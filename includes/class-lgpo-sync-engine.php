<?php
/**
 * Sync Engine
 *
 * Fetches all campaign members from Patreon API v2, matches by email
 * to existing WP users, and compares current roles to determine changes.
 * Supports manual review (fetch_and_compare + execute_approved) and
 * auto-apply mode for cron (run).
 *
 * Respects payment_source boundaries:
 *   - Skips users with payment_source=stripe and active paid role
 *   - Skips looth4 users always
 *   - Clears payment_source on downgrade to looth1
 *   - Sets payment_source=patreon on upgrade/verify
 *
 * @package LG_Patreon_Onboard
 */

defined( 'ABSPATH' ) || exit;

class LGPO_Sync_Engine {

    /** Patreon API v2 base URL. */
    private const API_BASE = 'https://www.patreon.com/api/oauth2/v2';

    /** Max members per API page. */
    private const PAGE_SIZE = 1000;

    /** Transient key for proposed changes. */
    private const CHANGES_TRANSIENT = 'lgpo_api_proposed_changes';

    /** Transient TTL: 1 hour. */
    private const CHANGES_TTL = HOUR_IN_SECONDS;

    /** Lock transient to prevent concurrent runs. */
    private const LOCK_KEY = 'lgpo_sync_running';
    private const LOCK_TTL = 300; // 5 minutes

    /* ------------------------------------------------------------------
     * Manual workflow: Phase A — Fetch and Compare
     * ----------------------------------------------------------------*/

    /**
     * Fetch all campaign members and compare with WP users.
     * Stores proposed changes in a transient for the review UI.
     *
     * @return array Summary stats, or [ 'error' => string ] on failure.
     */
    public static function fetch_and_compare(): array {
        $config = self::validate_config();
        if ( isset( $config['error'] ) ) {
            return $config;
        }

        $members = self::fetch_all_members( $config['token'], $config['campaign_id'] );
        if ( $members === null ) {
            return [ 'error' => 'Failed to fetch members from Patreon API. Check debug log.' ];
        }

        $tier_to_role = self::build_tier_lookup( $config['tier_map'] );

        $changes = [
            'updates'    => [],
            'skipped'    => [],
            'stats'      => [
                'total_fetched'  => count( $members ),
                'matched'        => 0,
                'unchanged'      => 0,
                'skipped_stripe' => 0,
                'skipped_looth4' => 0,
                'skipped_no_wp'  => 0,
            ],
        ];

        foreach ( $members as $member ) {
            self::compare_member( $member, $tier_to_role, $changes );
        }

        // Store for review UI
        set_transient( self::CHANGES_TRANSIENT, $changes, self::CHANGES_TTL );
        update_option( 'lgpo_last_fetch_time', time() );

        return $changes['stats'];
    }

    /* ------------------------------------------------------------------
     * Manual workflow: Phase B — Execute Approved Changes
     * ----------------------------------------------------------------*/

    /**
     * Execute a set of admin-approved changes.
     *
     * @param array $approved Array of change records (from the review UI).
     * @return array Results summary.
     */
    public static function execute_approved( array $approved ): array {
        self::start_batch();

        $results = [
            'applied'  => [],
            'errors'   => [],
        ];

        foreach ( $approved as $change ) {
            $result = self::apply_change( $change );
            if ( $result['success'] ) {
                $results['applied'][] = $result['message'];
            } else {
                $results['errors'][] = $result['message'];
            }
        }

        // Remove applied changes from the transient (keep the rest for continued review)
        $proposed = get_transient( self::CHANGES_TRANSIENT );
        if ( is_array( $proposed ) && ! empty( $proposed['updates'] ) ) {
            $applied_emails = array_map( fn( $c ) => strtolower( $c['email'] ?? '' ), $approved );
            $applied_set    = array_flip( $applied_emails );

            $proposed['updates'] = array_values( array_filter(
                $proposed['updates'],
                fn( $u ) => ! isset( $applied_set[ strtolower( $u['email'] ?? '' ) ] )
            ) );

            if ( empty( $proposed['updates'] ) && empty( $proposed['skipped'] ) ) {
                delete_transient( self::CHANGES_TRANSIENT );
            } else {
                set_transient( self::CHANGES_TRANSIENT, $proposed, self::CHANGES_TTL );
            }
        }

        // Store results and send admin email
        update_option( 'lgpo_last_sync_time', time() );
        update_option( 'lgpo_last_sync_results', $results );
        self::send_summary( $results, false );

        return $results;
    }

