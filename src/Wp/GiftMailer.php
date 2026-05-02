<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Sends gift code emails via wp_mail (FluentSMTP transport in prod).
 *
 * Two send paths fan out from a single batch coming in from Slim:
 *
 *   1. Codes carrying recipient_email      → personalized HTML email per recipient
 *      using templates/email/gift-recipient.html.php. From-name reflects the
 *      buyer ("Sarah Chen via The Looth Group"). Reply-To set to the buyer's
 *      address so recipients can thank them.
 *
 *   2. Codes WITHOUT recipient_email       → bulk-summary email to the buyer
 *      (legacy "buyer keeps the codes and forwards them" mode).
 *
 *   Buyer always also gets a receipt/summary email with every code listed —
 *   recipient-email-attached codes show "✓ emailed to <addr>" so the buyer
 *   has a record of who got what.
 *
 * Buyer is upserted as a FluentCRM contact tagged 'gift-purchaser' for future
 * marketing; that side-effect happens once per call regardless of code split.
 */
final class GiftMailer
{
    private const TAG = 'gift-purchaser';

    /**
     * @param list<array{id?:int,code:string,tier:string,duration_days:int,recipient_email?:?string,recipient_name?:?string,gift_message?:?string}> $codes
     */
    public function send(string $toEmail, string $toName, array $codes): void
    {
        if ( $codes === [] ) {
            return;
        }
        if ( ! is_email( $toEmail ) ) {
            error_log( "LGMS GiftMailer: invalid buyer email rejected: {$toEmail}" );
            return;
        }

        $this->upsertContact( $toEmail, $toName );

        // Split codes into "addressed" (have recipient_email) vs "buyer-keeps".
        $addressed   = [];
        $buyerKept   = [];
        foreach ( $codes as $c ) {
            $rEmail = isset( $c['recipient_email'] ) ? trim( (string) $c['recipient_email'] ) : '';
            if ( $rEmail !== '' && is_email( $rEmail ) ) {
                $addressed[] = $c;
            } else {
                $buyerKept[] = $c;
            }
        }

        // Per-recipient personalized emails.
        foreach ( $addressed as $c ) {
            $this->sendRecipientMail( $c, $toEmail, $toName );
        }

        // Buyer summary — always sent, but tone changes based on the split.
        $this->sendBuyerSummary( $toEmail, $toName, $buyerKept, $addressed );
    }

    private function upsertContact( string $email, string $name ): void
    {
        if ( ! function_exists( 'FluentCrmApi' ) ) {
            return;
        }
        $parts  = explode( ' ', trim( $name ), 2 );
        $result = FluentCrmApi( 'contacts' )->createOrUpdate( [
            'email'      => $email,
            'first_name' => $parts[0],
            'last_name'  => $parts[1] ?? '',
            'status'     => 'subscribed',
        ] );
        $contact = ( isset( $result->model ) && method_exists( $result->model, 'attachTags' ) )
            ? $result->model
            : $result;
        if ( $contact && method_exists( $contact, 'attachTags' ) ) {
            $contact->attachTags( [ self::TAG ] );
            $listId = (int) get_option( 'lgms_gift_purchaser_list_id', 0 );
            if ( $listId > 0 && method_exists( $contact, 'attachLists' ) ) {
                $contact->attachLists( [ $listId ] );
            } elseif ( $listId === 0 && ! get_transient( 'lgms_gift_list_warning' ) ) {
                error_log( 'LGMS GiftMailer: lgms_gift_purchaser_list_id WP option not set; skipping list attachment.' );
                set_transient( 'lgms_gift_list_warning', 1, HOUR_IN_SECONDS );
            }
        }
    }

    private function tierLabel( string $tier ): string
    {
        return match ( $tier ) {
            'looth2' => 'Looth LITE',
            'looth3' => 'Looth PRO',
            default  => 'Looth membership',
        };
    }

    private function redeemBaseUrl(): string
    {
        $opt = (string) get_option( 'lgms_redeem_url', '' );
        return $opt !== '' ? $opt : (string) home_url( '/lggift/' );
    }

