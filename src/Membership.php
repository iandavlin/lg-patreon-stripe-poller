<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Source-agnostic membership status for the Manage Subscription page.
 *
 * Returns a normalized struct describing the user's current membership
 * regardless of whether it lives in Stripe (subscriptions table), Patreon
 * (lg_patreon_members), or a manual admin grant (lg_role_sources).
 *
 * Priority: Stripe > Patreon > manual. The first source with a non-"none"
 * status wins. Used by the [lg_manage_subscription] shortcode to render
 * a unified status panel.
 */
final class Membership
{
    /**
     * @return array{
     *   source: string,            'stripe'|'patreon'|'manual'|'none'
     *   source_label: string,      Human-readable, e.g. "Stripe"
     *   status: string,            'active'|'past_due'|'canceled'|'none'
     *   status_label: string,      Human-readable, e.g. "Past due"
     *   tier: ?string,             Tier label or null
     *   amount_cents: ?int,        Recurring charge amount in cents (Patreon only today)
     *   next_charge_at: ?string,   MySQL DATETIME UTC or null
     *   manage_url: ?string,       External URL to manage the membership
     *   raw: array                 Underlying source-specific row for debugging
     * }
     */
    public static function statusFor( int $wpUserId ): array
    {
        if ( $wpUserId <= 0 ) {
            return self::none();
        }

        $stripe = self::stripeStatus( $wpUserId );
        if ( $stripe['status'] !== 'none' ) {
            return $stripe;
        }

        $patreon = self::patreonStatus( $wpUserId );
        if ( $patreon['status'] !== 'none' ) {
            return $patreon;
        }

        $manual = self::manualStatus( $wpUserId );
        if ( $manual['status'] !== 'none' ) {
            return $manual;
        }

        return self::none();
    }

    private static function stripeStatus( int $wpUserId ): array
    {
        try {
            $user = get_userdata( $wpUserId );
            if ( ! $user ) {
                return self::none();
            }
            $customer = \LGMS\Repos\CustomerRepo::findByEmail( (string) $user->user_email );
            if ( $customer === null ) {
                return self::none();
            }
            $stmt = Db::pdo()->prepare(
                "SELECT stripe_price_id, status, current_period_end
                 FROM subscriptions
                 WHERE customer_id = ? AND status IN ('active','trialing','past_due','canceled')
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute( [ (int) $customer['id'] ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            if ( ! $row ) {
                return self::none();
            }
            $rawStatus = (string) $row['status'];
            return [
                'source'         => 'stripe',
                'source_label'   => 'Stripe',
                'status'         => self::mapStripeStatus( $rawStatus ),
                'status_label'   => self::statusLabel( self::mapStripeStatus( $rawStatus ) ),
                'tier'           => null, // Resolved in shortcode via tierLabelForPrice()
                'amount_cents'   => null,
                'next_charge_at' => $row['current_period_end'] ?: null,
                'manage_url'     => null, // Stripe portal handled by existing buttons
                'raw'            => $row,
            ];
        } catch ( \Throwable $e ) {
            error_log( 'LGMS Membership::stripeStatus: ' . $e->getMessage() );
            return self::none();
        }
    }

    private static function patreonStatus( int $wpUserId ): array
    {
        try {
            $stmt = Db::pdo()->prepare(
                'SELECT patron_status, last_charge_status, last_charge_date, next_charge_date,
                        will_pay_amount_cents, currently_entitled_amount_cents, tier_label,
                        patreon_user_id, synced_at
                 FROM lg_patreon_members WHERE wp_user_id = ?'
            );
            $stmt->execute( [ $wpUserId ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            if ( ! $row ) {
                return self::none();
            }
            $status = self::mapPatreonStatus( $row );
            if ( $status === 'none' ) {
                return self::none();
            }
            return [
                'source'         => 'patreon',
                'source_label'   => 'Patreon',
                'status'         => $status,
                'status_label'   => self::statusLabel( $status ),
                'tier'           => $row['tier_label'] ?: null,
                'amount_cents'   => $row['will_pay_amount_cents'] !== null
                                        ? (int) $row['will_pay_amount_cents']
                                        : ( $row['currently_entitled_amount_cents'] !== null
                                            ? (int) $row['currently_entitled_amount_cents']
                                            : null ),
                'next_charge_at' => $row['next_charge_date'] ?: null,
                'manage_url'     => self::patreonManageUrl(),
                'raw'            => $row,
            ];
        } catch ( \Throwable $e ) {
            error_log( 'LGMS Membership::patreonStatus: ' . $e->getMessage() );
            return self::none();
        }
    }

    private static function manualStatus( int $wpUserId ): array
    {
        try {
            $stmt = Db::pdo()->prepare(
                'SELECT tier, updated_at FROM lg_role_sources
                 WHERE wp_user_id = ? AND source = ? LIMIT 1'
            );
            $stmt->execute( [ $wpUserId, 'manual_admin' ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            if ( ! $row ) {
                return self::none();
            }
            return [
                'source'         => 'manual',
                'source_label'   => 'Admin grant',
                'status'         => 'active',
                'status_label'   => self::statusLabel( 'active' ),
                'tier'           => $row['tier'] ?: null,
                'amount_cents'   => null,
                'next_charge_at' => null,
                'manage_url'     => null,
                'raw'            => $row,
            ];
        } catch ( \Throwable $e ) {
            error_log( 'LGMS Membership::manualStatus: ' . $e->getMessage() );
            return self::none();
        }
    }

    private static function mapStripeStatus( string $raw ): string
    {
        return match ( $raw ) {
            'active', 'trialing' => 'active',
            'past_due', 'unpaid' => 'past_due',
            'canceled', 'incomplete_expired' => 'canceled',
            default              => 'none',
        };
    }

    private static function mapPatreonStatus( array $row ): string
    {
        $patron = (string) ( $row['patron_status'] ?? '' );
        $charge = (string) ( $row['last_charge_status'] ?? '' );

        if ( $patron === 'declined_patron' || $charge === 'Declined' ) {
            return 'past_due';
        }
        if ( $patron === 'active_patron' ) {
            return 'active';
        }
        if ( $patron === 'former_patron' ) {
            return 'canceled';
        }
        return 'none';
    }

    private static function statusLabel( string $status ): string
    {
        return match ( $status ) {
            'active'   => 'Active',
            'past_due' => 'Past due',
            'canceled' => 'Canceled',
            default    => 'No active membership',
        };
    }

    private static function patreonManageUrl(): ?string
    {
        $url = trim( (string) get_option( 'lgpo_patreon_link', '' ) );
        if ( $url === '' ) {
            return 'https://www.patreon.com/loothgroup/membership';
        }
        return $url;
    }

    private static function none(): array
    {
        return [
            'source'         => 'none',
            'source_label'   => '',
            'status'         => 'none',
            'status_label'   => self::statusLabel( 'none' ),
            'tier'           => null,
            'amount_cents'   => null,
            'next_charge_at' => null,
            'manage_url'     => null,
            'raw'            => [],
        ];
    }
}