    /* ------------------------------------------------------------------
     * Auto-apply mode (for cron)
     * ----------------------------------------------------------------*/

    /**
     * Run a full fetch-and-apply cycle. Used by cron.
     * Applies all changes automatically (no review step).
     */
    public static function run(): void {
        // Prevent concurrent runs
        if ( get_transient( self::LOCK_KEY ) ) {
            error_log( 'LGPO Sync: Skipped — already running.' );
            return;
        }
        set_transient( self::LOCK_KEY, true, self::LOCK_TTL );

        error_log( 'LGPO Sync: Starting auto sync.' );

        $config = self::validate_config();
        if ( isset( $config['error'] ) ) {
            error_log( 'LGPO Sync: Aborted — ' . $config['error'] );
            delete_transient( self::LOCK_KEY );
            return;
        }

        $members = self::fetch_all_members( $config['token'], $config['campaign_id'] );
        if ( $members === null ) {
            error_log( 'LGPO Sync: Aborted — API fetch failed.' );
            delete_transient( self::LOCK_KEY );
            return;
        }

        error_log( sprintf( 'LGPO Sync: Fetched %d members from Patreon.', count( $members ) ) );

        $tier_to_role = self::build_tier_lookup( $config['tier_map'] );

        $changes = [
            'updates' => [],
            'skipped' => [],
            'stats'   => [
                'total_fetched'  => count( $members ),
                'matched'        => 0,
                'unchanged'      => 0,
                'skipped_stripe' => 0,
                'skipped_looth4' => 0,
                'skipped_no_wp'  => 0,
            ],
        ];

        foreach ( $members as $member ) {
            self::compare_member( $member, $tier_to_role, $changes );
        }

        // Auto-apply all updates
        self::start_batch();

        $results = [
            'applied' => [],
            'errors'  => [],
        ];

        foreach ( $changes['updates'] as $change ) {
            $result = self::apply_change( $change );
            if ( $result['success'] ) {
                $results['applied'][] = $result['message'];
            } else {
                $results['errors'][] = $result['message'];
            }
        }

        update_option( 'lgpo_last_sync_time', time() );
        update_option( 'lgpo_last_sync_results', $results );

        error_log( sprintf(
            'LGPO Sync: Complete — fetched: %d, matched: %d, applied: %d, unchanged: %d, errors: %d',
            $changes['stats']['total_fetched'],
            $changes['stats']['matched'],
            count( $results['applied'] ),
            $changes['stats']['unchanged'],
            count( $results['errors'] ),
        ) );

        self::send_summary( $results, true );
        delete_transient( self::LOCK_KEY );
    }

    /* ------------------------------------------------------------------
     * Patreon API
     * ----------------------------------------------------------------*/

    /**
     * Fetch all campaign members, handling pagination.
     *
     * @return array|null Array of normalized member records, or null on failure.
     */
    private static function fetch_all_members( string $token, string $campaign_id ): ?array {
        $members = [];
        $cursor  = null;
        $page    = 0;

        do {
            $page++;
            $url = self::build_members_url( $campaign_id, $cursor );

            error_log( "LGPO Sync: Fetching page {$page}..." );

            $response = wp_remote_get( $url, [
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent'    => 'LoothGroup-Sync/1.0',
                ],
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( 'LGPO Sync: API request failed — ' . $response->get_error_message() );
                return null;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                $body = wp_remote_retrieve_body( $response );
                error_log( "LGPO Sync: API returned HTTP {$code} — " . substr( $body, 0, 500 ) );
                return null;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! $body || ! isset( $body['data'] ) ) {
                error_log( 'LGPO Sync: API returned invalid JSON.' );
                return null;
            }

            // Index included resources (users, tiers)
            $included = self::index_included( $body['included'] ?? [] );

            // Normalize each member record
            foreach ( $body['data'] as $item ) {
                $normalized = self::normalize_member( $item, $included );
                if ( $normalized ) {
                    $members[] = $normalized;
                }
            }

            // Next page cursor
            $cursor = $body['meta']['pagination']['cursors']['next'] ?? null;

        } while ( $cursor !== null );

        return $members;
    }

