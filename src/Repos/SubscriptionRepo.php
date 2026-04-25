<?php

declare(strict_types=1);

namespace LGMS\Repos;

use LGMS\Db;
use PDO;

final class SubscriptionRepo
{
    public static function findByStripeId(string $sid): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM subscriptions WHERE stripe_subscription_id = ? LIMIT 1'
        );
        $stmt->execute( [ $sid ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        return $row !== false ? $row : null;
    }

    /** Returns the upserted row. */
    public static function upsert(
        int $customerId,
        string $stripeSubscriptionId,
        string $stripePriceId,
        string $status,
        bool $cancelAtPeriodEnd,
        ?int $currentPeriodStart,
        ?int $currentPeriodEnd,
        ?int $canceledAt,
    ): array {
        Db::pdo()->prepare(
            'INSERT INTO subscriptions
                (customer_id, stripe_subscription_id, stripe_price_id, status,
                 cancel_at_period_end, current_period_start, current_period_end, canceled_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                stripe_price_id      = VALUES(stripe_price_id),
                status               = VALUES(status),
                cancel_at_period_end = VALUES(cancel_at_period_end),
                current_period_start = VALUES(current_period_start),
                current_period_end   = VALUES(current_period_end),
                canceled_at          = VALUES(canceled_at)'
        )->execute( [
            $customerId,
            $stripeSubscriptionId,
            $stripePriceId,
            $status,
            $cancelAtPeriodEnd ? 1 : 0,
            self::tsToDate( $currentPeriodStart ),
            self::tsToDate( $currentPeriodEnd ),
            self::tsToDate( $canceledAt ),
        ] );
        return self::findByStripeId( $stripeSubscriptionId ) ?? [];
    }

    private static function tsToDate(?int $ts): ?string
    {
        return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
    }
}
