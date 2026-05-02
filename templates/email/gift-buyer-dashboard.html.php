<?php
/**
 * Buyer dashboard-mode email — sent when the buyer opted to manage their
 * codes from /my-gifts/ instead of receiving them as a code list.
 *
 * No code values appear in this email (the buyer can grab them from the
 * dashboard) — keeps the email short and focused on the CTA.
 *
 * @var string $buyerName     The buyer's display name (or 'there' fallback).
 * @var int    $count         Total codes purchased.
 * @var string $tierLabel     'Looth LITE' | 'Looth PRO'
 * @var string $dashboardUrl  Absolute URL to /my-gifts/
 * @var string $supportEmail
 */

$nameEs   = esc_html( $buyerName !== '' ? $buyerName : 'there' );
$countEs  = (int) $count;
$tierEs   = esc_html( $tierLabel );
$dashEs   = esc_url( $dashboardUrl );
$supEs    = esc_attr( $supportEmail );
$plural   = $countEs === 1 ? '' : 's';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Your gift codes are ready</title></head>
<body style="margin:0;padding:0;background:#f3f1ea;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f1ea;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1f1d1a;">
  <tr><td align="center" style="padding:32px 16px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);">

      <tr><td style="background:#87986A;color:#ffffff;padding:24px 28px 22px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:18px;font-weight:600;letter-spacing:.5px;">The Looth Group</td>
            <td align="right" style="font-size:13px;opacity:.85;">Gift purchase confirmation</td>
          </tr>
        </table>
      </td></tr>

      <tr><td style="padding:36px 28px 8px;text-align:center;">
        <div style="font-size:44px;line-height:1;margin-bottom:8px;">🎁</div>
        <h1 style="margin:0 0 6px;font-size:22px;line-height:1.25;font-weight:700;color:#1f1d1a;">
          Hi <?php echo $nameEs; ?> &mdash; you have <strong><?php echo $countEs; ?> <?php echo $tierEs; ?> gift code<?php echo $plural; ?></strong> ready to send.
        </h1>
        <p style="margin:14px 0 0;font-size:15px;line-height:1.55;color:#444;">
          Send them all at once, or distribute them over time &mdash; whatever works for you.
        </p>
      </td></tr>

      <tr><td style="padding:24px 28px 8px;text-align:center;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
          <tr><td style="background:#ECB351;border-radius:8px;">
            <a href="<?php echo $dashEs; ?>" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:600;color:#1f1d1a;text-decoration:none;border-radius:8px;">Open your gift dashboard &rarr;</a>
          </td></tr>
        </table>
      </td></tr>

      <tr><td style="padding:18px 28px 28px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f7f5ee;border-radius:8px;">
          <tr><td style="padding:18px 22px;">
            <p style="margin:0 0 10px;font-size:14px;font-weight:600;">In your dashboard you can:</p>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
              <tr><td style="padding:3px 0;font-size:14px;line-height:1.55;color:#444;">&#10003; Send a code to a recipient with a personal note</td></tr>
              <tr><td style="padding:3px 0;font-size:14px;line-height:1.55;color:#444;">&#10003; Resend, reassign, or void codes that haven't been redeemed yet</td></tr>
              <tr><td style="padding:3px 0;font-size:14px;line-height:1.55;color:#444;">&#10003; See who has redeemed their gift, and when</td></tr>
            </table>
          </td></tr>
        </table>
      </td></tr>

      <tr><td style="padding:0 28px 28px;">
        <p style="margin:0 0 10px;font-size:13px;line-height:1.55;color:#888;">
          Codes never expire &mdash; recipients can redeem whenever they're ready. Need help? <a href="mailto:<?php echo $supEs; ?>" style="color:#87986A;">Email us anytime</a>.
        </p>
        <p style="margin:0;font-size:12px;color:#aaa;">
          The Looth Group &middot; <a href="<?php echo esc_url( home_url() ); ?>" style="color:#aaa;"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'loothgroup.com' ); ?></a>
        </p>
      </td></tr>

    </table>
  </td></tr>
</table>
</body></html>