    /**
     * Build the campaign members API URL.
     */
    private static function build_members_url( string $campaign_id, ?string $cursor ): string {
        $params = [
            'include'        => 'currently_entitled_tiers,user',
            'fields[member]' => 'patron_status,email,full_name',
            'fields[tier]'   => 'title,amount_cents',
            'fields[user]'   => 'email,full_name',
            'page[count]'    => self::PAGE_SIZE,
        ];

        if ( $cursor !== null ) {
            $params['page[cursor]'] = $cursor;
        }

        return self::API_BASE . '/campaigns/' . $campaign_id . '/members?' . http_build_query( $params );
    }

    /**
     * Index the "included" array from JSON:API response by type:id.
     */
    private static function index_included( array $included ): array {
        $index = [];
        foreach ( $included as $resource ) {
            $key = ( $resource['type'] ?? '' ) . ':' . ( $resource['id'] ?? '' );
            $index[ $key ] = $resource;
        }
        return $index;
    }

    /**
     * Normalize a raw member data item into a flat record.
     */
    private static function normalize_member( array $item, array $included ): ?array {
        $attrs = $item['attributes'] ?? [];
        $rels  = $item['relationships'] ?? [];

        // Get email: try member email first, then included user resource
        $email = $attrs['email'] ?? '';
        if ( ! $email ) {
            $user_data = $rels['user']['data'] ?? null;
            if ( $user_data ) {
                $user_resource = $included[ 'user:' . $user_data['id'] ] ?? null;
                $email = $user_resource['attributes']['email'] ?? '';
            }
        }

        if ( ! $email ) {
            return null; // Cannot match without email
        }

        // Extract entitled tier IDs
        $tier_ids  = [];
        $tier_data = $rels['currently_entitled_tiers']['data'] ?? [];
        foreach ( $tier_data as $tier_ref ) {
            $tier_ids[] = (string) $tier_ref['id'];
        }

        // Get Patreon user ID from relationship
        $patreon_user_id = '';
        $user_data = $rels['user']['data'] ?? null;
        if ( $user_data ) {
            $patreon_user_id = (string) $user_data['id'];
        }

        return [
            'email'            => strtolower( trim( $email ) ),
            'full_name'        => $attrs['full_name'] ?? '',
            'patron_status'    => $attrs['patron_status'] ?? null,
            'tier_ids'         => $tier_ids,
            'patreon_user_id'  => $patreon_user_id,
        ];
    }

    /* ------------------------------------------------------------------
     * Comparison logic
     * ----------------------------------------------------------------*/

