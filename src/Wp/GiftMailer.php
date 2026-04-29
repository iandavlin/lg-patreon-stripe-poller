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
        }
    }

    /**
     * @param list<array{code:string,tier:string,duration_days:int}> $codes
     */
    private function sendMail( string $toEmail, string $toName, array $codes ): void
    {
        $count   = count( $codes );
        $tier    = esc_html( $codes[0]['tier'] );
        $days    = (int) $codes[0]['duration_days'];
        $subject = "Your {$count} Looth Gift Membership Code" . ( $count > 1 ? 's' : '' );
        $nameEsc = esc_html( $toName );

        $codeItems = implode( '', array_map(
            static fn ( array $c ): string =>
                '<li style="font-family:monospace;font-size:16px;margin:4px 0">' . esc_html( $c['code'] ) . '</li>',
            $codes,
        ) );

        $body = <<<HTML
        <p>Hi {$nameEsc},</p>
        <p>Thank you for your purchase! Here are your {$count} Looth Gift Membership code(s).<br>
        Each code grants a {$days}-day {$tier} membership.</p>
        <ul>{$codeItems}</ul>
        <p>To redeem, visit loothgroup.com and enter your code when prompted.<br>
        Each code can only be used once.</p>
        <p>Thanks,<br>The Looth Team</p>
        HTML;

        wp_mail(
            $toEmail,
            $subject,
            $body,
            [ 'Content-Type: text/html; charset=UTF-8' ],
        );
    }
}
