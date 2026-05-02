<?php
/**
 * Recipient HTML email template — sent by GiftMailer::sendRecipientMail()
 * for each gift code that carries recipient_email.
 *
 * Mirrors public/mockup-gift-email.html in lg-stripe-billing — keep them in
 * sync if you iterate on the design. Table-based layout + inline styles for
 * Outlook/Gmail-app compatibility.
 *
 * @var string  $recipientName  The recipient's display name (or 'there' fallback).
 * @var string  $giverName      The buyer's name (or 'Someone' for anonymous).
 * @var bool    $hasGiver       Whether the giver entered a name (controls hero copy).
 * @var string  $code           The gift code value.
 * @var string  $tierSlug       'looth2' | 'looth3'
 * @var string  $tierLabel      'Looth LITE' | 'Looth PRO'
 * @var string  $giftMessage    Optional buyer-supplied message (raw text, escape on output).
 * @var string  $redeemUrl      Pre-filled redemption URL.
 * @var bool    $isPro          True for Looth PRO (controls perks list).
 * @var string  $supportEmail   Fallback contact for "didn't expect this?" line.
 */

$rName  = esc_html( $recipientName !== '' ? $recipientName : 'there' );
$gName  = esc_html( $giverName );
$noteEs = $giftMessage !== '' ? esc_html( $giftMessage ) : '';
$tierEs = esc_html( $tierLabel );
$codeEs = esc_html( $code );
$urlEs  = esc_url( $redeemUrl );
$supEs  = esc_attr( $supportEmail );

if ( $hasGiver ) {
    $heroLine  = "You've been gifted a {$tierEs} membership";
    $greetLine = "Hi <strong>{$rName}</strong>, <strong>{$gName}</strong> picked up a 1-year {$tierEs} membership for you. Welcome.";
} else {
    $heroLine  = "Someone gifted you a {$tierEs} membership";
    $greetLine = "Hi <strong>{$rName}</strong> &mdash; someone picked up a 1-year {$tierEs} membership for you. Lucky you.";
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Your Looth gift</title></head>
<body style="margin:0;padding:0;background:#f3f1ea;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f1ea;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1f1d1a;">
  <tr><td align="center" style="padding:32px 16px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">

      <tr><td style="background:#87986A;color:#ffffff;padding:24px 28px 22px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:18px;font-weight:600;letter-spacing:.5px;">The Looth Group</td>
            <td align="right" style="font-size:13px;opacity:.85;">A gift for you</td>
          </tr>
        </table>
      </td></tr>

      <tr><td style="padding:36px 28px 8px;text-align:center;">
        <div style="font-size:44px;line-height:1;margin-bottom:8px;">🎁</div>
        <h1 style="margin:0 0 6px;font-size:24px;line-height:1.2;font-weight:700;color:#1f1d1a;"><?php echo $heroLine; ?></h1>
        <p style="margin:14px 0 0;font-size:15px;line-height:1.55;color:#444;"><?php echo $greetLine; ?></p>
      </td></tr>

      <?php if ( $noteEs !== '' ) : ?>
      <tr><td style="padding:18px 28px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fbf6e8;border-left:4px solid #ECB351;border-radius:0 6px 6px 0;">
          <tr><td style="padding:14px 18px;font-size:14.5px;line-height:1.55;color:#1f1d1a;">
            <em>&ldquo;<?php echo nl2br( $noteEs ); ?>&rdquo;</em>
          </td></tr>
        </table>
      </td></tr>
      <?php endif; ?>

      <tr><td style="padding:24px 28px 8px;">
        <p style="margin:0 0 8px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#777;">Your gift code</p>
        <div style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:24px;font-weight:600;letter-spacing:.12em;background:#f7f5ee;border:1px dashed #c0bfb8;border-radius:8px;padding:14px 16px;text-align:center;color:#1f1d1a;">
          <?php echo $codeEs; ?>
        </div>
      </td></tr>

      <tr><td style="padding:18px 28px 28px;text-align:center;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
          <tr><td style="background:#ECB351;border-radius:8px;">
            <a href="<?php echo $urlEs; ?>" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;color:#1f1d1a;text-decoration:none;border-radius:8px;">Redeem your membership &rarr;</a>
          </td></tr>
        </table>
        <p style="margin:12px 0 0;font-size:13px;color:#777;">Codes never expire &mdash; redeem whenever you're ready.</p>
      </td></tr>

      <tr><td style="padding:0 28px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f7f5ee;border-radius:8px;">
          <tr><td style="padding:18px 22px;">
            <p style="margin:0 0 10px;font-size:14px;font-weight:600;">What you get for the next 12 months</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr><td style="padding:3px 0;font-size:14px;line-height:1.55;color:#444;">&#10003; Members-only forums and full historical archive</td></tr>
              <?php if ( $isPro ) : ?>
              <tr><td style="padding:3px 0;font-size:14px;line-height:1.55;color:#444;">&#10003; Sponsor benefits and exclusive content</td></tr>
              <?php endif; ?>
              <tr><td style="padding:3px 0;font-size:14px;line-height:1.55;color:#444;">&#10003; Member events and community calendar</td></tr>
            </table>
          </td></tr>
        </table>
      </td></tr>

      <tr><td style="padding:0 28px 28px;">
        <p style="margin:0 0 10px;font-size:13px;line-height:1.55;color:#888;">
          Didn't expect this? <a href="mailto:<?php echo $supEs; ?>" style="color:#87986A;">Reply to this email</a> and we'll sort it out &mdash; no harm done. Codes can be returned within 30 days.
        </p>
        <p style="margin:0;font-size:12px;color:#aaa;">
          The Looth Group &middot; <a href="<?php echo esc_url( home_url() ); ?>" style="color:#aaa;"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'loothgroup.com' ); ?></a>
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>
