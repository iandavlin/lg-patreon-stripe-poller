<?php

declare(strict_types=1);

namespace LGMS\Repos;

use LGMS\Db;

final class ProductRepo
{
    /** Unit amount in cents for a Stripe price ID, or null if not found. */
    public static function amountCentsForPrice(string $stripePriceId): ?int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT unit_amount_cents FROM prices WHERE stripe_price_id = ? AND active = 1 LIMIT 1'
        );
        $stmt->execute( [ $stripePriceId ] );
        $val = $stmt->fetchColumn();
        return ( $val !== false && $val !== null ) ? (int) $val : null;
    }

    /** Tier ref (e.g. 'looth2') for a Stripe price ID, or null if unmapped. */
    public static function tierForPrice(string $stripePriceId): ?string
    {
        $stmt = Db::pdo()->prepare(
            "SELECT p.ref
             FROM prices pr
             JOIN products p ON p.id = pr.product_id
             WHERE pr.stripe_price_id = ?
               AND p.kind = 'membership'
               AND p.active = 1
             LIMIT 1"
        );
        $stmt->execute( [ $stripePriceId ] );
        $ref = $stmt->fetchColumn();
        return ( $ref !== false && $ref !== null ) ? (string) $ref : null;
    }
}
