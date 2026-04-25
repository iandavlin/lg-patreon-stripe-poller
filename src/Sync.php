<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Wp\UserProvisioner;
use Throwable;

/**
 * Per-customer sync orchestrator. Idempotent.
 *
 *   1. Find/provision WP user (writes wp_user_bridge)
 *   2. Compute current active tier from entitlements
 *   3. Report (wp_user_id, 'stripe', tier) to lg_role_sources
 *   4. Run arbiter to write wp_capabilities
 *
 * Called from:
 *   - Tick::run() pass 2 (cron)
 *   - REST endpoint /sync-customer (Slim post-checkout)
 *   - REST endpoint /run-now (admin)
 */
final class Sync
{
    /** @return array{ok:bool, message?:string, wp_user_id?:int, tier?:?string} */
    public static function customer(int $customerId): array
    {
        $customer = CustomerRepo::findById( $customerId );
        if ( ! $customer ) {
            return [ 'ok' => false, 'message' => "customer {$customerId} not found" ];
        }

        try {
            $wpUserId = UserProvisioner::findOrProvision(
                $customerId,
                (string) $customer['email'],
                $customer['name'] !== null ? (string) $customer['name'] : null,
            );
        } catch ( Throwable $e ) {
            return [ 'ok' => false, 'message' => 'provision failed: ' . $e->getMessage() ];
        }

        $tier = EntitlementRepo::activeTier( $customerId );
        RoleSourceWriter::report( $wpUserId, 'stripe', $tier );
        $arb = Arbiter::sync( $wpUserId );

        return [
            'ok'           => true,
            'wp_user_id'   => $wpUserId,
            'tier'         => $tier,
            'arbiter'      => $arb,
        ];
    }

    /** Sweep every active customer. Called from cron. */
    public static function all(): array
    {
        $stmt    = Db::pdo()->query( 'SELECT id FROM customers WHERE deleted_at IS NULL' );
        $results = [];
        foreach ( $stmt->fetchAll( \PDO::FETCH_COLUMN ) as $cid ) {
            $results[ (int) $cid ] = self::customer( (int) $cid );
        }
        return $results;
    }
}
