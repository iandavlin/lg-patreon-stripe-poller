<?php

declare(strict_types=1);

namespace LGMS\Repos;

use LGMS\Db;
use PDO;

/**
 * Read+void access to the shared gift_codes table. The Slim billing app
 * owns insert and redeem; this repo only voids on refund and reads for
 * audit. Mirrors the Slim-side repository's voidByStripeSessionId.
 */
final class GiftCodeRepo
{
    /**
     * Void all unredeemed gift codes from a given Checkout session and
     * return the IDs that were voided plus IDs of codes already redeemed
     * (which need admin review rather than auto-revocation).
     *
     * @return array{voided:int[], already_redeemed:int[]}
     */
    public static function voidByStripeSessionId(string $stripeSessionId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare(
            'SELECT id, redeemed_at FROM gift_codes
             WHERE stripe_session_id = ? AND voided_at IS NULL'
        );
        $stmt->execute( [ $stripeSessionId ] );
        $rows = $stmt->fetchAll( PDO::FETCH_ASSOC );

        $voided          = [];
        $alreadyRedeemed = [];
        foreach ( $rows as $row ) {
            $id = (int) $row['id'];
            if ( $row['redeemed_at'] !== null ) {
                $alreadyRedeemed[] = $id;
                continue;
            }
            $voided[] = $id;
        }

        if ( $voided !== [] ) {
            $in   = implode( ', ', array_fill( 0, count( $voided ), '?' ) );
            $stmt = $pdo->prepare( "UPDATE gift_codes SET voided_at = NOW() WHERE id IN ({$in})" );
            $stmt->execute( $voided );
        }

        return [ 'voided' => $voided, 'already_redeemed' => $alreadyRedeemed ];
    }
}
