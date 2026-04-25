<?php

declare(strict_types=1);

namespace LGMS\Repos;

use LGMS\Db;
use LGMS\Uuid;
use PDO;

final class EntitlementRepo
{
    public const KIND_MEMBERSHIP_TIER = 'membership_tier';

    public const SOURCE_SUBSCRIPTION = 'subscription';
    public const SOURCE_ORDER        = 'order';

    public static function activeForCustomer(int $customerId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM entitlements
             WHERE customer_id = ?
               AND revoked_at IS NULL
               AND starts_at <= NOW()
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY id DESC'
        );
        $stmt->execute( [ $customerId ] );
        return $stmt->fetchAll( PDO::FETCH_ASSOC );
    }

    /**
     * Grant a membership tier from a subscription. Idempotent: if an active
     * entitlement already exists for the same subscription with the same tier,
     * no rows are written. Tier changes revoke the prior row and insert a new
     * one so the audit trail stays clean.
     */
    public static function grantMembershipFromSubscription(
        int $customerId,
        string $tierRef,
        int $subscriptionId,
    ): void {
        $pdo = Db::pdo();

        // Already-active entitlement for the same source?
        $stmt = $pdo->prepare(
            'SELECT id, ref FROM entitlements
             WHERE source_type = ? AND source_id = ? AND revoked_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute( [ self::SOURCE_SUBSCRIPTION, $subscriptionId ] );
        $existing = $stmt->fetch( PDO::FETCH_ASSOC );

        if ( $existing && (string) $existing['ref'] === $tierRef ) {
            // Same source, same tier — nothing to do.
            return;
        }

        // Tier changed (or first time): revoke any prior, insert fresh.
        self::revokeBySource( self::SOURCE_SUBSCRIPTION, $subscriptionId );
        $pdo->prepare(
            'INSERT INTO entitlements
                (uuid, customer_id, kind, ref, source_type, source_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute( [
            Uuid::v4(),
            $customerId,
            self::KIND_MEMBERSHIP_TIER,
            $tierRef,
            self::SOURCE_SUBSCRIPTION,
            $subscriptionId,
        ] );
    }

    public static function revokeBySource(string $sourceType, int $sourceId): void
    {
        Db::pdo()->prepare(
            'UPDATE entitlements SET revoked_at = NOW()
             WHERE source_type = ? AND source_id = ? AND revoked_at IS NULL'
        )->execute( [ $sourceType, $sourceId ] );
    }

    /** Currently active membership tier ref for a customer, or null. */
    public static function activeTier(int $customerId): ?string
    {
        $rows  = self::activeForCustomer( $customerId );
        $tiers = array_values( array_filter(
            $rows,
            static fn (array $r): bool => $r['kind'] === self::KIND_MEMBERSHIP_TIER,
        ) );
        if ( $tiers === [] ) {
            return null;
        }
        usort( $tiers, static fn (array $a, array $b): int => strcmp( $b['ref'], $a['ref'] ) );
        return (string) $tiers[0]['ref'];
    }
}
