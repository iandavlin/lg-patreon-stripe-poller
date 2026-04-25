<?php

declare(strict_types=1);

namespace LGMS\Repos;

use LGMS\Db;
use LGMS\Uuid;
use PDO;

/**
 * CRUD on lg_membership.customers. Mirrors Slim's PdoCustomerRepository,
 * but returns associative arrays (no DTO ceremony — plugin is small).
 */
final class CustomerRepo
{
    public static function findById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute( [ $id ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        return $row !== false ? $row : null;
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute( [ $email ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        return $row !== false ? $row : null;
    }

    public static function findByStripeCustomerId(string $sid): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM customers WHERE stripe_customer_id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute( [ $sid ] );
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        return $row !== false ? $row : null;
    }

    /**
     * Find by Stripe customer id, else by email; create if neither matches.
     * If found by email and stripe_customer_id is NULL, upgrade it.
     */
    public static function findOrCreate(
        string $email,
        ?string $stripeCustomerId,
        ?string $name,
        ?string $country,
    ): array {
        if ( $stripeCustomerId ) {
            $found = self::findByStripeCustomerId( $stripeCustomerId );
            if ( $found ) {
                return $found;
            }
        }
        $byEmail = self::findByEmail( $email );
        if ( $byEmail ) {
            if ( $stripeCustomerId && empty( $byEmail['stripe_customer_id'] ) ) {
                Db::pdo()->prepare(
                    'UPDATE customers SET stripe_customer_id = ? WHERE id = ?'
                )->execute( [ $stripeCustomerId, $byEmail['id'] ] );
                $byEmail['stripe_customer_id'] = $stripeCustomerId;
            }
            return $byEmail;
        }

        $uuid = Uuid::v4();
        Db::pdo()->prepare(
            'INSERT INTO customers (uuid, stripe_customer_id, email, name, country)
             VALUES (?, ?, ?, ?, ?)'
        )->execute( [ $uuid, $stripeCustomerId, $email, $name, $country ] );
        $id = (int) Db::pdo()->lastInsertId();
        return self::findById( $id ) ?? [];
    }
}