    /**
     * Compare a single Patreon member against WP and record the result.
     */
    private static function compare_member( array $member, array $tier_to_role, array &$changes ): void {
        $email = $member['email'];

        // Find WP user by email
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            $changes['stats']['skipped_no_wp']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'No WP account',
            ];
            return;
        }

        $changes['stats']['matched']++;
        $user_id       = $user->ID;
        $current_roles = (array) $user->roles;

        // Skip looth4 always
        if ( in_array( 'looth4', $current_roles, true ) || in_array( 'administrator', $current_roles, true ) ) {
            $changes['stats']['skipped_looth4']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'Protected role (looth4/admin)',
            ];
            return;
        }

        // Skip active Stripe members (payment_source=stripe + paid role)
        $payment_source = get_user_meta( $user_id, 'payment_source', true );
        if (
            $payment_source === 'stripe'
            && ( in_array( 'looth2', $current_roles, true ) || in_array( 'looth3', $current_roles, true ) )
        ) {
            $changes['stats']['skipped_stripe']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'Active Stripe member',
            ];
            return;
        }

        // Determine target role
        $target_role = self::determine_role( $member, $tier_to_role );

        // Skip members whose tiers all map to looth4
        if ( $target_role === 'skip' ) {
            $changes['stats']['skipped_looth4']++;
            $changes['skipped'][] = [
                'email'  => $email,
                'reason' => 'Tier mapped to looth4 (bypass)',
            ];
            return;
        }

        // Get current looth role
        $current_role = 'looth1'; // default
        foreach ( [ 'looth3', 'looth2', 'looth1' ] as $r ) {
            if ( in_array( $r, $current_roles, true ) ) {
                $current_role = $r;
                break;
            }
        }

        // No change needed?
        if ( $target_role === $current_role ) {
            // Ensure payment_source is set if they're active
            if ( $target_role !== 'looth1' && $payment_source !== 'patreon' ) {
                $changes['updates'][] = [
                    'action'          => 'tag_only',
                    'user_id'         => $user_id,
                    'email'           => $email,
                    'current_role'    => $current_role,
                    'new_role'        => $target_role,
                    'tier_id'         => $member['tier_ids'][0] ?? '',
                    'patreon_user_id' => $member['patreon_user_id'] ?? '',
                    'reason'          => 'Set payment_source=patreon (role unchanged)',
                ];
            } else {
                $changes['stats']['unchanged']++;
            }
            return;
        }

        // Role change needed
        $action = ( $target_role === 'looth1' ) ? 'downgrade' : 'update';
        $reason = ( $action === 'downgrade' )
            ? 'Patron status: ' . ( $member['patron_status'] ?? 'none' )
            : 'Tier mapped to ' . $target_role;

        $changes['updates'][] = [
            'action'          => $action,
            'user_id'         => $user_id,
            'email'           => $email,
            'current_role'    => $current_role,
            'new_role'        => $target_role,
            'tier_id'         => $member['tier_ids'][0] ?? '',
            'patreon_user_id' => $member['patreon_user_id'] ?? '',
            'reason'          => $reason,
        ];
    }

    /**
     * Determine the target WP role for a Patreon member.
     */
    private static function determine_role( array $member, array $tier_to_role ): string {
        // Not an active patron → looth1
        if ( ( $member['patron_status'] ?? '' ) !== 'active_patron' ) {
            return 'looth1';
        }

        // Active but no entitled tiers → looth1
        if ( empty( $member['tier_ids'] ) ) {
            return 'looth1';
        }

        // Check if ALL entitled tiers map to looth4 → skip this member
        $non_looth4_tiers = array_filter( $member['tier_ids'], function ( $tid ) use ( $tier_to_role ) {
            return ( $tier_to_role[ $tid ] ?? null ) !== 'looth4';
        } );
        if ( empty( $non_looth4_tiers ) ) {
            return 'skip'; // All tiers are looth4-mapped
        }

        // Find the highest-value role among entitled tiers (ignoring looth4)
        $best_role = 'looth1';
        $role_rank = [ 'looth1' => 0, 'looth2' => 1, 'looth3' => 2 ];

        foreach ( $member['tier_ids'] as $tier_id ) {
            $role = $tier_to_role[ $tier_id ] ?? null;
            if ( $role && $role !== 'looth4' && ( $role_rank[ $role ] ?? 0 ) > ( $role_rank[ $best_role ] ?? 0 ) ) {
                $best_role = $role;
            }
        }

        return $best_role;
    }

    /* ------------------------------------------------------------------
     * Apply a single change
     * ----------------------------------------------------------------*/

    /**
     * Apply a single change record to a WP user.
     *
     * @return array [ 'success' => bool, 'message' => string ]
     */
    private static function apply_change( array $change ): array {
        $user_id = $change['user_id'] ?? 0;
        $email   = $change['email'] ?? '';

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [ 'success' => false, 'message' => "User {$email} (ID {$user_id}) not found." ];
        }

        // Double-check looth4 protection
        if ( in_array( 'looth4', (array) $user->roles, true ) ) {
            return [ 'success' => false, 'message' => "User {$email} has looth4 — skipped." ];
        }

        $new_role = $change['new_role'] ?? '';
        $action   = $change['action'] ?? 'update';
        $old_payment_source = get_user_meta( $user_id, 'payment_source', true );

        // Set Patreon user ID if available (links account for OAuth reuse)
        if ( ! empty( $change['patreon_user_id'] ) ) {
            update_user_meta( $user_id, 'lgpo_patreon_user_id', sanitize_text_field( $change['patreon_user_id'] ) );
        }

        if ( $action === 'tag_only' ) {
            // Just set payment_source, no role change
            update_user_meta( $user_id, 'payment_source', 'patreon' );
            self::log_change( $user_id, $email, $change['current_role'], $change['current_role'], $old_payment_source, 'patreon', 'tag_only' );
            return [ 'success' => true, 'message' => "{$email} — set payment_source=patreon (role unchanged)." ];
        }

        // Set the role
        $user->set_role( $new_role );

        if ( $new_role === 'looth1' ) {
            // Downgrade: clear payment_source
            delete_user_meta( $user_id, 'payment_source' );
            self::log_change( $user_id, $email, $change['current_role'], 'looth1', $old_payment_source, '', 'downgrade' );
            error_log( "LGPO Sync: Downgraded {$email} to looth1, cleared payment_source." );
            return [ 'success' => true, 'message' => "{$email} — downgraded to looth1." ];
        }

        // Upgrade or change: set payment_source=patreon
        update_user_meta( $user_id, 'payment_source', 'patreon' );

        // Update tier ID in usermeta
        if ( ! empty( $change['tier_id'] ) ) {
            update_user_meta( $user_id, 'lgpo_patreon_tier_id', sanitize_text_field( $change['tier_id'] ) );
        }

        self::log_change( $user_id, $email, $change['current_role'], $new_role, $old_payment_source, 'patreon', $action );
        error_log( "LGPO Sync: Updated {$email} to {$new_role}, set payment_source=patreon." );
        return [ 'success' => true, 'message' => "{$email} — {$change['current_role']} → {$new_role}." ];
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Validate that all required config is present.
     *
     * @return array Config values, or [ 'error' => string ] on failure.
     */
    private static function validate_config(): array {
        $token       = get_option( 'lgpo_creator_access_token', '' );
        $campaign_id = get_option( 'lgpo_campaign_id', '' );
        $tier_map    = get_option( 'lgpo_tier_map', [] );

        if ( ! $token ) {
            return [ 'error' => 'Creator Access Token not configured.' ];
        }
        if ( ! $campaign_id ) {
            return [ 'error' => 'Campaign ID not configured.' ];
        }
        if ( empty( $tier_map ) ) {
            return [ 'error' => 'Tier map is empty.' ];
        }

        return compact( 'token', 'campaign_id', 'tier_map' );
    }

    /**
     * Build a flat lookup from the lgpo_tier_map option.
     * Handles the format: [ tier_id => role_slug ]
     *
     * @return array Patreon Tier ID (string) → WP role (string).
     */
    private static function build_tier_lookup( $tier_map ): array {
        if ( ! is_array( $tier_map ) ) {
            return [];
        }

        $lookup = [];
        foreach ( $tier_map as $tier_id => $role ) {
            if ( is_array( $role ) ) {
                // Handle [ [ 'tier_id' => '...', 'role' => '...' ], ... ] format
                $lookup[ (string) ( $role['tier_id'] ?? $tier_id ) ] = (string) ( $role['role'] ?? 'looth1' );
            } else {
                $lookup[ (string) $tier_id ] = (string) $role;
            }
        }

        return $lookup;
    }

    /**
     * Get the currently stored proposed changes (for the review UI).
     *
     * @return array|null Changes array or null if none/expired.
     */
    public static function get_proposed_changes(): ?array {
        $changes = get_transient( self::CHANGES_TRANSIENT );
        return is_array( $changes ) ? $changes : null;
    }

    /**
     * Clear stored proposed changes.
     */
    public static function clear_proposed_changes(): void {
        delete_transient( self::CHANGES_TRANSIENT );
    }

    /* ------------------------------------------------------------------
     * Admin email summary
     * ----------------------------------------------------------------*/

    /**
     * Email the admin a sync summary.
     */
    private static function send_summary( array $results, bool $is_auto ): void {
        $admin_email = get_option( 'admin_email' );
        if ( ! $admin_email ) {
            return;
        }

        $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $mode      = $is_auto ? 'Auto' : 'Manual';
        $subject   = sprintf( '[%s] Patreon Sync Report (%s)', $site_name, $mode );

        $lines = [
            "Patreon Member Sync Report ({$mode})",
            '======================================',
            '',
            sprintf( 'Applied:  %d', count( $results['applied'] ) ),
            sprintf( 'Errors:   %d', count( $results['errors'] ) ),
        ];

        if ( ! empty( $results['applied'] ) ) {
            $lines[] = '';
            $lines[] = 'Applied:';
            foreach ( $results['applied'] as $msg ) {
                $lines[] = '  - ' . $msg;
            }
        }

        if ( ! empty( $results['errors'] ) ) {
            $lines[] = '';
            $lines[] = 'Errors:';
            foreach ( $results['errors'] as $msg ) {
                $lines[] = '  - ' . $msg;
            }
        }

        wp_mail( $admin_email, $subject, implode( "\n", $lines ) );
    }

    /* ------------------------------------------------------------------
     * Change Log — rolling 3-day history with revert support
     * ----------------------------------------------------------------*/

    /** Option key for the change log. */
    private const LOG_KEY = 'lgpo_sync_changelog';

    /** How long to keep log entries (3 days in seconds). */
    private const LOG_TTL = 259200;

    /**
     * Record a single change to the rolling log.
     */
    private static function log_change( int $user_id, string $email, string $old_role, string $new_role, string $old_ps, string $new_ps, string $action ): void {
        $log   = get_option( self::LOG_KEY, [] );
        $log[] = [
            'user_id'            => $user_id,
            'email'              => $email,
            'old_role'           => $old_role,
            'new_role'           => $new_role,
            'old_payment_source' => $old_ps,
            'new_payment_source' => $new_ps,
            'action'             => $action,
            'batch'              => get_option( 'lgpo_sync_batch_id', '' ),
            'timestamp'          => time(),
        ];

        // Prune entries older than 3 days
        $cutoff = time() - self::LOG_TTL;
        $log    = array_filter( $log, fn( $entry ) => $entry['timestamp'] >= $cutoff );

        update_option( self::LOG_KEY, array_values( $log ), false );
    }

    /**
     * Stamp a new batch ID before executing changes.
     * Call this at the start of execute_approved() and run().
     */
    private static function start_batch(): string {
        $batch_id = 'batch_' . gmdate( 'Ymd_His' ) . '_' . wp_rand( 1000, 9999 );
        update_option( 'lgpo_sync_batch_id', $batch_id, false );
        return $batch_id;
    }

    /**
     * Get the full change log (pruned to 3 days).
     *
     * @return array
     */
    public static function get_changelog(): array {
        $log    = get_option( self::LOG_KEY, [] );
        $cutoff = time() - self::LOG_TTL;
        return array_filter( $log, fn( $entry ) => $entry['timestamp'] >= $cutoff );
    }

    /**
     * Get a list of distinct batches in the log.
     *
     * @return array [ [ 'batch' => string, 'timestamp' => int, 'count' => int ], ... ]
     */
    public static function get_batches(): array {
        $log     = self::get_changelog();
        $batches = [];

        foreach ( $log as $entry ) {
            $b = $entry['batch'];
            if ( ! isset( $batches[ $b ] ) ) {
                $batches[ $b ] = [ 'batch' => $b, 'timestamp' => $entry['timestamp'], 'count' => 0 ];
            }
            $batches[ $b ]['count']++;
        }

        // Sort newest first
        usort( $batches, fn( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
        return $batches;
    }

    /**
     * Revert all changes from a specific batch.
     *
     * @return array [ 'reverted' => int, 'errors' => string[] ]
     */
    public static function revert_batch( string $batch_id ): array {
        $log      = self::get_changelog();
        $entries  = array_filter( $log, fn( $e ) => $e['batch'] === $batch_id );
        $reverted = 0;
        $errors   = [];

        foreach ( $entries as $entry ) {
            $user = get_userdata( $entry['user_id'] );
            if ( ! $user ) {
                $errors[] = "User {$entry['email']} (ID {$entry['user_id']}) not found.";
                continue;
            }

            // Skip looth4 protection
            if ( in_array( 'looth4', (array) $user->roles, true ) ) {
                $errors[] = "User {$entry['email']} is looth4 — skipped.";
                continue;
            }

            // Restore old role (only if it actually changed)
            if ( $entry['old_role'] !== $entry['new_role'] ) {
                $user->set_role( $entry['old_role'] );
            }

            // Restore old payment_source
            if ( $entry['old_payment_source'] ) {
                update_user_meta( $entry['user_id'], 'payment_source', $entry['old_payment_source'] );
            } else {
                delete_user_meta( $entry['user_id'], 'payment_source' );
            }

            $reverted++;
        }

        // Remove reverted entries from the log
        $remaining = array_filter( $log, fn( $e ) => $e['batch'] !== $batch_id );
        update_option( self::LOG_KEY, array_values( $remaining ), false );

        error_log( "LGPO Sync: Reverted batch {$batch_id} — {$reverted} changes restored." );
        return [ 'reverted' => $reverted, 'errors' => $errors ];
    }
}
