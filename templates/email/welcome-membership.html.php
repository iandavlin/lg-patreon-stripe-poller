<?php
/**
 * Welcome / upgrade-confirmation email body.
 *
 * Variables in scope:
 *   $name       — display name or username (string)
 *   $tierLabel  — "Looth LITE" | "Looth PRO" | "Looth Premium Plus" (string)
 *   $loginUrl   — wp-login.php URL with redirect to /manage-subscription/
 *   $manageUrl  — direct link to /manage-subscription/
 *   $homeUrl    — home URL
 */
?><!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to <?php echo esc_html( $tierLabel ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f1ea;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1f1d1a;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f4f1ea;padding:30px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border:2px solid #ECB351;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:32px 32px 8px 32px;text-align:center;">
                            <div style="font-size:42px;line-height:1;margin-bottom:8px;">🎉</div>
                            <h1 style="margin:0;font-size:24px;font-weight:700;color:#1f1d1a;">
                                Welcome to <?php echo esc_html( $tierLabel ); ?>
                            </h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px 24px 32px;font-size:16px;line-height:1.5;color:#333;">
                            <p style="margin:0 0 16px;">
                                Hi <?php echo esc_html( $name ); ?>,
                            </p>
                            <p style="margin:0 0 16px;">
                                Your payment went through and your <strong><?php echo esc_html( $tierLabel ); ?></strong>
                                membership is now active. You have full access to forums, archives, member events,
                                and everything that comes with your tier.
                            </p>
                            <p style="margin:0 0 24px;">
                                If you ever need to update your card, change plans, or cancel, you can manage
                                everything from your subscription dashboard.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;text-align:center;">
                            <a href="<?php echo esc_url( $loginUrl ); ?>"
                               style="display:inline-block;padding:14px 32px;background:#ECB351;color:#1f1d1a;
                                      font-weight:700;text-decoration:none;border-radius:8px;font-size:16px;">
                                Sign in &amp; manage your account
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px 32px;font-size:13px;line-height:1.5;color:#777;text-align:center;border-top:1px solid #eee;padding-top:24px;">
                            <p style="margin:0 0 6px;">
                                Direct links:
                                <a href="<?php echo esc_url( $manageUrl ); ?>" style="color:#87986A;">Manage subscription</a> &middot;
                                <a href="<?php echo esc_url( $homeUrl ); ?>" style="color:#87986A;">Visit the site</a>
                            </p>
                            <p style="margin:0;">
                                Questions? Just reply to this email and a human will get back to you.
                            </p>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-size:12px;color:#999;">
                    The Looth Group
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