    /**
     * One personalized HTML email per addressed code.
     *
     * @param array{id?:int,code:string,tier:string,duration_days:int,recipient_email?:?string,recipient_name?:?string,gift_message?:?string} $c
     */
    private function sendRecipientMail( array $c, string $giverEmail, string $giverName ): void
    {
        $recipientEmail = trim( (string) ( $c['recipient_email'] ?? '' ) );
        if ( $recipientEmail === '' || ! is_email( $recipientEmail ) ) {
            return;
        }

        $recipientName = trim( (string) ( $c['recipient_name'] ?? '' ) );
        $code          = (string) $c['code'];
        $tierSlug      = (string) $c['tier'];
        $tierLabel     = $this->tierLabel( $tierSlug );
        $isPro         = $tierSlug === 'looth3';
        $giftMessage   = trim( (string) ( $c['gift_message'] ?? '' ) );
        $hasGiver      = $giverName !== '' && strtolower( $giverName ) !== 'looth member';
        $renderedGiver = $hasGiver ? $giverName : 'Someone';
        $redeemUrl     = add_query_arg( 'code', $code, $this->redeemBaseUrl() );
        $supportEmail  = $this->supportEmail();

        $subject = $hasGiver
            ? sprintf( '%s has gifted you a %s membership 🎁', $renderedGiver, $tierLabel )
            : sprintf( 'A gift %s membership — for you 🎁', $tierLabel );

        $body = $this->renderTemplate( 'gift-recipient', [
            'recipientName' => $recipientName,
            'giverName'     => $renderedGiver,
            'hasGiver'      => $hasGiver,
            'code'          => $code,
            'tierSlug'      => $tierSlug,
            'tierLabel'     => $tierLabel,
            'giftMessage'   => $giftMessage,
            'redeemUrl'     => $redeemUrl,
            'isPro'         => $isPro,
            'supportEmail'  => $supportEmail,
        ] );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( $hasGiver ) {
            $fromName = sprintf( '%s via The Looth Group', $giverName );
            $headers[] = 'From: ' . $this->encodeFromHeader( $fromName, $this->fromEmail() );
        }
        // Reply-To = giver if we have a valid email, else support — so a "thanks!"
        // reply lands somewhere useful.
        $replyTo = ( $giverEmail !== '' && is_email( $giverEmail ) ) ? $giverEmail : $supportEmail;
        if ( $replyTo !== '' ) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        wp_mail( $recipientEmail, $subject, $body, $headers );
    }

