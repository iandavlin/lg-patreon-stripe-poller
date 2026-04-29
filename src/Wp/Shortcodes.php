<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * Front-end shortcodes. Registered from Plugin::boot() on `init`.
 *
 *   [lg_redeem_gift]  — gift code redemption form
 *
 * Planned (not yet built):
 *   [lg_join]                — tier picker + Stripe Checkout
 *   [lg_manage_subscription] — Stripe customer portal launcher
 *   [lg_membership_status]   — current tier + renewal info
 */
final class Shortcodes
{
    public static function register(): void
    {
        add_shortcode( 'lg_redeem_gift', [ self::class, 'redeemGift' ] );
    }

    public static function redeemGift( $atts = [] ): string
    {
        $atts = shortcode_atts( [
            'heading' => 'Redeem a Gift Code',
        ], (array) $atts, 'lg_redeem_gift' );

        $user        = wp_get_current_user();
        $isLoggedIn  = $user->ID > 0;
        $emailValue  = $isLoggedIn ? (string) $user->user_email : '';
        $nameValue   = $isLoggedIn ? trim( (string) ( $user->display_name ?: $user->user_login ) ) : '';
        $endpointUrl = esc_url_raw( home_url( '/billing/v1/redeem' ) );

        $heading = esc_html( (string) $atts['heading'] );
        $email   = esc_attr( $emailValue );
        $name    = esc_attr( $nameValue );
        $endpoint = esc_js( $endpointUrl );

        ob_start();
        ?>
        <div class="lg-redeem-gift">
            <h3 class="lg-redeem-gift__heading"><?php echo $heading; ?></h3>
            <form class="lg-redeem-gift__form" data-lg-redeem>
                <label class="lg-redeem-gift__label">
                    <span>Gift Code</span>
                    <input
                        type="text"
                        name="code"
                        required
                        maxlength="12"
                        autocomplete="off"
                        pattern="[A-Za-z0-9]{12}"
                        title="12-character gift code"
                        placeholder="ABCDEFGHIJKL"
                        style="text-transform:uppercase;letter-spacing:0.1em;"
                    >
                </label>
                <label class="lg-redeem-gift__label">
                    <span>Email</span>
                    <input type="email" name="email" required value="<?php echo $email; ?>">
                </label>
                <label class="lg-redeem-gift__label">
                    <span>Name <em style="opacity:.6;">(optional)</em></span>
                    <input type="text" name="name" value="<?php echo $name; ?>">
                </label>
                <button type="submit" class="lg-redeem-gift__submit">Redeem</button>
            </form>
            <div class="lg-redeem-gift__result" data-lg-redeem-result aria-live="polite"></div>
        </div>
        <script>
        (function(){
            const form     = document.querySelector('[data-lg-redeem]');
            const resultEl = document.querySelector('[data-lg-redeem-result]');
            const submitBt = form.querySelector('button[type="submit"]');
            if (!form) return;

            form.addEventListener('submit', async function(e){
                e.preventDefault();
                resultEl.textContent = 'Redeeming…';
                resultEl.className   = 'lg-redeem-gift__result is-pending';
                submitBt.disabled    = true;

                const data = {
                    code:  (form.code.value  || '').trim().toUpperCase(),
                    email: (form.email.value || '').trim(),
                    name:  (form.name.value  || '').trim(),
                };

                try {
                    const res  = await fetch('<?php echo $endpoint; ?>', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(data),
                    });
                    const json = await res.json();

                    if (json.ok) {
                        resultEl.className = 'lg-redeem-gift__result is-success';
                        resultEl.textContent = json.message + ' (expires ' + json.expires_at + ')';
                        form.reset();
                    } else {
                        resultEl.className = 'lg-redeem-gift__result is-error';
                        resultEl.textContent = json.error || 'Unable to redeem code.';
                    }
                } catch (err) {
                    resultEl.className   = 'lg-redeem-gift__result is-error';
                    resultEl.textContent = 'Network error: ' + err.message;
                } finally {
                    submitBt.disabled = false;
                }
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
