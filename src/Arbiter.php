<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Sole writer of wp_capabilities for looth1..4 tiers.
 *
 * Reads all source rows for a WP user, computes the winning tier
 * (highest across active sources), and writes wp_capabilities
 * preserving every non-tier role (administrator, bbp_participant, etc.).
 *
 * looth4 users are protected: never modified.
 */
final class Arbiter
{
    private const TIER_ROLES = [ 'looth1', 'looth2', 'looth3', 'looth4' ];

    public static function sync(int $wpUserId): array
    {
        $user = get_user_by( 'id', $wpUserId );
        if ( ! $user ) {
            return [ 'ok' => false, 'reason' => 'no such WP user' ];
        }

        // Protected — never touch.
        if ( in_array( 'looth4', $user->roles, true ) ) {
            return [ 'ok' => true, 'reason' => 'looth4 protected, skipped' ];
        }

        $sources = RoleSourceWriter::readAllForUser( $wpUserId );
        $winning = self::computeWinningTier( $sources );

        // Remove existing tier roles that aren't the winner.
        foreach ( self::TIER_ROLES as $role ) {
            if ( in_array( $role, $user->roles, true ) && $role !== $winning ) {
                $user->remove_role( $role );
            }
        }

        // Add the winning role if not already present.
        if ( $winning !== null && ! in_array( $winning, $user->roles, true ) ) {
            $user->add_role( $winning );
        }

        return [ 'ok' => true, 'winning_tier' => $winning, 'sources' => $sources ];
    }

    /**
     * Highest of looth1..4 across sources reporting non-null tiers.
     * If we have any rows but none report a tier, fall back to looth1
     * (lapsed). If no rows at all, return null (don't touch the user).
     */
    private static function computeWinningTier(array $sources): ?string
    {
        if ( $sources === [] ) {
            return null;
        }
        $best = null;
        foreach ( $sources as $tier ) {
            if ( $tier === null ) {
                continue;
            }
            if ( ! in_array( $tier, self::TIER_ROLES, true ) ) {
                continue;
            }
            if ( $best === null || strcmp( $tier, $best ) > 0 ) {
                $best = $tier;
            }
        }
        return $best ?? 'looth1';
    }
}
