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
            CREATE TABLE IF NOT EXISTS lg_patreon_members (
                wp_user_id                      BIGINT UNSIGNED NOT NULL,
                patreon_user_id                 VARCHAR(64)     NULL,
                email                           VARCHAR(255)    NULL,
                full_name                       VARCHAR(255)    NULL,
                patron_status                   VARCHAR(32)     NULL,
                last_charge_status              VARCHAR(32)     NULL,
                last_charge_date                DATETIME        NULL,
                next_charge_date                DATETIME        NULL,
                will_pay_amount_cents           INT             NULL,
                currently_entitled_amount_cents INT             NULL,
                tier_label                      VARCHAR(255)    NULL,
                synced_at                       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (wp_user_id),
                KEY idx_patreon_user_id (patreon_user_id),
                KEY idx_patron_status   (patron_status)
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

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS lg_processed_events (
                event_id      VARCHAR(64) PRIMARY KEY,
                first_seen_at DATETIME    NOT NULL,
                last_seen_at  DATETIME    NOT NULL,
                dup_count     INT         NOT NULL DEFAULT 0,
                KEY idx_dup_count (dup_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        // QA feedback log written from /test-checklist/. Testers (logged-in
        // or password-only) submit per-item bugs / notes / questions; admins
        // triage by flipping status (open → fixed | wontfix).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS lg_test_feedback (
                id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id      VARCHAR(64)     NOT NULL,
                tester_name  VARCHAR(128)    NOT NULL,
                severity     VARCHAR(16)     NOT NULL,
                status       VARCHAR(16)     NOT NULL DEFAULT 'open',
                body         TEXT            NOT NULL,
                user_agent   VARCHAR(255)    NULL,
                created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_status  (status),
                KEY idx_item    (item_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }
}