    /**
     * Buyer-summary email. Always sent; content varies by split:
     *  - All buyer-kept    → legacy code list (one big "here are your codes" email)
     *  - All addressed     → confirmation receipt ("we've emailed your N recipients")
     *  - Mixed             → both sections
     *
     * @param list<array{code:string,tier:string,recipient_email?:?string,recipient_name?:?string}> $buyerKept
     * @param list<array{code:string,tier:string,recipient_email?:?string,recipient_name?:?string}> $addressed
     */
    private function sendBuyerSummary( string $toEmail, string $toName, array $buyerKept, array $addressed ): void
    {
        $totalCount  = count( $buyerKept ) + count( $addressed );
        $tier        = (string) ( $buyerKept[0]['tier'] ?? $addressed[0]['tier'] ?? '' );
        $tierLabel   = $this->tierLabel( $tier );
        $nameEs      = esc_html( $toName );
        $redeemBase  = $this->redeemBaseUrl();

        $isAddressedOnly = $buyerKept === [] && $addressed !== [];
        $isMixed         = $buyerKept !== [] && $addressed !== [];

        $subject = $isAddressedOnly
            ? sprintf( 'Your %d Looth gift purchase — %s recipients emailed', $totalCount, count( $addressed ) )
            : sprintf( 'Your %d Looth Gift Membership %s', $totalCount, $totalCount === 1 ? 'Code' : 'Codes' );

        // Buyer-kept codes block (clickable code chips, same as legacy)
        $buyerKeptHtml = '';
        if ( $buyerKept !== [] ) {
            $items = implode( '', array_map(
                static function ( array $c ) use ( $redeemBase ): string {
                    $code = (string) $c['code'];
                    $url  = add_query_arg( 'code', $code, $redeemBase );
                    return '<li style="margin:6px 0">'
                         . '<a href="' . esc_url( $url ) . '" '
                         . 'style="font-family:monospace;font-size:18px;text-decoration:none;'
                         . 'background:#f4f4f4;border:1px solid #ddd;border-radius:4px;'
                         . 'padding:6px 10px;display:inline-block;letter-spacing:0.05em;color:#222;">'
                         . esc_html( $code )
                         . '</a>'
                         . '</li>';
                },
                $buyerKept,
            ) );
            $buyerKeptCount = count( $buyerKept );
            $buyerKeptHtml = '<h3 style="margin:24px 0 8px;font-size:16px;">'
                           . esc_html( sprintf( '%d code%s for you to forward', $buyerKeptCount, $buyerKeptCount === 1 ? '' : 's' ) )
                           . '</h3>'
                           . '<p style="margin:0 0 8px;font-size:14px;color:#444;">Click any code to redeem &mdash; or share the codes with whoever you like.</p>'
                           . '<ul style="list-style:none;padding-left:0">' . $items . '</ul>';
        }

        // Addressed codes block (table of recipients with their codes)
        $addressedHtml = '';
        if ( $addressed !== [] ) {
            $rows = '';
            foreach ( $addressed as $c ) {
                $rEmail = esc_html( (string) ( $c['recipient_email'] ?? '' ) );
                $rName  = esc_html( (string) ( $c['recipient_name']  ?? '' ) );
                $code   = esc_html( (string) $c['code'] );
                $rows  .= '<tr>'
                        . '<td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:14px;">'
                        . ( $rName !== '' ? $rName . '<br><span style="opacity:.7;font-size:12px;">' . $rEmail . '</span>' : $rEmail )
                        . '</td>'
                        . '<td style="padding:6px 8px;border-bottom:1px solid #eee;font-family:monospace;font-size:14px;letter-spacing:.05em;">'
                        . $code
                        . '</td></tr>';
            }
            $addressedCount = count( $addressed );
            $addressedHtml = '<h3 style="margin:24px 0 8px;font-size:16px;">'
                           . esc_html( sprintf( '%d code%s emailed directly', $addressedCount, $addressedCount === 1 ? '' : 's' ) )
                           . '</h3>'
                           . '<p style="margin:0 0 8px;font-size:14px;color:#444;">Each recipient below received their own personalized email with the code shown. Listed here so you have a record.</p>'
                           . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;font-family:Arial,sans-serif;">'
                           . '<thead><tr>'
                           . '<th align="left" style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:13px;color:#666;">Recipient</th>'
                           . '<th align="left" style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:13px;color:#666;">Code</th>'
                           . '</tr></thead>'
                           . '<tbody>' . $rows . '</tbody></table>';
        }

        $intro = $isAddressedOnly
            ? '<p>Thanks for your purchase! We just emailed each of your ' . count( $addressed ) . ' recipient' . ( count( $addressed ) === 1 ? '' : 's' ) . ' with their own personalized gift email.</p>'
                . '<p>Below is a record of who got what, in case you need it. Codes haven\'t been redeemed yet &mdash; recipients can redeem at their convenience.</p>'
            : ( $isMixed
                ? '<p>Thanks for your purchase! Here\'s the rundown of all ' . esc_html( (string) $totalCount ) . ' ' . esc_html( $tierLabel ) . ' codes.</p>'
                : '<p>Thank you for your purchase! Here are your ' . esc_html( (string) $totalCount ) . ' ' . esc_html( $tierLabel ) . ' code' . ( $totalCount === 1 ? '' : 's' ) . ' below. Each grants a 1-year membership when redeemed.</p>'
              );

        $body = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><title>' . esc_html( $subject ) . '</title></head>'
              . '<body style="font-family:Arial,sans-serif;color:#222;line-height:1.5;">'
              . '<p>Hi ' . $nameEs . ',</p>'
              . $intro
              . $addressedHtml
              . $buyerKeptHtml
              . '<p style="opacity:.75;font-size:0.9em;margin-top:24px;">Codes do not expire. Each code can be redeemed once at <a href="' . esc_url( $redeemBase ) . '">' . esc_html( $redeemBase ) . '</a>.</p>'
              . '<p>Thanks,<br>The Looth Team</p>'
              . '</body></html>';

        wp_mail( $toEmail, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    private function renderTemplate( string $name, array $vars ): string
    {
        $path = LGPO_PLUGIN_DIR . 'templates/email/' . $name . '.html.php';
        if ( ! file_exists( $path ) ) {
            error_log( "LGMS GiftMailer: missing email template {$path}" );
            return '';
        }
        ob_start();
        extract( $vars, EXTR_SKIP );
        include $path;
        return (string) ob_get_clean();
    }

    private function fromEmail(): string
    {
        // Prefer FluentSMTP/admin email so the From domain matches DKIM/SPF.
        $admin = (string) get_option( 'admin_email', '' );
        return $admin !== '' && is_email( $admin ) ? $admin : 'hello@loothgroup.com';
    }

    private function supportEmail(): string
    {
        $opt = (string) get_option( 'lgms_refund_email', '' );
        return $opt !== '' && is_email( $opt ) ? $opt : $this->fromEmail();
    }

    private function encodeFromHeader( string $name, string $email ): string
    {
        // Strip CR/LF to defeat header injection; quote the name if it contains
        // RFC 5322 specials.
        $name  = trim( str_replace( [ "\r", "\n" ], '', $name ) );
        $email = trim( str_replace( [ "\r", "\n", '<', '>' ], '', $email ) );
        if ( preg_match( '/[",;:<>\[\]\\\\]/', $name ) ) {
            $name = '"' . addslashes( $name ) . '"';
        }
        return $name === '' ? $email : sprintf( '%s <%s>', $name, $email );
    }
}
