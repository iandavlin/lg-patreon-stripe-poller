<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Sends gift code emails via FluentCRM.
 *
 * Creates/updates the purchaser as a FluentCRM contact tagged 'gift-purchaser'
 * so the contact record exists for future marketing. Email routes through
 * whatever transport FluentCRM / FluentSMTP has configured.
 */
final class GiftMailer
{
    private const TAG = 'gift-purchaser';

    /**
     * @param list<array{code:string,tier:string,duration_days:int}> $codes
     */
    public function send(string $toEmail, string $toName, array $codes): void
    {
        if ( $codes === [] ) {
            return;
        }

        if ( ! is_email( $toEmail ) ) {
            error_log( "LGMS GiftMailer: invalid recipient email rejected: {$toEmail}" );
            return;
        }

        $this->upsertContact( $toEmail, $toName );
        $this->sendMail( $toEmail, $toName, $codes );
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

        // createOrUpdate returns the Contact model directly or an object with ->model
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

    /**
     * @param list<array{code:string,tier:string,duration_days:int}> $codes
     *
     * Assumes all codes in the batch share the same tier + duration_days, which
     * is true today: one Stripe gift checkout = one product = one batch.
     *
     * Each code is rendered both as plain monospace text (so it's copy-pasteable
     * even if the recipient's mail client strips HTML) and wrapped in a link to
     * the redemption page with ?code= prefilled.
     */
    private function sendMail( string $toEmail, string $toName, array $codes ): void
    {
        $count    = count( $codes );
        $tier     = esc_html( $codes[0]['tier'] );
        $days     = (int) $codes[0]['duration_days'];
        $subject  = "Your {$count} Looth Gift Membership Code" . ( $count > 1 ? 's' : '' );
        $nameEsc  = esc_html( $toName );
        $redeemBase = (string) get_option( 'lgms_redeem_url', '' );
        if ( $redeemBase === '' ) {
            $redeemBase = (string) home_url( '/lggift/' );
        }

        $codeItems = implode( '', array_map(
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
            $codes,
        ) );

        $singular = $count === 1;
        $codeWord = $singular ? 'code' : 'codes';

        $body = <<<HTML
        <!doctype html>
        <html lang="en"><head><meta charset="UTF-8"><title>{$subject}</title></head>
        <body style="font-family:Arial,sans-serif;color:#222;line-height:1.5;">
        <p>Hi {$nameEsc},</p>
        <p>Thank you for your purchase! Here {$count} Looth Gift Membership {$codeWord} below.<br>
        Each grants a {$days}-day {$tier} membership.</p>
        <p><strong>Click any code to redeem it</strong> — it will open the redemption page with the code pre-filled.</p>
        <ul style="list-style:none;padding-left:0">{$codeItems}</ul>
        <p style="opacity:.75;font-size:0.9em;">If you'd rather redeem manually, visit <a href="{$redeemBase}">{$redeemBase}</a> and paste a code. Each code can only be used once. Codes do not expire.</p>
        <p>Thanks,<br>The Looth Team</p>
        </body></html>
        HTML;

        wp_mail(
            $toEmail,
            $subject,
            $body,
            [ 'Content-Type: text/html; charset=UTF-8' ],
        );
    }
}
