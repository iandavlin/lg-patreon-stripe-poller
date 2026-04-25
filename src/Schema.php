<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Plugin's own tables, kept in the lg_membership database.
 * Idempotent — safe to run on every activation.
 */
final class Schema
{
    public static function apply(): void
    {
        $pdo = Db::pdo();

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS lg_role_sources (
                wp_user_id  BIGINT UNSIGNED NOT NULL,
                source      VARCHAR(32)     NOT NULL,
                tier        VARCHAR(32)     NULL,
                updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (wp_user_id, source),
                KEY idx_source (source)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS lg_event_cursor (
                source       VARCHAR(32) PRIMARY KEY,
                cursor_id    VARCHAR(64) NULL,
                last_polled  DATETIME    NULL,
                last_status  VARCHAR(32) NULL,
                last_error   TEXT        NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }
}
