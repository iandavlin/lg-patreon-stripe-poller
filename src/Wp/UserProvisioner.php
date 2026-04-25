<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\Db;
use RuntimeException;

/**
 * Find or create a WP user for an lg_membership customer.
 * Always inserts wp_user_bridge on success.
 *
 * Lookup priority: existing bridge row > WP user by email > create new.
 */
final class UserProvisioner
{
    public static function findOrProvision(int $customerId, string $email, ?string $name): int
    {
        // Already bridged?
        $stmt = Db::pdo()->prepare(
            'SELECT wp_user_id FROM wp_user_bridge WHERE customer_id = ? LIMIT 1'
        );
        $stmt->execute( [ $customerId ] );
        $bridged = $stmt->fetchColumn();
        if ( $bridged !== false ) {
            return (int) $bridged;
        }

        // WP user exists by email? Bridge and return.
        $existing = get_user_by( 'email', $email );
        if ( $existing ) {
            self::writeBridge( $customerId, (int) $existing->ID );
            return (int) $existing->ID;
        }

        // Create a fresh WP user. role=looth1; arbiter will upgrade if entitled.
        $username = self::generateUsername( $email );
        $userId   = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password( 24, true, true ),
            'display_name' => $name ?: $username,
            'first_name'   => self::firstName( $name ),
            'last_name'    => self::lastName( $name ),
            'role'         => 'looth1',
        ] );

        if ( is_wp_error( $userId ) ) {
            throw new RuntimeException( 'wp_insert_user failed: ' . $userId->get_error_message() );
        }

        self::writeBridge( $customerId, (int) $userId );
        self::sendWelcomeEmail( (int) $userId );

        return (int) $userId;
    }

    private static function writeBridge(int $customerId, int $wpUserId): void
    {
        Db::pdo()->prepare(
            'INSERT INTO wp_user_bridge (customer_id, wp_user_id, synced_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE wp_user_id = VALUES(wp_user_id), synced_at = NOW()'
        )->execute( [ $customerId, $wpUserId ] );
    }

    private static function generateUsername(string $email): string
    {
        $base = sanitize_user( strstr( $email, '@', true ) ?: 'member', true );
        if ( ! $base ) {
            $base = 'member';
        }
        $candidate = $base;
        $n         = 1;
        while ( username_exists( $candidate ) ) {
            $candidate = $base . '_' . ++$n;
            if ( $n > 100 ) {
                $candidate = $base . '_' . wp_generate_password( 6, false );
                break;
            }
        }
        return $candidate;
    }

    private static function firstName(?string $full): string
    {
        if ( ! $full ) return '';
        $parts = preg_split( '/\s+/', trim( $full ), 2 );
        return $parts[0] ?? '';
    }

    private static function lastName(?string $full): string
    {
        if ( ! $full ) return '';
        $parts = preg_split( '/\s+/', trim( $full ), 2 );
        return $parts[1] ?? '';
    }

    private static function sendWelcomeEmail(int $userId): void
    {
        $user = get_user_by( 'id', $userId );
        if ( ! $user ) return;

        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            error_log( 'LGMS: get_password_reset_key failed — ' . $key->get_error_message() );
            return;
        }

        $resetUrl = network_site_url(
            'wp-login.php?action=rp&key=' . rawurlencode( $key )
                . '&login=' . rawurlencode( $user->user_login ),
            'login'
        );

        $siteName = (string) get_bloginfo( 'name' );
        $subject  = "Welcome to {$siteName}";
        $body     = sprintf(
            "Thanks for joining %s!\n\nYour account has been created. Set your password here:\n\n%s\n\nThis link expires in 24 hours.",
            $siteName,
            $resetUrl
        );

        wp_mail( $user->user_email, $subject, $body );
    }
}
