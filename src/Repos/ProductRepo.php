<?php

declare(strict_types=1);

namespace LGMS\Repos;

use LGMS\Db;

final class ProductRepo
{
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
