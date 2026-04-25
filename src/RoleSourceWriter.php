<?php

declare(strict_types=1);

namespace LGMS;

use PDO;

/**
 * Reads/writes per-source role opinions in lg_role_sources.
 *
 * Each row says "source X thinks user Y has tier Z." Multiple sources
 * (stripe, patreon, manual) coexist; the Arbiter picks the winner.
 */
final class RoleSourceWriter
{
    public static function report(int $wpUserId, string $source, ?string $tier): void
    {
        Db::pdo()->prepare(
            'INSERT INTO lg_role_sources (wp_user_id, source, tier)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE tier = VALUES(tier)'
        )->execute( [ $wpUserId, $source, $tier ] );
    }

    /** @return array<string, ?string> source => tier */
    public static function readAllForUser(int $wpUserId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT source, tier FROM lg_role_sources WHERE wp_user_id = ?'
        );
        $stmt->execute( [ $wpUserId ] );
        $out = [];
        foreach ( $stmt->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
            $out[ (string) $row['source'] ] = $row['tier'] !== null ? (string) $row['tier'] : null;
        }
        return $out;
    }
}
