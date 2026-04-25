<?php

declare(strict_types=1);

namespace LGMS;

use PDO;
use RuntimeException;

/**
 * PDO accessor for the lg_membership database.
 *
 * Connection params come from wp_options:
 *   lgms_db_host, lgms_db_port, lgms_db_name, lgms_db_user, lgms_db_pass
 *
 * Defaults match the standard local install. Settings page lets admins override.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if ( self::$pdo instanceof PDO ) {
            return self::$pdo;
        }

        $host = (string) get_option( 'lgms_db_host', '127.0.0.1' );
        $port = (string) get_option( 'lgms_db_port', '3306' );
        $name = (string) get_option( 'lgms_db_name', 'lg_membership' );
        $user = (string) get_option( 'lgms_db_user', 'lg_membership' );
        $pass = (string) get_option( 'lgms_db_pass', '' );

        if ( $user === '' ) {
            throw new RuntimeException( 'LGMS: DB credentials not configured. Visit Settings → LG Member Sync.' );
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        self::$pdo = new PDO( $dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }
}
