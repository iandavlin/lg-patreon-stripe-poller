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
        add_shortcode( 'lg_redeem_gift',         [ self::class, 'redeemGift'         ] );
        add_shortcode( 'lg_join',                [ self::class, 'join'               ] );
        add_shortcode( 'lg_manage_subscription', [ self::class, 'manageSubscription' ] );
        add_shortcode( 'lg_manage_membership',   [ self::class, 'manageSubscription' ] );
        add_shortcode( 'lg_gift',                [ self::class, 'gift'               ] );
        add_shortcode( 'lg_refund_request',      [ self::class, 'refundRequest'      ] );
        add_shortcode( 'lg_regional_fail',       [ self::class, 'regionalFail'       ] );
        add_shortcode( 'lg_subscription_success',[ self::class, 'subscriptionSuccess'] );
        add_shortcode( 'lg_my_gifts',            [ self::class, 'myGifts'            ] );
        add_shortcode( 'lg_member_nav',          [ self::class, 'memberNav'          ] );
    }

    /**
     * [lg_gift] — gift purchase flow. Buyer picks tier, qty (>=2), pays.
     * Codes are emailed to the buyer after Stripe completes the charge;
     * each code can be passed on and redeemed independently via [lg_redeem_gift].
     *
     * Independent of [lg_join] — an active subscriber can buy gifts without
     * blocking. Codes never expire.
     */
    public static function gift( $atts = [] ): string
    {
        $atts = shortcode_atts( [
            'heading' => 'Give the gift of Looth',
            'popular' => 'looth3',
        ], (array) $atts, 'lg_gift' );

        $user       = wp_get_current_user();
        $isLoggedIn = $user->ID > 0;
        $emailValue = $isLoggedIn ? (string) $user->user_email : '';
        $nameValue  = $isLoggedIn ? trim( (string) ( $user->display_name ?: $user->user_login ) ) : '';

        $base      = rtrim( (string) home_url( '/billing' ), '/' );
        $endpoints = [
            'products'       => esc_url_raw( $base . '/v1/products' ),
            'config'         => esc_url_raw( $base . '/v1/config' ),
            'checkout'       => esc_url_raw( $base . '/v1/checkout' ),
            'authUrl'        => esc_url_raw( rest_url( 'lg-member-sync/v1/gift-auth' ) ),
            'affiliateClick' => esc_url_raw( $base . '/v1/affiliate-click' ),
        ];

        $heading     = esc_html( (string) $atts['heading'] );
        $popularRef  = (string) $atts['popular'];
        $email       = esc_attr( $emailValue );
        $name        = esc_attr( $nameValue );
        $endpointsJs = wp_json_encode( $endpoints );
        $configJs    = wp_json_encode( [
            'popular'    => $popularRef,
            'loggedIn'   => $isLoggedIn,
            'buyerEmail' => $emailValue,
            'buyerName'  => $nameValue,
            'loginUrl'   => wp_login_url( get_permalink() ),
        ] );

        // For logged-in buyers we collapse the form to tier + qty + buy.
        // No mode picker, no recipient repeater, no email field — their
        // codes attach to their account and they manage them at /my-gifts/
        // after purchase. The CSS class drives the visibility conditionals.
        $rootClass = 'lg-gift' . ( $isLoggedIn ? ' lg-gift--logged-in' : '' );

        ob_start();
        ?>
        <div class="<?php echo esc_attr( $rootClass ); ?>">
            <?php if ( $isLoggedIn ) : ?>
                <p class="lg-gift__loggedin-banner">
                    Hi <strong><?php echo esc_html( $nameValue ?: $emailValue ); ?></strong> &mdash; codes you buy will land in your <a href="<?php echo esc_url( home_url( '/my-gifts/' ) ); ?>">gift dashboard</a> after checkout.
                </p>
            <?php endif; ?>

            <header class="lg-gift__hero">
                <h2 class="lg-gift__heading"><?php echo $heading; ?></h2>
                <p class="lg-gift__intro">
                    Buy gift codes to share with anyone. Each code grants a membership when redeemed. Codes never expire.
                </p>
                <ul class="lg-gift__perks">
                    <li>✓ Full members-only forums + archive</li>
                    <li>✓ Sponsor benefits and member events</li>
                    <li>✓ Shareable to anyone — they redeem at their convenience</li>
                </ul>
            </header>

            <div class="lg-gift__panel">
                <h3 class="lg-gift__panel-heading">1. Tier selection</h3>
                <div class="lg-gift__tier-dropdown" data-lg-tier-dropdown>
                    <button type="button" class="lg-gift__tier-trigger" data-lg-tier-trigger aria-haspopup="listbox" aria-expanded="false">
                        <span class="lg-gift__tier-trigger-text" data-lg-tier-selected>Loading…</span>
                        <span class="lg-gift__tier-chevron" aria-hidden="true">▾</span>
                    </button>
                    <div class="lg-gift__tier-options" data-lg-tier-options role="listbox" hidden></div>
                </div>

                <h3 class="lg-gift__panel-heading">2. Membership duration</h3>
                <div class="lg-gift__durations" data-lg-gift-durations></div>

                <h3 class="lg-gift__panel-heading">3. How many gift memberships?</h3>
                <div class="lg-gift__quantity-row">
                    <button type="button" class="lg-gift__qbtn" data-lg-qty-step="-1" aria-label="Decrease">−</button>
                    <input type="number" class="lg-gift__qinput" name="quantity" value="1" min="1" step="1" required>
                    <button type="button" class="lg-gift__qbtn" data-lg-qty-step="1" aria-label="Increase">+</button>
                </div>
                <div class="lg-gift__presets" data-lg-gift-presets></div>

                <div class="lg-gift__progress" data-lg-gift-progress hidden>
                    <div class="lg-gift__progress-track"><div class="lg-gift__progress-fill" data-lg-gift-progress-fill></div></div>
                    <p class="lg-gift__progress-label" data-lg-gift-progress-label></p>
                </div>

                <div data-lg-mode-section>
                    <h3 class="lg-gift__panel-heading">4. How do you want your codes?</h3>
                    <div class="lg-mode">

                        <label class="lg-mode__opt is-selected" data-mode="managed">
                            <input type="radio" name="mode" value="managed" checked>
                            <span class="lg-mode__radio"></span>
                            <div>
                                <div class="lg-mode__title">Log in to manage &amp; send personalized gift emails <span class="lg-mode__badge">Recommended</span></div>
                                <div class="lg-mode__sub">Access your gift dashboard after purchase &mdash; resend, refund, or send a beautiful personalized email to each recipient whenever you&rsquo;re ready.</div>
                            </div>
                        </label>

                        <label class="lg-mode__opt" data-mode="self">
                            <input type="radio" name="mode" value="self">
                            <span class="lg-mode__radio"></span>
                            <div>
                                <div class="lg-mode__title">Get codes via email</div>
                                <div class="lg-mode__sub">We send all codes to your email. Forward or share them yourself.</div>
                                <div class="lg-mode__note">Heads up: without an account we can&rsquo;t recover or refund these codes if you lose access to this email.</div>
                            </div>
                        </label>

                    </div>

                    <div class="lg-auth" data-lg-auth-block hidden>
                        <p class="lg-auth__login-prompt">
                            Have you logged on before?
                            <a href="#" data-lg-auth-open-login>Log in instead &rarr;</a>
                        </p>
                        <div class="lg-auth__fields">
                            <input type="email"    class="lg-auth__input" data-lg-auth-email          placeholder="your@email.com"                  autocomplete="email">
                            <input type="email"    class="lg-auth__input" data-lg-auth-email-confirm  placeholder="Confirm email"                   autocomplete="email">
                            <small data-lg-auth-email-mismatch class="lg-pwd-mismatch" hidden>Emails don&rsquo;t match.</small>
                            <input type="password" class="lg-auth__input" data-lg-auth-password         placeholder="Pick a password (8+ characters)" autocomplete="new-password">
                            <input type="password" class="lg-auth__input" data-lg-auth-password-confirm placeholder="Confirm password"               autocomplete="new-password">
                            <small data-lg-auth-pwd-mismatch class="lg-pwd-mismatch" hidden>Passwords don&rsquo;t match.</small>
                        </div>
                        <p class="lg-auth__hint">We&rsquo;ll set up your account so your gift codes attach to your dashboard.</p>
                        <button type="button" class="lg-auth__btn" data-lg-auth-btn>Create account</button>
                        <p class="lg-auth__error" data-lg-auth-error hidden></p>
                    </div>

                    <!-- Inline login modal — opened when an existing member clicks
                         "Log in" or when the create-account submit detects an
                         existing email. Same-page so gift form state survives. -->
                    <div class="lg-pay-modal" data-lg-login-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-login-modal-title">
                        <div class="lg-pay-modal__backdrop" data-lg-login-close></div>
                        <div class="lg-pay-modal__card lg-login-card">
                            <button type="button" class="lg-pay-modal__close" data-lg-login-close aria-label="Close">&times;</button>
                            <div class="lg-login-card__body">
                                <h3 id="lg-login-modal-title" class="lg-login-card__title">Log in to continue</h3>
                                <p class="lg-login-card__sub" data-lg-login-sub>Your gift selections will stay right here.</p>
                                <div class="lg-login-card__fields">
                                    <input type="email"    class="lg-auth__input" data-lg-login-email    placeholder="your@email.com" autocomplete="email">
                                    <input type="password" class="lg-auth__input" data-lg-login-password placeholder="Password"       autocomplete="current-password">
                                </div>
                                <p class="lg-auth__error" data-lg-login-error hidden></p>
                                <button type="button" class="lg-auth__btn" data-lg-login-btn>Log in</button>
                                <p class="lg-login-card__forgot">
                                    <a href="<?php echo esc_url( wp_lostpassword_url( get_permalink() ) ); ?>">Forgot your password?</a>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="lg-auth__success" data-lg-auth-success hidden>
                        <span class="lg-auth__check-icon">&#10003;</span>
                        <span data-lg-auth-welcome>You&rsquo;re logged in.</span>
                    </div>

                </div>

                <div class="lg-consent" data-lg-consent-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-consent-title">
                    <div class="lg-consent__backdrop" data-lg-consent-cancel></div>
                    <div class="lg-consent__card">
                        <button type="button" class="lg-consent__close" data-lg-consent-cancel aria-label="Close">&times;</button>
                        <h3 id="lg-consent-title" class="lg-consent__title">One more thing</h3>
                        <p class="lg-consent__body">
                            We&rsquo;re creating an account for <strong data-lg-consent-email>your email</strong>.
                            Your email will only be used for your gift codes and your account &mdash;
                            <strong>we will never sell or share it</strong>.
                        </p>
                        <label class="lg-consent__check">
                            <input type="checkbox" data-lg-consent-subscribe>
                            <span>Yes, also send me the weekly Looth email &mdash; news, events, and member highlights.</span>
                        </label>
                        <div class="lg-consent__actions">
                            <button type="button" class="lg-consent__btn lg-consent__btn--ghost" data-lg-consent-cancel>Cancel</button>
                            <button type="button" class="lg-consent__btn lg-consent__btn--primary" data-lg-consent-confirm>Create my account</button>
                        </div>
                        <p class="lg-consent__error" data-lg-consent-error hidden></p>
                    </div>
                </div>

                <div class="lg-redirect" data-lg-redirect-overlay hidden role="dialog" aria-modal="true" aria-labelledby="lg-redirect-title">
                    <div class="lg-redirect__card">
                        <div class="lg-redirect__spinner" aria-hidden="true"></div>
                        <div id="lg-redirect-title" class="lg-redirect__title">Setting up secure checkout&hellip;</div>
                        <div class="lg-redirect__sub">Please wait &mdash; the payment form is loading.</div>
                    </div>
                </div>

                <div class="lg-pay-modal" data-lg-checkout-modal hidden role="dialog" aria-modal="true" aria-label="Secure checkout">
                    <div class="lg-pay-modal__backdrop" data-lg-checkout-close></div>
                    <div class="lg-pay-modal__card">
                        <button type="button" class="lg-pay-modal__close" data-lg-checkout-close aria-label="Close checkout">&times;</button>

                        <!-- In-modal processing overlay: shown while confirm() runs. -->
                        <div class="lg-modal-processing" data-lg-gift-modal-processing hidden aria-hidden="true">
                            <div class="lg-modal-processing__spinner" aria-hidden="true"></div>
                            <p class="lg-modal-processing__label">Processing payment&hellip;</p>
                        </div>

                        <!-- Embedded path: kept for fallback if server returns ui_mode=embedded. -->
                        <div class="lg-pay-modal__body" data-lg-gift-checkout></div>

                        <!-- Custom path (Basil): we mount Stripe Elements + render our own Pay button. -->
                        <div class="lg-pay-modal__custom lg-stripe-modal" data-lg-gift-checkout-custom hidden>
                            <div class="lg-stripe-modal__header">
                                <div class="lg-stripe-modal__heading">Complete your purchase</div>
                                <div class="lg-stripe-modal__amount" data-lg-gift-pay-amount>&nbsp;</div>
                                <div class="lg-stripe-modal__sublabel" data-lg-gift-pay-sublabel>&nbsp;</div>
                            </div>
                            <div class="lg-stripe-modal__pe" data-lg-gift-payment-element></div>
                            <div class="lg-stripe-modal__error" data-lg-gift-checkout-error role="alert" hidden></div>
                            <button type="button" class="lg-stripe-modal__pay" data-lg-gift-checkout-pay disabled>
                                <span data-lg-gift-pay-label>Pay</span>
                            </button>
                            <p class="lg-stripe-modal__secured">
                                <svg class="lg-stripe-modal__secured-lock" xmlns="http://www.w3.org/2000/svg" width="11" height="13" viewBox="0 0 11 13" aria-hidden="true"><path fill="currentColor" d="M5.5 0C3.567 0 2 1.567 2 3.5V5h-.5A1.5 1.5 0 0 0 0 6.5v5A1.5 1.5 0 0 0 1.5 13h8a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 9.5 5H9V3.5C9 1.567 7.433 0 5.5 0Zm0 1.5A2 2 0 0 1 7.5 3.5V5h-4V3.5a2 2 0 0 1 2-2Z"/></svg>
                                <span>Powered by</span>
                                <svg class="lg-stripe-modal__secured-mark" xmlns="http://www.w3.org/2000/svg" width="42" height="18" viewBox="0 0 60 25" aria-hidden="true" role="img" aria-label="Stripe"><path fill="#635BFF" d="M59.64 14.28h-8.06c.19 1.93 1.6 2.55 3.2 2.55 1.64 0 2.96-.37 4.05-.95v3.32a8.33 8.33 0 0 1-4.56 1.1c-4.01 0-6.83-2.5-6.83-7.48 0-4.19 2.39-7.52 6.3-7.52 3.92 0 5.96 3.28 5.96 7.5 0 .4-.04 1.26-.06 1.48zm-5.92-5.62c-1.03 0-2.17.73-2.17 2.58h4.25c0-1.85-1.07-2.58-2.08-2.58zM40.95 20.3c-1.44 0-2.32-.6-2.9-1.04l-.02 4.63-4.12.87V5.57h3.76l.08 1.02a4.7 4.7 0 0 1 3.23-1.29c2.9 0 5.62 2.6 5.62 7.4 0 5.23-2.7 7.6-5.65 7.6zM40 9.04c-.95 0-1.54.34-1.97.81l.02 6.12c.4.44.98.78 1.95.78 1.52 0 2.54-1.65 2.54-3.87 0-2.15-1.04-3.84-2.54-3.84zM28.24 5.57h4.13v14.44h-4.13V5.57zm0-4.7L32.37 0v3.36l-4.13.88V.88zm-4.32 9.35v9.79H19.8V5.57h3.7l.12 1.22c1-1.77 3.07-1.41 3.62-1.22v3.79c-.52-.17-2.29-.43-3.32.86zm-8.55 4.72c0 2.43 2.6 1.68 3.12 1.46v3.36c-.55.3-1.54.54-2.89.54a4.15 4.15 0 0 1-4.27-4.24l.01-13.17 4.02-.86v3.54h3.14V9.1h-3.14l.01 5.85zm-4.91.7c0 2.97-2.31 4.66-5.73 4.66a11.2 11.2 0 0 1-4.46-.93v-3.93c1.38.75 3.1 1.31 4.46 1.31.92 0 1.53-.24 1.53-1C6.26 13.77 0 14.51 0 9.95 0 7.04 2.28 5.3 5.62 5.3c1.36 0 2.72.2 4.09.75v3.88a9.23 9.23 0 0 0-4.1-1.06c-.86 0-1.44.25-1.44.93 0 1.85 6.29.97 6.29 5.83z"/></svg>
                            </p>
                        </div>
                    </div>
                </div>

            <div class="lg-processing" data-lg-gift-processing hidden role="status" aria-live="polite" aria-label="Processing payment">
                <div class="lg-processing__card">
                    <div class="lg-processing__spinner" aria-hidden="true"></div>
                    <h3 class="lg-processing__title">Still processing your payment&hellip;</h3>
                    <p class="lg-processing__body">Your payment went through. We&rsquo;re finalizing your order &mdash; please don&rsquo;t close this tab. You&rsquo;ll be redirected in a moment.</p>
                </div>
            </div>

            <!-- Gift purchase success — gift codes are delivered by email, so
                 instead of redirecting away we show a confirmation modal in
                 place. Webhook + reconcile cron handle fulfillment server-side. -->
            <div class="lg-gift-success" data-lg-gift-success hidden role="dialog" aria-modal="true" aria-labelledby="lg-gift-success-title">
                <div class="lg-gift-success__card">
                    <div class="lg-gift-success__icon" aria-hidden="true">&#10003;</div>
                    <h3 id="lg-gift-success-title" class="lg-gift-success__title">Payment received &mdash; thank you!</h3>
                    <p class="lg-gift-success__body">
                        Your gift codes are on the way. We&rsquo;ve emailed them to <span class="lg-gift-success__email" data-lg-gift-success-email>your inbox</span>.
                        It can take a minute or two to land.
                    </p>
                    <div class="lg-gift-success__actions">
                        <?php if ( is_user_logged_in() ): ?>
                            <a class="lg-gift-success__btn lg-gift-success__btn--primary" href="<?php echo esc_url( home_url( '/my-gifts/' ) ); ?>">View my gifts</a>
                            <button type="button" class="lg-gift-success__btn lg-gift-success__btn--ghost" data-lg-gift-success-close>Close</button>
                        <?php else: ?>
                            <button type="button" class="lg-gift-success__btn lg-gift-success__btn--primary" data-lg-gift-success-close>Done</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

                <div class="lg-anonwarn" data-lg-anonwarn-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-anonwarn-title">
                    <div class="lg-anonwarn__backdrop" data-lg-anonwarn-cancel></div>
                    <div class="lg-anonwarn__card">
                        <button type="button" class="lg-anonwarn__close" data-lg-anonwarn-cancel aria-label="Close">&times;</button>
                        <h3 id="lg-anonwarn-title" class="lg-anonwarn__title">Heads up &mdash; checking out without an account</h3>
                        <p class="lg-anonwarn__body">
                            You&rsquo;ll get the codes via email, but <strong>if you ever lose access to that email we won&rsquo;t be able to recover or refund the codes for you.</strong>
                            With a free account you also get a dashboard to resend, void, or refund codes any time.
                        </p>
                        <label class="lg-anonwarn__check">
                            <input type="checkbox" data-lg-anonwarn-confirm-check>
                            <span>I understand and want to proceed without an account.</span>
                        </label>
                        <div class="lg-anonwarn__actions">
                            <button type="button" class="lg-anonwarn__btn lg-anonwarn__btn--ghost"   data-lg-anonwarn-cancel>Go back</button>
                            <button type="button" class="lg-anonwarn__btn lg-anonwarn__btn--primary" data-lg-anonwarn-confirm disabled>Continue to payment</button>
                        </div>
                    </div>
                </div>

                <div data-lg-buyer-email-section>
                    <h3 class="lg-gift__panel-heading">5. Your email <span style="font-weight:400;font-size:.85em;color:rgba(0,0,0,0.5);" data-lg-mode-label>(codes will be sent here)</span></h3>
                    <div class="lg-gift__field">
                        <input type="email" name="email" value="<?php echo $email; ?>" placeholder="you@example.com">
                        <input type="email" name="email_confirm" value="<?php echo $email; ?>" placeholder="Confirm email" style="margin-top:.5em;">
                        <small data-lg-buyer-email-mismatch class="lg-pwd-mismatch" hidden>Emails don&rsquo;t match.</small>
                        <small data-lg-mode-help>We send all codes to this address. You forward / share them yourself.</small>
                    </div>
                </div>

            </div>

            <div class="lg-gift__summary" data-lg-gift-summary>
                <div class="lg-gift__summary-row">
                    <span class="lg-gift__summary-label">Subtotal</span>
                    <span data-lg-gift-line-sub>—</span>
                </div>
                <div class="lg-gift__summary-row lg-gift__summary-row--disc" data-lg-gift-line-disc-row hidden>
                    <span class="lg-gift__summary-label">Bulk discount</span>
                    <span data-lg-gift-line-disc>—</span>
                </div>
                <div class="lg-gift__summary-row lg-gift__summary-row--total">
                    <span class="lg-gift__summary-label">Total</span>
                    <span data-lg-gift-line-total>—</span>
                </div>
                <p class="lg-gift__savings" data-lg-gift-savings hidden></p>
            </div>

            <button type="button" class="lg-gift__submit" data-lg-gift-submit>
                <span data-lg-gift-cta>Continue to checkout</span>
            </button>

            <p class="lg-gift__guarantee">
                <span aria-hidden="true">🎁</span> Codes never expire · billed in USD by Stripe · 30-day refund window
            </p>

            <div class="lg-gift__error" data-lg-gift-error aria-live="polite"></div>
        </div>

        <style>
            .lg-gift { max-width: 720px; margin: 0 auto; padding: 1.5em 1.2em; box-sizing: border-box; }
            .lg-gift * { box-sizing: border-box; }
            .lg-gift [hidden] { display: none !important; }
            .lg-gift__hero { text-align: center; margin-bottom: 1.6em; }
            .lg-gift__heading { margin: 0 0 .3em; font-size: 1.8em; }
            .lg-gift__intro { margin: 0 0 .9em; opacity: .85; }
            .lg-gift__perks { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: .4em 1.2em; justify-content: center; font-size: 0.95em; opacity: .8; }
            .lg-gift__perks li { white-space: nowrap; }

            .lg-gift__panel { border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; padding: 1.2em 1.4em; background: rgba(255,255,255,0.4); }
            .lg-gift__panel-heading { margin: 1em 0 .55em; font-size: 1.05em; font-weight: 600; }
            .lg-gift__panel-heading:first-child { margin-top: 0; }

            .lg-gift__tier-dropdown { position: relative; }
            .lg-gift__tier-trigger { width: 100%; display: flex; justify-content: space-between; align-items: flex-start; gap: .6em; padding: .8em 1em; font-size: 1em; border: 1px solid rgba(0,0,0,0.2); border-radius: 8px; background: #fff; color: inherit; cursor: pointer; text-align: left; box-sizing: border-box; }
            .lg-gift__tier-trigger:focus { outline: 2px solid var(--lg-amber, #ECB351); outline-offset: 1px; }
            .lg-gift__tier-trigger-text { flex: 1; white-space: normal; line-height: 1.45; word-break: break-word; }
            .lg-gift__tier-chevron { flex-shrink: 0; opacity: .55; font-size: .85em; transition: transform .15s; padding-top: .15em; }
            .lg-gift__tier-trigger[aria-expanded="true"] .lg-gift__tier-chevron { transform: rotate(180deg); }
            .lg-gift__tier-options { position: absolute; z-index: 200; left: 0; right: 0; top: calc(100% + 4px); background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.13); overflow: hidden; }
            .lg-gift__tier-options[hidden] { display: none; }
            .lg-gift__tier-option { padding: .8em 1em; cursor: pointer; line-height: 1.45; white-space: normal; }
            .lg-gift__tier-option:hover { background: rgba(0,0,0,0.04); }
            .lg-gift__tier-option.is-selected { background: rgba(236,179,81,0.12); font-weight: 600; }

            .lg-gift__durations { display: flex; flex-direction: column; gap: 0; border: 1px solid rgba(0,0,0,0.12); border-radius: 10px; overflow: hidden; margin-top: .2em; }
            .lg-gift__dur-row { display: flex; align-items: center; gap: .85em; padding: .85em 1.1em; cursor: pointer; background: #fff; border-bottom: 1px solid rgba(0,0,0,0.08); transition: background .12s; }
            .lg-gift__dur-row:last-child { border-bottom: none; }
            .lg-gift__dur-row:hover { background: rgba(0,0,0,0.025); }
            .lg-gift__dur-row.is-selected { background: rgba(236,179,81,0.10); }
            .lg-gift__dur-row input[type="radio"] { accent-color: var(--lg-amber, #ECB351); width: 1.1em; height: 1.1em; flex-shrink: 0; margin: 0; cursor: pointer; }
            .lg-gift__dur-label { font-weight: 500; flex-shrink: 0; }
            .lg-gift__dur-stepper { display: inline-flex; align-items: stretch; border: 1px solid rgba(0,0,0,0.18); border-radius: 6px; overflow: hidden; margin-left: .65em; flex-shrink: 0; }
            .lg-gift__dur-step { width: 32px; background: rgba(0,0,0,0.04); border: none; cursor: pointer; font-size: 1.1em; line-height: 1; color: inherit; padding: 0; flex-shrink: 0; }
            .lg-gift__dur-step:hover { background: rgba(0,0,0,0.09); }
            .lg-gift__dur-custom-input { width: 46px; text-align: center; border: none; border-left: 1px solid rgba(0,0,0,0.1); border-right: 1px solid rgba(0,0,0,0.1); font-size: .95em; padding: .3em 0; background: #fff; color: inherit; -moz-appearance: textfield; }
            .lg-gift__dur-custom-input::-webkit-outer-spin-button, .lg-gift__dur-custom-input::-webkit-inner-spin-button { -webkit-appearance: none; }
            .lg-gift__dur-price { color: var(--lg-sage, #87986A); font-weight: 600; font-size: .95em; flex-shrink: 0; flex: 1; text-align: right; }

            .lg-gift__quantity-row { display: inline-flex; align-items: stretch; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; overflow: hidden; }
            .lg-gift__qbtn { width: 44px; background: rgba(0,0,0,0.04); border: none; cursor: pointer; font-size: 1.3em; line-height: 1; color: inherit; }
            .lg-gift__qbtn:hover { background: rgba(0,0,0,0.08); }
            .lg-gift__qinput { width: 88px; text-align: center; border: none; border-left: 1px solid rgba(0,0,0,0.1); border-right: 1px solid rgba(0,0,0,0.1); font-size: 1.05em; padding: .5em 0; background: #fff; color: inherit; -moz-appearance: textfield; }
            .lg-gift__qinput::-webkit-outer-spin-button, .lg-gift__qinput::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

            .lg-gift__presets { display: flex; flex-wrap: wrap; gap: .45em; margin-top: .8em; }
            .lg-gift__preset { padding: .35em .85em; border-radius: 999px; border: 1px solid rgba(0,0,0,0.15); background: #fff; cursor: pointer; font-size: .9em; color: inherit; transition: all .15s; }
            .lg-gift__preset:hover { border-color: rgba(0,0,0,0.4); }
            .lg-gift__preset.is-active { background: var(--lg-amber, #ECB351); border-color: transparent; color: #1f1d1a; font-weight: 600; }
            .lg-gift__preset-disc { display: inline-block; margin-left: .35em; opacity: .85; font-size: .85em; }
            .lg-gift__preset.is-active .lg-gift__preset-disc { opacity: 1; }

            .lg-gift__progress { margin-top: 1em; }
            .lg-gift__progress-track { height: 8px; background: rgba(0,0,0,0.08); border-radius: 999px; overflow: hidden; }
            .lg-gift__progress-fill { height: 100%; background: linear-gradient(90deg, #87986A, #ECB351); transition: width .25s; }
            .lg-gift__progress-label { font-size: .9em; margin: .5em 0 0; opacity: .85; }

            .lg-gift__field { margin-top: .4em; }
            .lg-gift__field input[type="email"] { width: 100%; padding: .65em .85em; font-size: 1em; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; box-sizing: border-box; }
            .lg-gift__field small { display: block; opacity: .7; font-size: .85em; margin-top: .35em; }

            .lg-gift__summary { margin: 1.4em 0; padding: 1em 1.2em; border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; background: rgba(135,152,106,0.06); }
            .lg-gift__summary-row { display: flex; justify-content: space-between; align-items: baseline; padding: .25em 0; }
            .lg-gift__summary-row--disc { color: #15803d; font-weight: 500; }
            .lg-gift__summary-row--total { border-top: 1px solid rgba(0,0,0,0.1); margin-top: .35em; padding-top: .65em; font-size: 1.15em; font-weight: 700; }
            .lg-gift__summary-label { opacity: .8; }
            .lg-gift__savings { margin: .5em 0 0; font-size: .9em; color: #15803d; font-weight: 500; }

            .lg-gift__submit { display: block; width: 100%; padding: .9em 1.2em; font-size: 1.05em; font-weight: 600; cursor: pointer; background: var(--lg-amber, #ECB351); color: #1f1d1a; border: none; border-radius: 8px; transition: filter .15s; }
            .lg-gift__submit:hover { filter: brightness(0.95); }
            .lg-gift__submit:disabled { opacity: 0.6; cursor: progress; }

            .lg-gift__guarantee { text-align: center; opacity: .65; font-size: .85em; margin-top: .8em; }
            .lg-gift__error { color: #b00; margin-top: .8em; min-height: 1em; }
            .lg-gift__error:empty { display: none; }
            .lg-gift__checkout { margin-top: 1.6em; }
            .lg-gift__loading { padding: 1em; opacity: .6; text-align: center; grid-column: 1 / -1; }

            /* Send-mode toggle (radio cards) */
            .lg-mode { display: flex; flex-direction: column; gap: 10px; margin-bottom: .4em; }
            .lg-mode__opt { position: relative; display: flex; align-items: flex-start; gap: .85em; padding: 1em 1.1em; border: 2px solid rgba(0,0,0,0.1); border-radius: 8px; cursor: pointer; background: #fff; transition: border-color .15s, box-shadow .15s; }
            .lg-mode__opt:hover { border-color: rgba(0,0,0,0.3); }
            .lg-mode__opt.is-selected { border-color: var(--lg-amber, #ECB351); box-shadow: 0 0 0 3px rgba(236,179,81,0.18); }
            .lg-mode__opt input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
            .lg-mode__radio { flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(0,0,0,0.25); margin-top: .18em; transition: border-color .15s, background .15s; }
            .lg-mode__opt.is-selected .lg-mode__radio { border-color: var(--lg-amber, #ECB351); background: radial-gradient(circle at center, var(--lg-amber,#ECB351) 5px, #fff 5px); }
            .lg-mode__title { font-weight: 600; }
            .lg-mode__badge { display: inline-block; margin-left: .5em; padding: .12em .55em; background: var(--lg-amber, #ECB351); color: #1f1d1a; border-radius: 999px; font-size: .7em; font-weight: 700; letter-spacing: .03em; vertical-align: middle; }
            .lg-mode__sub { font-size: .88em; opacity: .7; margin-top: .2em; }
            .lg-mode__note { font-size: .8em; color: #92400e; margin-top: .35em; font-style: italic; }

            /* Inline auth block (login / register) */
            .lg-auth { margin-top: .85em; padding: 1.1em; background: rgba(250,246,238,0.7); border: 1px solid rgba(236,179,81,0.28); border-radius: 8px; }
            .lg-auth__fields { display: flex; flex-direction: column; gap: .5em; margin-bottom: .7em; }
            .lg-auth__input { width: 100%; padding: .6em .85em; font-size: .95em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; background: #fff; color: inherit; box-sizing: border-box; }
            .lg-auth__check-row { display: flex; align-items: center; gap: .5em; font-size: .88em; margin-bottom: .75em; cursor: pointer; }
            .lg-auth__hint { font-size: .82em; color: rgba(0,0,0,0.55); margin: -.25em 0 .85em; line-height: 1.4; }
            .lg-auth__btn { width: 100%; padding: .65em 1em; background: var(--lg-amber, #ECB351); border: none; border-radius: 6px; font-weight: 600; font-size: .95em; cursor: pointer; color: #1f1d1a; transition: opacity .15s; }
            .lg-auth__btn:hover { opacity: .88; }
            .lg-auth__btn:disabled { opacity: .55; cursor: default; }
            .lg-auth__error { font-size: .88em; color: #b91c1c; margin-top: .6em; }
            .lg-auth__forgot { font-size: .85em; margin-top: .35em; }
            .lg-auth__login-prompt { font-size: .92em; margin: 0 0 .8em; color: #444; }
            .lg-auth__login-prompt a { font-weight: 600; }

            /* Inline login modal card — sits inside the shared .lg-pay-modal
               overlay, but smaller than the checkout card. */
            .lg-login-card { max-width: 420px !important; }
            .lg-login-card__body { padding: 1.8em 1.5em 1.4em; }
            .lg-login-card__title { margin: 0 0 .35em; font-size: 1.2em; font-weight: 700; }
            .lg-login-card__sub { margin: 0 0 1.1em; font-size: .92em; color: #555; }
            .lg-login-card__fields { display: flex; flex-direction: column; gap: .6em; margin-bottom: .8em; }
            .lg-login-card__forgot { margin: .8em 0 0; font-size: .85em; text-align: center; }
            .lg-auth__success { display: flex; align-items: center; gap: .55em; margin-top: .85em; padding: .7em 1em; background: rgba(135,152,106,0.12); border: 1px solid rgba(135,152,106,0.3); border-radius: 8px; font-size: .92em; color: #2d4f2a; }
            .lg-auth__check-icon { font-weight: 700; font-size: 1.15em; }

            /* Recipient repeater */
            .lg-recip { margin-top: .8em; }
            .lg-recip__bulk { display: flex; gap: .5em; margin-bottom: .6em; flex-wrap: wrap; }
            .lg-recip__bulk-btn { padding: .35em .8em; border-radius: 999px; border: 1px solid rgba(0,0,0,0.15); background: #fff; cursor: pointer; font-size: .85em; color: inherit; }
            .lg-recip__bulk-btn:hover { border-color: rgba(0,0,0,0.4); }
            .lg-recip__paste { display: none; width: 100%; min-height: 5em; padding: .65em .85em; font: 14px/1.4 ui-monospace, Menlo, Consolas, monospace; border: 1px solid rgba(0,0,0,0.15); border-radius: 8px; resize: vertical; margin-bottom: .8em; box-sizing: border-box; }
            .lg-recip__paste.is-open { display: block; }
            .lg-recip__paste-help { display: none; font-size: .8em; color: rgba(0,0,0,0.5); margin-top: -.3em; margin-bottom: .8em; }
            .lg-recip__paste-help.is-open { display: block; }
            .lg-recip__apply-all { display: none; gap: .5em; align-items: center; margin: .8em 0 0; padding: .6em .85em; background: rgba(0,0,0,0.03); border-radius: 6px; }
            .lg-recip__apply-all.is-open { display: flex; }
            .lg-recip__apply-all textarea { flex: 1; min-height: 2em; padding: .4em .65em; font-size: .88em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; resize: vertical; font-family: inherit; }
            .lg-recip__apply-all-btn { padding: .4em .8em; font-size: .82em; border-radius: 6px; border: 1px solid var(--lg-sage, #87986A); color: var(--lg-sage, #87986A); background: #fff; cursor: pointer; white-space: nowrap; }
            .lg-recip__list { display: flex; flex-direction: column; gap: .55em; margin-top: .6em; }
            .lg-recip__row { display: grid; grid-template-columns: 36px 1.1fr 1.4fr; gap: 8px; align-items: start; }
            .lg-recip__row--note { grid-template-columns: 36px 1fr; }
            .lg-recip__num { display: flex; align-items: center; padding: .65em 0; font-size: .85em; opacity: .55; font-variant-numeric: tabular-nums; }
            .lg-recip__row input { width: 100%; padding: .55em .75em; font-size: .95em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; background: #fff; color: inherit; box-sizing: border-box; }
            .lg-recip__row textarea { width: 100%; min-height: 2.4em; padding: .5em .75em; font-size: .88em; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; resize: vertical; font-family: inherit; box-sizing: border-box; }
            .lg-recip__add-note { background: none; border: none; color: var(--lg-sage, #87986A); font-size: .82em; padding: .25em 0; cursor: pointer; text-align: left; }
            .lg-recip__add-note:hover { text-decoration: underline; }
            .lg-recip__status { margin-top: .8em; padding: .6em .9em; border-radius: 6px; font-size: .9em; }
            .lg-recip__status.is-ok { background: rgba(135,152,106,0.12); color: #2d4f2a; }
            .lg-recip__status.is-warn { background: rgba(255,200,80,0.18); color: #92400e; }

            /* Logged-in: stripped UI — kill the mode toggle, recipient repeater,
               and buyer-email field. Codes attach to their account and they
               manage them at /my-gifts/ after purchase. */
            .lg-gift__loggedin-banner { max-width: 720px; margin: 0 auto 1em; padding: .65em 1em; background: rgba(135,152,106,0.12); border: 1px solid rgba(135,152,106,0.3); border-radius: 8px; font-size: .92em; }
            .lg-gift__loggedin-banner a { color: var(--lg-sage, #87986A); font-weight: 600; }
            .lg-gift--logged-in [data-lg-mode-section],
            .lg-gift--logged-in [data-lg-buyer-email-section] { display: none !important; }

            /* Hide step 4 buyer-email when "Log in to manage" mode is active —
               we already have the user's email from their account. */
            .lg-gift--mode-managed [data-lg-buyer-email-section] { display: none !important; }

            /* Consent modal */
            .lg-consent { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
            .lg-consent[hidden] { display: none !important; }
            .lg-consent__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
            .lg-consent__close:hover { color: #000; background: #f5f5f5; }
            .lg-consent__card { position: relative; }
            .lg-consent__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.45); }
            .lg-consent__card { position: relative; max-width: 420px; width: 100%; padding: 1.6em 1.5em; background: #fff; border-radius: 12px; box-shadow: 0 12px 40px rgba(0,0,0,0.3); color: #1f1d1a; }
            .lg-consent__title { margin: 0 0 .55em; font-size: 1.25em; font-weight: 700; }
            .lg-consent__body { margin: 0 0 1em; font-size: .95em; line-height: 1.5; color: #333; }
            .lg-consent__check { display: flex; align-items: flex-start; gap: .55em; padding: .75em .9em; margin-bottom: 1.1em; background: rgba(236,179,81,0.08); border: 1px solid rgba(236,179,81,0.3); border-radius: 6px; cursor: pointer; font-size: .9em; line-height: 1.4; }
            .lg-consent__check input { margin-top: .15em; flex-shrink: 0; }
            .lg-consent__actions { display: flex; gap: .65em; justify-content: flex-end; }
            .lg-consent__btn { padding: .6em 1.15em; border-radius: 6px; font-weight: 600; font-size: .92em; cursor: pointer; border: none; transition: opacity .15s, background .15s; }
            .lg-consent__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a; }
            .lg-consent__btn--ghost:hover { background: rgba(0,0,0,0.04); }
            .lg-consent__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; }
            .lg-consent__btn--primary:hover { opacity: .88; }
            .lg-consent__btn:disabled { opacity: .55; cursor: default; }
            .lg-consent__error { margin: .9em 0 0; font-size: .88em; color: #b91c1c; }

            /* Redirect overlay shown immediately on first checkout click —
               blocks the page so a frantic second click can't fire a second
               Stripe session before the embedded form is ready. */
            .lg-redirect { position: fixed !important; inset: 0 !important; z-index: 2147483647 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; background: rgba(0,0,0,0.55) !important; }
            .lg-redirect[hidden] { display: none !important; }
            .lg-redirect__card { background: #fff; padding: 1.8em 2em; border-radius: 12px; max-width: 380px; text-align: center; box-shadow: 0 16px 50px rgba(0,0,0,0.35); color: #1f1d1a; }
            .lg-redirect__spinner { width: 38px; height: 38px; margin: 0 auto 1em; border: 3px solid rgba(0,0,0,0.1); border-top-color: var(--lg-amber, #ECB351); border-radius: 50%; animation: lg-redirect-spin .85s linear infinite; }
            @keyframes lg-redirect-spin { to { transform: rotate(360deg); } }
            .lg-redirect__title { font-weight: 600; font-size: 1.05em; margin-bottom: .25em; }
            .lg-redirect__sub { font-size: .88em; color: #555; line-height: 1.4; }
            .lg-gift--checkout-locked .lg-gift__panel { pointer-events: none; opacity: .6; }
            .lg-gift--checkout-locked .lg-gift__submit { pointer-events: none; opacity: .55; cursor: not-allowed; }

            /* Pay modal styles (.lg-pay-modal* + .lg-stripe-modal* + .lg-modal-processing*)
               are defined in assets/lg-shortcodes.css — single source of truth shared
               with [lg_join]. */

            /* Anonymous-checkout warning modal — same pattern as the consent
               modal so it escapes BuddyBoss containing-block traps. */
            .lg-anonwarn { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
            .lg-anonwarn[hidden] { display: none !important; }
            .lg-anonwarn__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
            .lg-anonwarn__card { position: relative; background: #fff; border-radius: 12px; padding: 1.6em 1.5em; max-width: 460px; width: 100%; box-shadow: 0 16px 50px rgba(0,0,0,0.4); color: #1f1d1a; }
            .lg-anonwarn__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
            .lg-anonwarn__close:hover { color: #000; background: #f5f5f5; }
            .lg-anonwarn__title { margin: 0 0 .55em; font-size: 1.2em; font-weight: 700; line-height: 1.3; padding-right: 2em; }
            .lg-anonwarn__body { margin: 0 0 1em; font-size: .95em; line-height: 1.5; color: #333; }
            .lg-anonwarn__check { display: flex; align-items: flex-start; gap: .6em; padding: .8em .9em; margin-bottom: 1.1em; background: rgba(255,200,80,0.14); border: 1px solid rgba(255,180,40,0.45); border-radius: 6px; cursor: pointer; font-size: .92em; line-height: 1.4; font-weight: 500; }
            .lg-anonwarn__check input { margin-top: .15em; flex-shrink: 0; }
            .lg-anonwarn__actions { display: flex; gap: .65em; justify-content: flex-end; }
            .lg-anonwarn__btn { padding: .6em 1.15em; border-radius: 6px; font-weight: 600; font-size: .92em; cursor: pointer; border: none; transition: opacity .15s, background .15s; }
            .lg-anonwarn__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a; }
            .lg-anonwarn__btn--ghost:hover { background: rgba(0,0,0,0.04); }
            .lg-anonwarn__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; }
            .lg-anonwarn__btn--primary:hover { opacity: .88; }
            .lg-anonwarn__btn:disabled { opacity: .45; cursor: not-allowed; }

            /* Same body-class lockdown so the redirect overlay and consent
               modal also escape any transformed ancestors. */
            .lg-redirect, .lg-consent { z-index: 2147483600 !important; }
            body.lg-modal-open { overflow: hidden !important; }
        </style>

        <div class="lg-existacct" data-lg-existacct-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-existacct-title">
            <div class="lg-existacct__backdrop" data-lg-existacct-cancel></div>
            <div class="lg-existacct__card">
                <button type="button" class="lg-existacct__close" data-lg-existacct-cancel aria-label="Close">&times;</button>
                <h3 id="lg-existacct-title" class="lg-existacct__title">This email already has an account</h3>
                <p class="lg-existacct__body" data-lg-existacct-body></p>
                <div class="lg-existacct__actions">
                    <a class="lg-existacct__btn lg-existacct__btn--primary" data-lg-existacct-login href="#">Log in &amp; manage subscription</a>
                    <a class="lg-existacct__btn lg-existacct__btn--ghost"   data-lg-existacct-forgot hidden href="#">Forgot your password?</a>
                </div>
                <p class="lg-existacct__alt"><a href="#" data-lg-existacct-cancel>Use a different email instead</a></p>
            </div>
        </div>
        <style>
            .lg-existacct { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
            .lg-existacct[hidden] { display: none !important; }
            .lg-existacct__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
            .lg-existacct__card { position: relative; background: #fff; border-radius: 12px; padding: 1.7em 1.6em; max-width: 460px; width: 100%; box-shadow: 0 16px 50px rgba(0,0,0,0.4); color: #1f1d1a; }
            .lg-existacct__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
            .lg-existacct__close:hover { color: #000; background: #f5f5f5; }
            .lg-existacct__title { margin: 0 0 .55em; font-size: 1.2em; font-weight: 700; line-height: 1.3; padding-right: 2em; }
            .lg-existacct__body { margin: 0 0 1.2em; font-size: .95em; line-height: 1.5; color: #333; }
            .lg-existacct__actions { display: flex; gap: .65em; flex-wrap: wrap; }
            .lg-existacct__btn { padding: .65em 1.2em; border-radius: 6px; font-weight: 600; font-size: .92em; text-decoration: none; display: inline-block; cursor: pointer; }
            .lg-existacct__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a !important; }
            .lg-existacct__btn--primary:hover { opacity: .88; }
            .lg-existacct__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a !important; }
            .lg-existacct__btn--ghost:hover { background: rgba(0,0,0,0.04); }
            .lg-existacct__alt { margin: 1em 0 0; font-size: .85em; color: #666; }
            .lg-existacct__alt a { color: var(--lg-sage, #87986A); }

            .lg-pwd-wrap { position: relative; display: block; }
            .lg-pwd-wrap input { width: 100%; padding-right: 2.6em !important; }
            .lg-pwd-eye { position: absolute; right: .35em; top: 50%; transform: translateY(-50%); width: 2em; height: 2em; padding: 0; background: transparent; border: none; cursor: pointer; opacity: .55; font-size: 1.05em; line-height: 1; display: flex; align-items: center; justify-content: center; }
            .lg-pwd-eye:hover { opacity: .9; }
            .lg-pwd-mismatch { color: #b91c1c !important; font-size: .85em; margin-top: .3em; }

                /* Post-payment processing lock — shown when Stripe fires onComplete.
                   Sits above everything (max z-index) so the user cannot click into
                   nav, close the tab silently (beforeunload warns), or otherwise
                   navigate away while the post-pay redirect to /welcome/ is in flight. */
                .lg-processing { position: fixed !important; inset: 0 !important; z-index: 2147483647 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
                .lg-processing[hidden] { display: none !important; }
                .lg-processing__card { background: #fff; border-radius: 12px; padding: 2em 1.8em; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,0.5); color: #1f1d1a; }
                .lg-processing__spinner { width: 44px; height: 44px; margin: 0 auto 1em; border: 4px solid rgba(0,0,0,0.1); border-top-color: var(--lg-amber, #ECB351); border-radius: 50%; animation: lg-processing-spin 0.9s linear infinite; }
                .lg-processing__title { margin: 0 0 .6em; font-size: 1.15em; font-weight: 700; }
                .lg-processing__body  { margin: 0; font-size: .92em; line-height: 1.5; color: #444; }
                @keyframes lg-processing-spin { to { transform: rotate(360deg); } }
                /* Pay-modal lock styles live in assets/lg-shortcodes.css under
                   .lg-pay-modal[data-lg-locked="1"]. */
        </style>
        <!-- Basil release of Stripe.js — required for stripe.initCheckout()
             (custom UI mode). Must match the API-version pin on the Slim side. -->
        <script src="https://js.stripe.com/basil/stripe.js"></script>
        <script>
        (function(){
            function lgGetRef() { try { return localStorage.getItem('lg_ref') || ''; } catch(_) { return ''; } }

            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const CONFIG    = <?php echo $configJs; ?>;

            // Capture ?ref= affiliate slug on landing, persist across sessions,
            // and fire a server-side click event so misses are visible in reports.
            (function() {
                try {
                    var r = new URLSearchParams(window.location.search).get('ref');
                    if (r) {
                        r = r.replace(/[^a-z0-9_-]/gi, '').slice(0, 80);
                        localStorage.setItem('lg_ref', r);
                        fetch(ENDPOINTS.affiliateClick, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ ref: r }),
                            keepalive: true,
                        }).catch(function(){});
                    }
                } catch(_) {}
            })();
            const tierDropdown    = document.querySelector('[data-lg-tier-dropdown]');
            const tierTrigger    = document.querySelector('[data-lg-tier-trigger]');
            const tierOptionsEl  = document.querySelector('[data-lg-tier-options]');
            const tierSelectedEl = document.querySelector('[data-lg-tier-selected]');
            const durationsEl = document.querySelector('[data-lg-gift-durations]');
            const presetsEl   = document.querySelector('[data-lg-gift-presets]');
            const progressEl  = document.querySelector('[data-lg-gift-progress]');
            const progressFill= document.querySelector('[data-lg-gift-progress-fill]');
            const progressLab = document.querySelector('[data-lg-gift-progress-label]');
            const summarySub  = document.querySelector('[data-lg-gift-line-sub]');
            const summaryDiscRow = document.querySelector('[data-lg-gift-line-disc-row]');
            const summaryDisc = document.querySelector('[data-lg-gift-line-disc]');
            const summaryTot  = document.querySelector('[data-lg-gift-line-total]');
            const savingsEl   = document.querySelector('[data-lg-gift-savings]');
            const submitBtn   = document.querySelector('[data-lg-gift-submit]');
            const ctaSpan     = document.querySelector('[data-lg-gift-cta]');
            const errorEl     = document.querySelector('[data-lg-gift-error]');
            const checkoutEl  = document.querySelector('[data-lg-gift-checkout]');
            const giftCustomEl   = document.querySelector('[data-lg-gift-checkout-custom]');
            const giftPeEl       = document.querySelector('[data-lg-gift-payment-element]');
            const giftPayBt      = document.querySelector('[data-lg-gift-checkout-pay]');
            const giftPayLabelEl = document.querySelector('[data-lg-gift-pay-label]');
            const giftCustomErrorEl = document.querySelector('[data-lg-gift-checkout-error]');
            const giftModalProcessingEl = document.querySelector('[data-lg-gift-modal-processing]');
            const giftPayAmountEl   = document.querySelector('[data-lg-gift-pay-amount]');
            const giftPaySublabelEl = document.querySelector('[data-lg-gift-pay-sublabel]');
            const qtyInput    = document.querySelector('input[name="quantity"]');
            const emailInput  = document.querySelector('input[name="email"]');

            let products            = [];
            let bulkTiers          = [];
            let selectedRef        = null;
            // selectedDuration: { months, priceId, baseUnitCents }
            // months=0 means yearly (uses yearly price directly, no multiplication)
            let selectedDuration   = null;
            let customMonthsVal    = 3; // last value in the custom-months input
            let stripe             = null;
            let mountedSession     = null;
            let giftCustomCheckout = null;
            let giftPaymentElement = null;

            const dollars = (cents) => { const n = cents / 100; return '$' + (n % 1 === 0 ? n.toFixed(0) : n.toFixed(2)); };
            const dollarsRound = (cents) => '$' + Math.round(cents / 100);
            function showError(msg){ errorEl.textContent = msg || ''; }

            function selectedTier(){
                return products.find(p => p.ref === selectedRef) || null;
            }
            // Price helpers — derive yearly and monthly prices from a product's prices array.
            const monthlyPrice = (prod) => prod ? (prod.prices.find(p => p.type === 'recurring' && p.interval === 'month') || null) : null;
            const yearlyPrice  = (prod) => prod ? (prod.prices.find(p => p.type === 'recurring' && p.interval === 'year')  || null) : null;

            // Scale bulk discount linearly with duration: 1 month = 1/12, 1 year = 12/12 = full.
            function scaledDiscountPct(qty, months){
                let raw = 0;
                bulkTiers.forEach(t => { if (qty >= t.min_qty && t.discount_pct > raw) raw = t.discount_pct; });
                return Math.round(raw * Math.min(months, 12) / 12);
            }

            function discountPctFor(qty){
                if (!selectedDuration) return 0;
                return scaledDiscountPct(qty, selectedDuration.months || 12);
            }

            function nextTier(qty){
                return bulkTiers.filter(t => qty < t.min_qty).sort((a,b) => a.min_qty - b.min_qty)[0] || null;
            }

            // Custom dropdown: populate options and wire interactions.
            function renderTiers(){
                if (!tierOptionsEl) return;
                tierOptionsEl.innerHTML = '';
                const eligible = products.filter(p => monthlyPrice(p) || yearlyPrice(p));
                eligible.forEach(function(prod){
                    const mp = monthlyPrice(prod);
                    const yp = yearlyPrice(prod);
                    let label = prod.name;
                    if (mp && yp) label += ' — ' + dollars(mp.unit_amount_cents) + '/mo · ' + dollars(yp.unit_amount_cents) + '/yr';
                    else if (mp)  label += ' — ' + dollars(mp.unit_amount_cents) + '/mo';
                    const opt = document.createElement('div');
                    opt.className = 'lg-gift__tier-option';
                    opt.setAttribute('role', 'option');
                    opt.dataset.ref = prod.ref;
                    opt.textContent = label;
                    opt.addEventListener('click', () => { selectTier(prod.ref); closeTierDropdown(); });
                    tierOptionsEl.appendChild(opt);
                });
                // Toggle open/close on trigger click
                if (tierTrigger) {
                    tierTrigger.addEventListener('click', () => {
                        const isOpen = tierTrigger.getAttribute('aria-expanded') === 'true';
                        isOpen ? closeTierDropdown() : openTierDropdown();
                    });
                }
                // Close on outside click
                document.addEventListener('click', function(e){
                    if (tierDropdown && !tierDropdown.contains(e.target)) closeTierDropdown();
                });
                // Default: popular tier or first
                const def = eligible.find(p => p.ref === CONFIG.popular) || eligible[0];
                if (def) selectTier(def.ref);
            }

            function openTierDropdown(){
                if (tierOptionsEl) tierOptionsEl.hidden = false;
                if (tierTrigger)   tierTrigger.setAttribute('aria-expanded', 'true');
            }
            function closeTierDropdown(){
                if (tierOptionsEl) tierOptionsEl.hidden = true;
                if (tierTrigger)   tierTrigger.setAttribute('aria-expanded', 'false');
            }

            function selectTier(ref){
                selectedRef = ref;
                // Update trigger label
                const prod = selectedTier();
                if (tierSelectedEl && prod) {
                    const mp = monthlyPrice(prod);
                    const yp = yearlyPrice(prod);
                    let label = prod.name;
                    if (mp && yp) label += ' — ' + dollars(mp.unit_amount_cents) + '/mo · ' + dollars(yp.unit_amount_cents) + '/yr';
                    else if (mp)  label += ' — ' + dollars(mp.unit_amount_cents) + '/mo';
                    tierSelectedEl.textContent = label;
                }
                // Highlight selected option
                if (tierOptionsEl) {
                    tierOptionsEl.querySelectorAll('.lg-gift__tier-option').forEach(el => {
                        el.classList.toggle('is-selected', el.dataset.ref === ref);
                    });
                }
                renderDurations(prod);
                selectDuration('year');
            }

            // Build the three duration rows: 1 year / 1 month / custom N months.
            function renderDurations(prod){
                if (!durationsEl || !prod) return;
                const mp = monthlyPrice(prod);
                const yp = yearlyPrice(prod);

                durationsEl.innerHTML = '';

                function makeRow(key, labelText, priceText, extraContent){
                    const row = document.createElement('label');
                    row.className = 'lg-gift__dur-row';
                    row.dataset.durKey = key;
                    const radio = document.createElement('input');
                    radio.type = 'radio'; radio.name = 'duration'; radio.value = key;
                    row.appendChild(radio);
                    const lbl = document.createElement('span');
                    lbl.className = 'lg-gift__dur-label';
                    lbl.textContent = labelText;
                    row.appendChild(lbl);
                    if (extraContent) row.appendChild(extraContent);
                    const pr = document.createElement('span');
                    pr.className = 'lg-gift__dur-price';
                    pr.dataset.durPriceKey = key;
                    pr.textContent = priceText;
                    row.appendChild(pr);
                    row.addEventListener('click', function(e){
                        if (e.target.tagName === 'INPUT' && e.target.type === 'number') return;
                        radio.checked = true;
                        selectDuration(key);
                    });
                    radio.addEventListener('change', () => selectDuration(key));
                    return row;
                }

                // 1-year row (uses yearly price directly)
                if (yp) {
                    durationsEl.appendChild(makeRow('year', '1 year', dollars(yp.unit_amount_cents) + ' / gift'));
                }

                // 1-month row
                if (mp) {
                    durationsEl.appendChild(makeRow('month', '1 month', dollars(mp.unit_amount_cents) + ' / gift'));
                }

                // Custom months row — stepper (− N +), price updates live
                if (mp) {
                    const stepper = document.createElement('div');
                    stepper.className = 'lg-gift__dur-stepper';

                    const decBtn = document.createElement('button');
                    decBtn.type = 'button'; decBtn.className = 'lg-gift__dur-step'; decBtn.textContent = '−';
                    decBtn.setAttribute('aria-label', 'Decrease months');

                    const numInput = document.createElement('input');
                    numInput.type = 'number'; numInput.min = '2'; numInput.max = '36';
                    numInput.value = String(customMonthsVal);
                    numInput.className = 'lg-gift__dur-custom-input';

                    const incBtn = document.createElement('button');
                    incBtn.type = 'button'; incBtn.className = 'lg-gift__dur-step'; incBtn.textContent = '+';
                    incBtn.setAttribute('aria-label', 'Increase months');

                    function updateCustomMonths(val){
                        customMonthsVal = Math.max(2, Math.min(36, val));
                        numInput.value = String(customMonthsVal);
                        const pr = durationsEl.querySelector('[data-dur-price-key="custom"]');
                        if (pr) pr.textContent = dollars(mp.unit_amount_cents * customMonthsVal) + ' / gift';
                        if (selectedDuration && selectedDuration.key === 'custom') {
                            selectedDuration.months = customMonthsVal;
                            selectedDuration.baseUnitCents = mp.unit_amount_cents * customMonthsVal;
                            selectedDuration.durationMonths = customMonthsVal;
                            renderPresets(); recompute();
                        }
                    }
                    decBtn.addEventListener('click', (e) => { e.stopPropagation(); updateCustomMonths(customMonthsVal - 1); });
                    incBtn.addEventListener('click', (e) => { e.stopPropagation(); updateCustomMonths(customMonthsVal + 1); });
                    numInput.addEventListener('input', function(){ updateCustomMonths(parseInt(this.value, 10) || 2); });

                    stepper.appendChild(decBtn);
                    stepper.appendChild(numInput);
                    stepper.appendChild(incBtn);

                    const customRow = makeRow('custom', 'Custom months', dollars(mp.unit_amount_cents * customMonthsVal) + ' / gift', stepper);
                    durationsEl.appendChild(customRow);
                }
            }

            function selectDuration(key){
                const prod  = selectedTier();
                const mp    = monthlyPrice(prod);
                const yp    = yearlyPrice(prod);

                if (key === 'year' && yp) {
                    selectedDuration = { key: 'year', months: 12, priceId: yp.stripe_price_id, baseUnitCents: yp.unit_amount_cents, durationMonths: null };
                } else if (key === 'month' && mp) {
                    selectedDuration = { key: 'month', months: 1, priceId: mp.stripe_price_id, baseUnitCents: mp.unit_amount_cents, durationMonths: 1 };
                } else if (key === 'custom' && mp) {
                    selectedDuration = { key: 'custom', months: customMonthsVal, priceId: mp.stripe_price_id, baseUnitCents: mp.unit_amount_cents * customMonthsVal, durationMonths: customMonthsVal };
                } else {
                    return;
                }

                if (durationsEl) {
                    durationsEl.querySelectorAll('.lg-gift__dur-row').forEach(el => {
                        const isMe = el.dataset.durKey === key;
                        el.classList.toggle('is-selected', isMe);
                        const r = el.querySelector('input[type="radio"]');
                        if (r) r.checked = isMe;
                    });
                }
                renderPresets();
                recompute();
            }

            function renderPresets(){
                presetsEl.innerHTML = '';
                const months = selectedDuration ? (selectedDuration.months || 12) : 12;
                // Always include 1; then one preset per bulk tier minimum.
                const stops = [1, ...bulkTiers.map(t => t.min_qty)];
                const seen = new Set();
                stops.forEach(function(qty){
                    if (seen.has(qty)) return;
                    seen.add(qty);
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'lg-gift__preset';
                    btn.dataset.qty = String(qty);
                    const bulkTier = bulkTiers.find(t => t.min_qty === qty);
                    const scaledPct = bulkTier ? scaledDiscountPct(qty, months) : 0;
                    btn.innerHTML = qty + (scaledPct > 0 ? ' <span class="lg-gift__preset-disc">' + scaledPct + '% off</span>' : '');
                    btn.addEventListener('click', () => { qtyInput.value = String(qty); recompute(); });
                    presetsEl.appendChild(btn);
                });
            }

            function highlightPreset(qty){
                presetsEl.querySelectorAll('.lg-gift__preset').forEach(b => {
                    b.classList.toggle('is-active', parseInt(b.dataset.qty, 10) === qty);
                });
            }

            function renderProgress(qty){
                if (progressEl) progressEl.hidden = true;
            }

            function recompute(){
                const dur = selectedDuration;
                const qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                qtyInput.value = String(qty);
                highlightPreset(qty);
                if (!dur) {
                    summarySub.textContent  = '—';
                    summaryTot.textContent  = '—';
                    summaryDiscRow.hidden   = true;
                    savingsEl.hidden        = true;
                    ctaSpan.textContent     = 'Continue to checkout';
                    return;
                }
                const durLabel   = dur.key === 'year' ? '1 year' : dur.key === 'month' ? '1 month' : dur.months + ' months';
                const subCents   = dur.baseUnitCents * qty;
                const pct        = discountPctFor(qty);
                const discCents  = Math.round(subCents * pct / 100);
                const totalCents = subCents - discCents;

                summarySub.textContent = qty + ' × ' + dollars(dur.baseUnitCents) + ' (' + durLabel + ') = ' + dollars(subCents);
                if (pct > 0) {
                    summaryDiscRow.hidden = false;
                    summaryDisc.textContent = '−' + dollars(discCents) + '  (' + pct + '% off)';
                    savingsEl.hidden = false;
                    // Dynamic "next tier" nudge
                    const next = nextTier(qty);
                    if (next) {
                        const nextPct = scaledDiscountPct(next.min_qty, dur.months || 12);
                        const need = next.min_qty - qty;
                        savingsEl.textContent = "You're saving " + dollarsRound(discCents) + ' — add ' + need + ' more code' + (need === 1 ? '' : 's') + ' to unlock ' + nextPct + '% off.';
                    } else {
                        savingsEl.textContent = "You're saving " + dollarsRound(discCents) + ' with bulk pricing.';
                    }
                } else {
                    summaryDiscRow.hidden = true;
                    const next = nextTier(qty);
                    if (next) {
                        const nextPct = scaledDiscountPct(next.min_qty, dur.months || 12);
                        savingsEl.hidden = false;
                        savingsEl.textContent = 'Add ' + (next.min_qty - qty) + ' more code' + (next.min_qty - qty === 1 ? '' : 's') + ' to unlock ' + nextPct + '% off.';
                    } else {
                        savingsEl.hidden = true;
                    }
                }
                summaryTot.textContent = dollars(totalCents);
                ctaSpan.textContent = 'Continue to checkout · ' + qty + ' × ' + durLabel + ' · ' + dollars(totalCents);
                renderProgress(qty);
            }

            async function loadProducts(){
                showError('');
                try {
                    const res  = await fetch(ENDPOINTS.products);
                    const json = await res.json();
                    products  = json.products || [];
                    bulkTiers = (json.bulk_discount_tiers || []).slice().sort((a,b) => a.min_qty - b.min_qty);
                    // renderTiers → selectTier → renderDurations → selectDuration → renderPresets + recompute
                    renderTiers();
                    applyResumeState();
                } catch (err) {
                    showError('Failed to load tiers: ' + err.message);
                }
            }

            // Reapply gift-form state captured before a login-modal reload
            // (see serializeGiftState below). Runs after products + tiers
            // are populated so selectTier/selectDuration have something to
            // hook into. Strips the lg_resume params from the URL after
            // applying so a manual refresh doesn't re-apply stale state.
            function applyResumeState(){
                let params;
                try { params = new URLSearchParams(window.location.search); }
                catch (e) { return; }
                if (params.get('lg_resume') !== '1') return;
                const tier = params.get('tier');
                const dur  = params.get('dur');
                const cm   = parseInt(params.get('cm') || '', 10);
                const qty  = parseInt(params.get('qty') || '', 10);
                if (cm && cm >= 2 && cm <= 36) customMonthsVal = cm;
                if (tier && products.some(p => p.ref === tier)) selectTier(tier);
                if (dur === 'year' || dur === 'month' || dur === 'custom') {
                    try { selectDuration(dur); } catch (e) {}
                }
                if (qty && qty >= 1 && qtyInput) {
                    qtyInput.value = String(qty);
                    try { recompute(); } catch (e) {}
                }
                ['lg_resume','tier','dur','cm','qty'].forEach(k => params.delete(k));
                const clean = window.location.pathname + (params.toString() ? '?' + params : '') + window.location.hash;
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, '', clean);
                }
            }

            // Serialize current gift selections into a query string the
            // post-reload page can pick up via applyResumeState. Used by
            // the inline login modal so a returning member doesn't lose
            // their tier/duration/qty when we reload to render the
            // canonical logged-in state.
            window.lgSerializeGiftState = function(){
                const p = new URLSearchParams();
                p.set('lg_resume', '1');
                if (selectedRef) p.set('tier', selectedRef);
                if (selectedDuration && selectedDuration.key) p.set('dur', selectedDuration.key);
                if (customMonthsVal) p.set('cm', String(customMonthsVal));
                if (qtyInput && qtyInput.value) p.set('qty', String(qtyInput.value));
                return p.toString();
            };

            // Quantity stepper buttons
            document.querySelectorAll('[data-lg-qty-step]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const step = parseInt(btn.dataset.lgQtyStep, 10) || 1;
                    qtyInput.value = String(Math.max(1, (parseInt(qtyInput.value, 10) || 1) + step));
                    recompute();
                });
            });
            qtyInput.addEventListener('input', recompute);

            // One-shot guard: once a Stripe checkout is in flight or mounted,
            // refuse to start another. The overlay also blocks pointer events
            // so a panicked double-tap can't slip through before the JS guard
            // updates.
            let checkoutInProgress = false;
            const redirectOverlay = document.querySelector('[data-lg-redirect-overlay]');
            const checkoutModal   = document.querySelector('[data-lg-checkout-modal]');

            // Move modal/overlay elements to <body> so ancestor transforms
            // (BuddyBoss theme uses them) can't trap position:fixed.
            const anonWarnModal = document.querySelector('[data-lg-anonwarn-modal]');
            [redirectOverlay, checkoutModal, document.querySelector('[data-lg-consent-modal]'), anonWarnModal].forEach(el => {
                if (el && el.parentNode !== document.body) document.body.appendChild(el);
            });

            // Default mode is "managed" — apply its UI state on load so
            // anonymous visitors land with the auth panel open and step 4
            // (your email) hidden. Skipped for already-logged-in users
            // since their mode-section is hidden by CSS.
            (function initManagedDefault(){
                if (CONFIG.loggedIn) return;
                const root  = document.querySelector('.lg-gift');
                const block = document.querySelector('[data-lg-auth-block]');
                if (root)  root.classList.add('lg-gift--mode-managed');
                if (block) block.hidden = false;
            })();

            let redirectOverlayShownAt = 0;
            function lockCheckout() {
                checkoutInProgress = true;
                if (redirectOverlay) {
                    // Always re-park as the LAST child of <body> so the theme
                    // can't paint anything above it.
                    if (redirectOverlay.parentNode !== document.body || redirectOverlay !== document.body.lastChild) {
                        document.body.appendChild(redirectOverlay);
                    }
                    redirectOverlay.hidden = false;
                    redirectOverlayShownAt = Date.now();
                }
                document.body.classList.add('lg-modal-open');
                const root = document.querySelector('.lg-gift');
                if (root) root.classList.add('lg-gift--checkout-locked');
                submitBtn.disabled = true;
            }
            function hideRedirectOverlaySoon() {
                if (!redirectOverlay) return;
                const elapsed = Date.now() - redirectOverlayShownAt;
                const remain  = Math.max(0, 700 - elapsed);
                setTimeout(() => { redirectOverlay.hidden = true; }, remain);
            }
            function unlockCheckout() {
                checkoutInProgress = false;
                if (redirectOverlay) redirectOverlay.hidden = true;
                if (checkoutModal && checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
                const root = document.querySelector('.lg-gift');
                if (root) root.classList.remove('lg-gift--checkout-locked');
                submitBtn.disabled = false;
            }
            function showGiftCheckoutError(msg) {
                if (!giftCustomErrorEl) return;
                if (msg) {
                    giftCustomErrorEl.textContent = msg;
                    giftCustomErrorEl.hidden = false;
                } else {
                    giftCustomErrorEl.textContent = '';
                    giftCustomErrorEl.hidden = true;
                }
            }

            function teardownGiftMount() {
                if (mountedSession) {
                    try { mountedSession.destroy(); } catch (_) {}
                    mountedSession = null;
                }
                if (giftPaymentElement) {
                    try { giftPaymentElement.unmount(); } catch (_) {}
                    giftPaymentElement = null;
                }
                giftCustomCheckout = null;
                if (checkoutEl) checkoutEl.innerHTML = '';
                if (giftPeEl) giftPeEl.innerHTML = '';
                if (giftCustomEl) giftCustomEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                showGiftCheckoutError('');
                if (giftPayBt) giftPayBt.disabled = true;
            }

            async function mountGiftEmbeddedCheckout(clientSecret) {
                if (giftCustomEl) giftCustomEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                mountedSession = await stripe.initEmbeddedCheckout({
                    clientSecret: clientSecret,
                    onComplete: showProcessingOverlay,
                });
                mountedSession.mount(checkoutEl);
            }

            async function mountGiftCustomCheckout(clientSecret) {
                // Same pattern as join: keep custom block hidden behind the
                // processing overlay until paymentElement is 'ready'.
                if (checkoutEl) checkoutEl.hidden = true;
                if (giftCustomEl) giftCustomEl.hidden = true;
                if (giftModalProcessingEl) giftModalProcessingEl.hidden = false;

                giftCustomCheckout = await stripe.initCheckout({
                    fetchClientSecret: async () => clientSecret,
                });

                giftCustomCheckout.on('change', (session) => {
                    if (!giftPayBt) return;
                    giftPayBt.disabled = !session.canConfirm;
                    const cents = session && session.total && session.total.total
                        ? session.total.total.amount
                        : null;
                    const formatted = (typeof cents === 'number' && !isNaN(cents))
                        ? '$' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)
                        : '';
                    if (giftPayLabelEl) giftPayLabelEl.textContent = formatted ? 'Pay ' + formatted : 'Pay';
                    if (giftPayAmountEl) { giftPayAmountEl.textContent = formatted; giftPayAmountEl.hidden = !formatted; }
                    const tier  = selectedTier();
                    const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                    if (giftPaySublabelEl) {
                        const giftSub = tier ? (tier.name + " · " + qty + (qty === 1 ? " gift" : " gifts")) : "";
                        giftPaySublabelEl.textContent = giftSub;
                        giftPaySublabelEl.hidden = !giftSub;
                    }
                });

                giftPaymentElement = giftCustomCheckout.createPaymentElement();
                giftPaymentElement.on('ready', () => {
                    if (giftCustomEl) giftCustomEl.hidden = false;
                    if (giftModalProcessingEl) giftModalProcessingEl.hidden = true;
                });
                giftPaymentElement.mount(giftPeEl);
            }

            async function onGiftPayClick() {
                if (!giftCustomCheckout || !giftPayBt || giftPayBt.disabled) return;
                showGiftCheckoutError('');

                if (checkoutModal) checkoutModal.dataset.lgLocked = '1';

                const origLabel = giftPayLabelEl ? giftPayLabelEl.textContent : '';
                giftPayBt.disabled = true;
                if (giftModalProcessingEl) giftModalProcessingEl.hidden = false;

                try {
                    const result = await giftCustomCheckout.confirm();
                    if (result && result.error) {
                        if (checkoutModal) delete checkoutModal.dataset.lgLocked;
                        if (giftModalProcessingEl) giftModalProcessingEl.hidden = true;
                        showGiftCheckoutError(result.error.message || 'Payment failed. Please try again.');
                        if (giftPayLabelEl) giftPayLabelEl.textContent = origLabel || 'Pay';
                        giftPayBt.disabled = false;
                        return;
                    }

                    // Success — gift codes are delivered by email, so we don't
                    // redirect away. Show an in-page success modal and let the
                    // webhook + /v1/return fast-path provision asynchronously.
                    let sessionId = '';
                    try { sessionId = (giftCustomCheckout.session && giftCustomCheckout.session().id) || ''; } catch (_) {}

                    // Fire-and-forget kick of the idempotent fast-path so emails
                    // go out immediately rather than waiting for the webhook.
                    if (sessionId) {
                        try {
                            fetch('<?php echo esc_js( esc_url_raw( home_url( '/billing/v1/return' ) ) ); ?>?session_id=' + encodeURIComponent(sessionId), {
                                method: 'GET',
                                credentials: 'omit',
                                redirect: 'manual',
                                keepalive: true,
                            }).catch(() => {});
                        } catch (_) {}
                    }

                    teardownGiftMount();
                    if (checkoutModal) {
                        delete checkoutModal.dataset.lgLocked;
                        checkoutModal.hidden = true;
                    }
                    paymentCompleted = true;
                    window.removeEventListener('beforeunload', preventNavWhileProcessing);

                    showGiftSuccessModal(pendingEmail || '');
                } catch (err) {
                    if (checkoutModal) delete checkoutModal.dataset.lgLocked;
                    if (giftModalProcessingEl) giftModalProcessingEl.hidden = true;
                    showGiftCheckoutError('Network error: ' + (err && err.message ? err.message : err));
                    if (giftPayLabelEl) giftPayLabelEl.textContent = origLabel || 'Pay';
                    giftPayBt.disabled = false;
                }
            }
            if (giftPayBt) giftPayBt.addEventListener('click', onGiftPayClick);

            function closeCheckoutModal() {
                if (checkoutModal) checkoutModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
                teardownGiftMount();
                unlockCheckout();
                recompute();
            }

            const giftSuccessModal = document.querySelector('[data-lg-gift-success]');
            const giftSuccessEmailEl = document.querySelector('[data-lg-gift-success-email]');
            if (giftSuccessModal && giftSuccessModal.parentNode !== document.body) {
                document.body.appendChild(giftSuccessModal);
            }
            function showGiftSuccessModal(email) {
                if (giftSuccessEmailEl && email) giftSuccessEmailEl.textContent = email;
                if (giftSuccessModal) giftSuccessModal.hidden = false;
                document.body.classList.add('lg-modal-open');
            }
            document.querySelectorAll('[data-lg-gift-success-close]').forEach(el => {
                el.addEventListener('click', () => {
                    if (giftSuccessModal) giftSuccessModal.hidden = true;
                    document.body.classList.remove('lg-modal-open');
                    paymentCompleted = false;
                    paymentInFlight = false;
                    unlockCheckout();
                    recompute();
                });
            });

            // Two-stage close protection for the Stripe checkout modal:
            //
            //   1. paymentInFlight — set true when the modal opens with a mounted
            //      Stripe iframe. Any close attempt while this is true triggers
            //      a confirm() dialog warning the user that their card may
            //      already be charged (Stripe submits to the bank the instant
            //      they click Pay, before onComplete fires).
            //
            //   2. paymentCompleted — set true when Stripe fires onComplete.
            //      At this point close is suppressed entirely; we show the
            //      processing overlay and let Stripe's redirect to /welcome/
            //      finish the flow. A beforeunload listener also blocks tab
            //      close until the redirect lands.
            //
            // The pre-onComplete confirm() is the critical fix for the bug
            // where users close the modal mid-charge and end up paid but with
            // no account / no redirect / no entitlement.
            let paymentInFlight = false;
            let paymentCompleted = false;
            const processingOverlay = document.querySelector('[data-lg-gift-processing]');
            if (processingOverlay && processingOverlay.parentNode !== document.body) {
                document.body.appendChild(processingOverlay);
            }
            function markPaymentInFlight() {
                paymentInFlight = true;
                window.addEventListener('beforeunload', preventNavWhileProcessing);
            }
            function showProcessingOverlay() {
                paymentCompleted = true;
                if (processingOverlay) processingOverlay.hidden = false;
                document.body.classList.add('lg-modal-open');
                // Stripe is about to navigate parent to return_url — remove the
                // beforeunload guard so the intended redirect sails through
                // without the browser's "Leave site?" prompt.
                window.removeEventListener('beforeunload', preventNavWhileProcessing);
            }
            function preventNavWhileProcessing(e) {
                if (!paymentInFlight && !paymentCompleted) return;
                e.preventDefault();
                e.returnValue = '';
                return '';
            }

            document.querySelectorAll('[data-lg-checkout-close]').forEach(el => {
                el.addEventListener('click', () => {
                    // Paid: show overlay, never close.
                    if (paymentCompleted) { showProcessingOverlay(); return; }
                    // Stripe mounted: hard-refuse close. CSS already hides the
                    // X and disables backdrop pointer events; this guard
                    // covers any other dispatch (Esc keybinds, programmatic).
                    if (paymentInFlight) return;
                    closeCheckoutModal();
                });
            });

            // Anon-warn modal wiring
            const anonCheck   = document.querySelector('[data-lg-anonwarn-confirm-check]');
            const anonConfirm = document.querySelector('[data-lg-anonwarn-confirm]');
            if (anonCheck && anonConfirm) {
                anonCheck.addEventListener('change', () => { anonConfirm.disabled = !anonCheck.checked; });
            }
            document.querySelectorAll('[data-lg-anonwarn-cancel]').forEach(el => {
                el.addEventListener('click', () => {
                    if (anonWarnModal) anonWarnModal.hidden = true;
                    if (anonCheck) anonCheck.checked = false;
                    if (anonConfirm) anonConfirm.disabled = true;
                    document.body.classList.remove('lg-modal-open');
                });
            });
            if (anonConfirm) {
                anonConfirm.addEventListener('click', () => {
                    if (anonWarnModal) anonWarnModal.hidden = true;
                    if (anonCheck) anonCheck.checked = false;
                    anonConfirm.disabled = true;
                    document.body.classList.remove('lg-modal-open');
                    launchCheckout();
                });
            }

            // Per-click checkout context — captured when submit fires, used
            // by launchCheckout() (which can be called directly OR after
            // the user acknowledges the anon-warn modal).
            let pendingPrice = null, pendingQty = 0, pendingEmail = '';

            async function launchCheckout(){
                lockCheckout();
                if (mountedSession) { try { mountedSession.destroy(); } catch (e) {} mountedSession = null; }
                if (checkoutEl) checkoutEl.innerHTML = '';
                const origCta = ctaSpan.textContent;
                ctaSpan.textContent = 'Loading…';

                await runCheckout(pendingPrice, pendingQty, pendingEmail, origCta);
            }

            submitBtn.addEventListener('click', async function(){
                if (checkoutInProgress) return;

                showError('');
                const price = selectedDuration;
                if (!price) { showError('Please pick a tier and duration.'); return; }

                const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }
                // Confirm-email match (only enforced when buyer-email section is
                // visible — i.e. self mode, anonymous buyer). Logged-in or
                // managed-mode buyers don't see the confirm field.
                if (buyerEmailConfEl && !document.querySelector('.lg-gift--logged-in') && sendMode !== 'managed') {
                    const confirmVal = (buyerEmailConfEl.value || '').trim().toLowerCase();
                    if (email.toLowerCase() !== confirmVal) {
                        showError('Emails don’t match — please re-enter your confirmation email.');
                        buyerEmailConfEl.focus();
                        return;
                    }
                }

                pendingPrice = price; pendingQty = qty; pendingEmail = email;

                // Self-mode anonymous buyers see the warn modal first.
                // Logged-in (server-side or via auth panel) and managed-mode
                // users skip it — they have an account on file.
                const isAnonSelf = (sendMode === 'self') && !CONFIG.loggedIn && !isAuthed;
                if (isAnonSelf && anonWarnModal) {
                    anonWarnModal.hidden = false;
                    document.body.classList.add('lg-modal-open');
                    return;
                }

                launchCheckout();
            });

            async function runCheckout(price, qty, email, origCta){

                try {
                    const sessRes = await fetch(ENDPOINTS.checkout, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(buildCheckoutBody(price.priceId || price.stripe_price_id, qty, email)),
                    });
                    const sessData = await sessRes.json();
                    if (!sessData.clientSecret) {
                        showError(sessData.error || 'Could not start checkout.');
                        ctaSpan.textContent = origCta;
                        unlockCheckout();
                        return;
                    }

                    if (!stripe) {
                        const cfg = await (await fetch(ENDPOINTS.config)).json();
                        if (!cfg.publishableKey) {
                            showError('Stripe not configured.');
                            ctaSpan.textContent = origCta;
                            unlockCheckout();
                            return;
                        }
                        stripe = Stripe(cfg.publishableKey);
                    }

                    if (checkoutModal) checkoutModal.hidden = false;
                    document.body.classList.add('lg-modal-open');

                    // Watchdog — never trap the user behind the spinner forever.
                    const watchdog = setTimeout(() => {
                        if (redirectOverlay && !redirectOverlay.hidden) {
                            redirectOverlay.hidden = true;
                            showError('Checkout took longer than expected to load. Try again, or refresh the page.');
                        }
                    }, 12000);

                    const uiMode = sessData.ui_mode || 'embedded';
                    if (uiMode === 'custom') {
                        await mountGiftCustomCheckout(sessData.clientSecret);
                    } else {
                        await mountGiftEmbeddedCheckout(sessData.clientSecret);
                    }
                    clearTimeout(watchdog);

                    // Custom mode: lock-at-Pay-click is wired in onGiftPayClick.
                    // Embedded mode (fallback): lock at mount, same as before.
                    if (uiMode !== 'custom') {
                        if (checkoutModal) checkoutModal.dataset.lgLocked = '1';
                        markPaymentInFlight();
                    }

                    hideRedirectOverlaySoon();
                } catch (err) {
                    showError('Network error: ' + err.message);
                    ctaSpan.textContent = origCta;
                    unlockCheckout();
                    if (checkoutModal) checkoutModal.hidden = true;
                    document.body.classList.remove('lg-modal-open');
                    recompute();
                }
            }

            // ── Send-mode toggle + recipient repeater ─────────────────────────
            const modeOpts    = document.querySelectorAll('.lg-mode__opt');
            const recipBlock  = document.querySelector('[data-lg-recip-block]');
            const recipList   = document.querySelector('[data-lg-recip-list]');
            const recipCount  = document.querySelector('[data-lg-recip-count]');
            const recipStatus = document.querySelector('[data-lg-recip-status]');
            const modeLabel   = document.querySelector('[data-lg-mode-label]');
            const modeHelp    = document.querySelector('[data-lg-mode-help]');
            const pasteEl     = document.querySelector('[data-lg-recip-paste]');
            const pasteHelp   = document.querySelector('.lg-recip__paste-help');
            const applyAll    = document.querySelector('[data-lg-recip-applyall]');
            const togglePaste = document.querySelector('[data-lg-toggle-paste]');
            const toggleApply = document.querySelector('[data-lg-toggle-applyall]');
            const applyAllBtn = document.querySelector('[data-lg-recip-applyall-btn]');

            let sendMode = 'managed';
            let recipRows = []; // {name, email, message, noteOpen}

            function syncRows(){
                const qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                while (recipRows.length < qty) recipRows.push({ name: '', email: '', message: '', noteOpen: false });
                while (recipRows.length > qty) recipRows.pop();
            }
            function isValidEmail(s){ return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s); }

            function renderRecipients(){
                if (!recipList) return;
                syncRows();
                recipList.innerHTML = '';
                recipRows.forEach((r, i) => {
                    const row = document.createElement('div');
                    row.className = 'lg-recip__row';
                    row.innerHTML =
                        '<div class="lg-recip__num">' + (i + 1) + '.</div>' +
                        '<input type="text"  placeholder="Name (optional)" value="' + escapeAttr(r.name)  + '" data-i="' + i + '" data-f="name">' +
                        '<input type="email" placeholder="email"           value="' + escapeAttr(r.email) + '" data-i="' + i + '" data-f="email">';
                    recipList.appendChild(row);
                    if (r.noteOpen || r.message) {
                        const noteRow = document.createElement('div');
                        noteRow.className = 'lg-recip__row lg-recip__row--note';
                        noteRow.innerHTML =
                            '<div class="lg-recip__num"></div>' +
                            '<textarea placeholder="Personal note for ' + escapeAttr(r.name || 'this recipient') + '…" data-i="' + i + '" data-f="message">' + escapeHtml(r.message) + '</textarea>';
                        recipList.appendChild(noteRow);
                    } else {
                        const addBtn = document.createElement('div');
                        addBtn.className = 'lg-recip__row lg-recip__row--note';
                        addBtn.innerHTML =
                            '<div class="lg-recip__num"></div>' +
                            '<button type="button" class="lg-recip__add-note" data-i="' + i + '">+ Add personal note</button>';
                        recipList.appendChild(addBtn);
                    }
                });
                updateRecipStatus();
            }
            function updateRecipStatus(){
                const qty = recipRows.length;
                const filled = recipRows.filter(r => isValidEmail(r.email)).length;
                if (recipCount) recipCount.textContent = '(' + filled + ' / ' + qty + ' ready)';
                if (!recipStatus) return;
                if (filled === qty && qty > 0) {
                    recipStatus.className = 'lg-recip__status is-ok';
                    recipStatus.textContent = '✓ Ready — ' + filled + ' recipient' + (qty === 1 ? '' : 's') + ' will each receive their own gift email.';
                } else {
                    const remain = qty - filled;
                    recipStatus.className = 'lg-recip__status is-warn';
                    recipStatus.textContent = remain + ' more recipient email' + (remain === 1 ? '' : 's') + ' needed before checkout.';
                }
            }
            function escapeAttr(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
            function escapeHtml(s){ return escapeAttr(s); }

            if (recipList) {
                recipList.addEventListener('input', e => {
                    const i = parseInt(e.target.dataset.i, 10);
                    const f = e.target.dataset.f;
                    if (Number.isFinite(i) && f) {
                        recipRows[i][f] = e.target.value;
                        updateRecipStatus();
                    }
                });
                recipList.addEventListener('click', e => {
                    if (e.target.classList.contains('lg-recip__add-note')) {
                        const i = parseInt(e.target.dataset.i, 10);
                        recipRows[i].noteOpen = true;
                        renderRecipients();
                    }
                });
            }

            const authBlock      = document.querySelector('[data-lg-auth-block]');
            const authSuccess    = document.querySelector('[data-lg-auth-success]');
            const authEmailEl    = document.querySelector('[data-lg-auth-email]');
            const authEmailConfEl= document.querySelector('[data-lg-auth-email-confirm]');
            const authEmailMisEl = document.querySelector('[data-lg-auth-email-mismatch]');
            const authPassEl     = document.querySelector('[data-lg-auth-password]');
            const authPassConfEl = document.querySelector('[data-lg-auth-password-confirm]');
            const authPwdMisEl   = document.querySelector('[data-lg-auth-pwd-mismatch]');
            const authSubEl      = document.querySelector('[data-lg-auth-subscribe]');
            const authBtn        = document.querySelector('[data-lg-auth-btn]');
            const authErrEl      = document.querySelector('[data-lg-auth-error]');
            const authForgot     = document.querySelector('[data-lg-auth-forgot]');
            const authWelcome    = document.querySelector('[data-lg-auth-welcome]');
            let   isAuthed       = CONFIG.loggedIn;

            // Login-modal refs (returning-member fast path on the same page)
            const loginModal     = document.querySelector('[data-lg-login-modal]');
            const loginEmailEl   = document.querySelector('[data-lg-login-email]');
            const loginPassEl    = document.querySelector('[data-lg-login-password]');
            const loginBtn       = document.querySelector('[data-lg-login-btn]');
            const loginErrEl     = document.querySelector('[data-lg-login-error]');
            const loginSubEl     = document.querySelector('[data-lg-login-sub]');

            // Portal login modal to body so BuddyBoss containing-blocks can't trap it.
            if (loginModal && loginModal.parentNode !== document.body) {
                document.body.appendChild(loginModal);
            }
            function openLoginModal(prefillEmail, subMsg) {
                if (!loginModal) return;
                if (loginEmailEl) loginEmailEl.value = prefillEmail || (authEmailEl ? authEmailEl.value : '') || '';
                if (loginPassEl)  loginPassEl.value  = '';
                if (loginErrEl)   { loginErrEl.hidden = true; loginErrEl.textContent = ''; }
                if (loginSubEl && subMsg) loginSubEl.textContent = subMsg;
                loginModal.hidden = false;
                document.body.classList.add('lg-modal-open');
                setTimeout(() => { (loginEmailEl && !loginEmailEl.value ? loginEmailEl : loginPassEl)?.focus(); }, 30);
            }
            function closeLoginModal() {
                if (loginModal) loginModal.hidden = true;
                if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
            }
            document.querySelectorAll('[data-lg-login-close]').forEach(el => {
                el.addEventListener('click', (e) => { e.preventDefault(); closeLoginModal(); });
            });
            document.querySelectorAll('[data-lg-auth-open-login]').forEach(el => {
                el.addEventListener('click', (e) => { e.preventDefault(); openLoginModal(authEmailEl ? authEmailEl.value : ''); });
            });
            if (loginBtn) {
                loginBtn.addEventListener('click', async () => {
                    const email = (loginEmailEl ? loginEmailEl.value.trim() : '');
                    const pwd   = (loginPassEl  ? loginPassEl.value         : '');
                    if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                        if (loginErrEl) { loginErrEl.textContent = 'Please enter a valid email address.'; loginErrEl.hidden = false; }
                        return;
                    }
                    if (!pwd) {
                        if (loginErrEl) { loginErrEl.textContent = 'Please enter your password.'; loginErrEl.hidden = false; }
                        return;
                    }
                    loginBtn.disabled = true; loginBtn.textContent = 'Logging in…';
                    try {
                        const data = await doAuth({ email, password: pwd, login_only: true });
                        if (data.ok) {
                            // Reload so the gift page renders in its canonical
                            // logged-in state (server-rendered banner, hidden
                            // mode/buyer-email sections, /my-gifts link, etc.).
                            // The auth cookie was set in the response above so
                            // the reload runs as the now-logged-in user.
                            // Carry the in-progress selections through as URL
                            // params so the post-reload page can re-apply them
                            // (see applyResumeState above).
                            loginBtn.textContent = 'Logged in — refreshing…';
                            const qs = (typeof window.lgSerializeGiftState === 'function')
                                ? window.lgSerializeGiftState() : '';
                            const dest = window.location.pathname
                                + (qs ? '?' + qs : '')
                                + window.location.hash;
                            window.location.replace(dest);
                            return;
                        } else {
                            if (loginErrEl) { loginErrEl.textContent = data.error || 'Login failed. Check your password.'; loginErrEl.hidden = false; }
                        }
                    } catch (e) {
                        if (loginErrEl) { loginErrEl.textContent = 'Network error. Please try again.'; loginErrEl.hidden = false; }
                    }
                    loginBtn.disabled = false; loginBtn.textContent = 'Log in';
                });
            }

            // Live email/password match for create-account form
            function checkAuthEmailMatch() {
                if (!authEmailEl || !authEmailConfEl || !authEmailMisEl) return;
                const a = (authEmailEl.value || '').trim().toLowerCase();
                const b = (authEmailConfEl.value || '').trim().toLowerCase();
                authEmailMisEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            function checkAuthPwdMatch() {
                if (!authPassEl || !authPassConfEl || !authPwdMisEl) return;
                const a = authPassEl.value, b = authPassConfEl.value;
                authPwdMisEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (authEmailEl)     authEmailEl.addEventListener('input', checkAuthEmailMatch);
            if (authEmailConfEl) authEmailConfEl.addEventListener('input', checkAuthEmailMatch);
            if (authPassEl)      authPassEl.addEventListener('input', checkAuthPwdMatch);
            if (authPassConfEl)  authPassConfEl.addEventListener('input', checkAuthPwdMatch);

            // Buyer-email confirm match (self mode at step 5)
            const buyerEmailEl     = document.querySelector('input[name="email"]');
            const buyerEmailConfEl = document.querySelector('input[name="email_confirm"]');
            const buyerEmailMisEl  = document.querySelector('[data-lg-buyer-email-mismatch]');
            function checkBuyerEmailMatch() {
                if (!buyerEmailEl || !buyerEmailConfEl || !buyerEmailMisEl) return;
                const a = (buyerEmailEl.value || '').trim().toLowerCase();
                const b = (buyerEmailConfEl.value || '').trim().toLowerCase();
                buyerEmailMisEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (buyerEmailEl)     buyerEmailEl.addEventListener('input', checkBuyerEmailMatch);
            if (buyerEmailConfEl) buyerEmailConfEl.addEventListener('input', checkBuyerEmailMatch);

            const giftRoot = document.querySelector('.lg-gift');

            modeOpts.forEach(o => o.addEventListener('click', () => {
                modeOpts.forEach(x => x.classList.remove('is-selected'));
                o.classList.add('is-selected');
                const r = o.querySelector('input[type="radio"]'); if (r) r.checked = true;
                sendMode = o.dataset.mode;
                if (giftRoot) giftRoot.classList.toggle('lg-gift--mode-managed', sendMode === 'managed');
                if (sendMode === 'managed') {
                    if (!isAuthed && authBlock) authBlock.hidden = false;
                } else {
                    if (modeLabel) modeLabel.textContent = '(codes will be sent here)';
                    if (modeHelp)  modeHelp.textContent  = 'We send all codes to this address. You forward / share them yourself.';
                    if (authBlock && !isAuthed) authBlock.hidden = true;
                }
            }));

            const consentModal   = document.querySelector('[data-lg-consent-modal]');
            const consentEmail   = document.querySelector('[data-lg-consent-email]');
            const consentSub     = document.querySelector('[data-lg-consent-subscribe]');
            const consentConfirm = document.querySelector('[data-lg-consent-confirm]');
            const consentErr     = document.querySelector('[data-lg-consent-error]');

            function applyAuthSuccess(data) {
                isAuthed = true;
                if (authBlock)    authBlock.hidden    = true;
                if (authSuccess)  authSuccess.hidden  = false;
                if (authWelcome)  authWelcome.textContent = 'You' + String.fromCharCode(39) + 're logged in as ' + data.name + '.';
                const buyerInput = document.querySelector('[name="email"]');
                if (buyerInput && data.email) buyerInput.value = data.email;
                const buyerInputConf = document.querySelector('[name="email_confirm"]');
                if (buyerInputConf && data.email) buyerInputConf.value = data.email;
                if (consentModal) consentModal.hidden = true;
                if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');

                // Strip the buy-flow chrome: now that codes attach to their
                // account, drop the mode picker and the buyer-email section.
                // Re-use the existing logged-in CSS class which already
                // hides [data-lg-mode-section] and [data-lg-buyer-email-section].
                const root = document.querySelector('.lg-gift');
                if (root) root.classList.add('lg-gift--logged-in');

                // Floating "Hi <name> — codes will land in your dashboard"
                // banner the logged-in branch normally renders server-side.
                if (root && !document.querySelector('.lg-gift__loggedin-banner')) {
                    const banner = document.createElement('div');
                    banner.className = 'lg-gift__loggedin-banner';
                    banner.innerHTML = 'Hi <strong>' + (data.name ? String(data.name).replace(/[<>&]/g, '') : 'there') +
                                       '</strong> &mdash; codes you buy will land in your <a href="/my-gifts/">gift dashboard</a> after checkout.';
                    root.insertBefore(banner, root.firstChild);
                }
            }

            async function doAuth(payload) {
                const res = await fetch(ENDPOINTS.authUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                return await res.json();
            }

            if (authBtn) {
                authBtn.addEventListener('click', async () => {
                    const email         = (authEmailEl     ? authEmailEl.value.trim()     : '');
                    const emailConfirm  = (authEmailConfEl ? authEmailConfEl.value.trim() : '');
                    const password      = (authPassEl      ? authPassEl.value             : '');
                    const passwordConf  = (authPassConfEl  ? authPassConfEl.value         : '');

                    if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                        if (authErrEl) { authErrEl.textContent = 'Please enter a valid email address.'; authErrEl.hidden = false; }
                        return;
                    }
                    if (authEmailConfEl && email.toLowerCase() !== emailConfirm.toLowerCase()) {
                        if (authErrEl) { authErrEl.textContent = 'Emails don’t match.'; authErrEl.hidden = false; }
                        return;
                    }
                    if (password.length < 8) {
                        if (authErrEl) { authErrEl.textContent = 'Password must be at least 8 characters.'; authErrEl.hidden = false; }
                        return;
                    }
                    if (authPassConfEl && password !== passwordConf) {
                        if (authErrEl) { authErrEl.textContent = 'Passwords don’t match.'; authErrEl.hidden = false; }
                        return;
                    }

                    if (authErrEl)  authErrEl.hidden  = true;
                    if (authForgot) authForgot.hidden = true;
                    authBtn.disabled    = true;
                    authBtn.textContent = 'Please wait…';

                    try {
                        const data = await doAuth({ email, password });
                        if (data.ok) {
                            applyAuthSuccess(data);
                        } else if (data.forgot) {
                            // Email already has an account — don't burn them with
                            // a generic error. Pop the inline login modal with
                            // the email pre-filled so they can finish on this
                            // same page (gift selections survive intact).
                            openLoginModal(email, 'Looks like you already have an account. Log in to keep your gift selections.');
                        } else if (data.needs_consent) {
                            // New email — show consent modal before creating account.
                            // Defensive: if for any reason the local email is
                            // empty, fall back to the server-echoed email.
                            const showEmail = email || (data.email || '');
                            if (consentEmail) consentEmail.textContent = showEmail || 'this email';
                            if (consentSub)   consentSub.checked = false;
                            if (consentErr)   consentErr.hidden = true;
                            if (consentModal) {
                                consentModal.hidden = false;
                                document.body.classList.add('lg-modal-open');
                            }
                        } else {
                            if (authErrEl)  { authErrEl.textContent = data.error || 'Something went wrong. Please try again.'; authErrEl.hidden = false; }
                            if (authForgot && data.forgot) authForgot.hidden = false;
                        }
                    } catch (e) {
                        if (authErrEl) { authErrEl.textContent = 'Network error. Please try again.'; authErrEl.hidden = false; }
                    }

                    authBtn.disabled    = false;
                    authBtn.textContent = 'Create account';
                });
            }

            // Consent modal — confirm or cancel new-account creation.
            document.querySelectorAll('[data-lg-consent-cancel]').forEach(el => {
                el.addEventListener('click', () => {
                    if (consentModal) consentModal.hidden = true;
                    if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
                });
            });

            // Esc key closes consent modal as a final escape hatch.
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                if (consentModal && !consentModal.hidden) {
                    consentModal.hidden = true;
                    if (!checkoutModal || checkoutModal.hidden) document.body.classList.remove('lg-modal-open');
                }
            });

            if (consentConfirm) {
                consentConfirm.addEventListener('click', async () => {
                    const email     = (authEmailEl ? authEmailEl.value.trim() : '');
                    const password  = (authPassEl  ? authPassEl.value         : '');
                    const subscribe = (consentSub  ? consentSub.checked       : false);

                    if (consentErr) consentErr.hidden = true;
                    consentConfirm.disabled    = true;
                    consentConfirm.textContent = 'Creating…';

                    try {
                        const data = await doAuth({ email, password, subscribe_weekly: subscribe, confirmed_consent: true });
                        if (data.ok) {
                            if (consentModal) consentModal.hidden = true;
                            applyAuthSuccess(data);
                        } else if (consentErr) {
                            consentErr.textContent = data.error || 'Something went wrong. Please try again.';
                            consentErr.hidden = false;
                        }
                    } catch (e) {
                        if (consentErr) { consentErr.textContent = 'Network error. Please try again.'; consentErr.hidden = false; }
                    }

                    consentConfirm.disabled    = false;
                    consentConfirm.textContent = 'Create my account';
                });
            }

            if (togglePaste && pasteEl && pasteHelp) {
                togglePaste.addEventListener('click', () => {
                    pasteEl.classList.toggle('is-open');
                    pasteHelp.classList.toggle('is-open');
                    if (pasteEl.classList.contains('is-open')) pasteEl.focus();
                });
                pasteEl.addEventListener('blur', () => {
                    const lines = pasteEl.value.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
                    syncRows();
                    lines.forEach((line, i) => {
                        if (i >= recipRows.length) return;
                        const m = line.match(/^(.+?)\s*<(.+@.+)>$/);
                        if (m)      { recipRows[i].name = m[1].trim(); recipRows[i].email = m[2].trim(); }
                        else if (isValidEmail(line)) { recipRows[i].email = line; }
                    });
                    renderRecipients();
                });
            }
            if (toggleApply && applyAll && applyAllBtn) {
                toggleApply.addEventListener('click', () => applyAll.classList.toggle('is-open'));
                applyAllBtn.addEventListener('click', () => {
                    const note = applyAll.querySelector('textarea').value.trim();
                    recipRows.forEach(r => { r.message = note; r.noteOpen = note !== ''; });
                    renderRecipients();
                });
            }

            // Re-render recipient rows whenever qty changes (in direct mode).
            qtyInput.addEventListener('input',  () => { if (sendMode === 'direct') renderRecipients(); });
            document.querySelectorAll('[data-lg-qty-step]').forEach(b => b.addEventListener('click', () => { if (sendMode === 'direct') renderRecipients(); }));
            document.querySelectorAll('.lg-gift__preset').forEach(b => b.addEventListener('click', () => { if (sendMode === 'direct') renderRecipients(); }));

            function buildCheckoutBody(priceId, qty, email){
                const body = { price_id: priceId, quantity: qty, email: email, gift: true };
                if (selectedDuration && selectedDuration.durationMonths !== null) {
                    body.duration_months = selectedDuration.durationMonths;
                }
                const lgRef = lgGetRef();
                if (lgRef) body.ref = lgRef;
                // Logged-in buyers always go to the dashboard — no recipients
                // collected upfront. Slim sees dashboard_mode=1 and routes the
                // post-purchase redirect to /my-gifts/ + sends the
                // "you have N codes" buyer email instead of the bulk-table one.
                if (CONFIG.loggedIn || (sendMode === 'managed' && isAuthed)) {
                    body.dashboard_mode = 1;
                    return body;
                }
                if (sendMode === 'direct') {
                    body.recipients = recipRows.map(r => ({
                        email:   r.email,
                        name:    r.name,
                        message: r.message,
                    }));
                }
                return body;
            }

            // Pre-flight validation before opening Stripe.
            const origSubmit = submitBtn.onclick;
            submitBtn.addEventListener('click', function(e){
                if (sendMode === 'managed' && !isAuthed) {
                    e.stopImmediatePropagation();
                    if (authBlock) { authBlock.hidden = false; authBlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
                    if (authEmailEl) authEmailEl.focus();
                    showError('Please log in or create an account first.');
                    return;
                }
                if (sendMode === 'direct') {
                    syncRows();
                    const bad = recipRows.findIndex(r => !isValidEmail(r.email));
                    if (bad !== -1) {
                        e.stopImmediatePropagation();
                        showError('Please fill in valid emails for every recipient (row ' + (bad + 1) + ' is missing a valid address).');
                        const inputs = recipList.querySelectorAll('input[data-f="email"]');
                        if (inputs[bad]) inputs[bad].focus();
                        return;
                    }
                }
            }, true);

            loadProducts();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [lg_manage_subscription] — button that opens the Stripe Customer Portal
     * for the logged-in user, where they can upgrade / downgrade / cancel
     * their subscription, update payment methods, and view invoices.
     *
     * Renders nothing for non-logged-in users or users without a customer
     * record. Use [lg_join] instead for those cases.
     */
    public static function manageSubscription( $atts = [] ): string
    {
        shortcode_atts( [], (array) $atts, 'lg_manage_subscription' );

        $user = wp_get_current_user();
        if ( $user->ID === 0 ) {
            return '<p><em>Please sign in to manage your membership.</em></p>';
        }
        $email = (string) $user->user_email;
        if ( $email === '' ) {
            return '';
        }

        $customer = \LGMS\Repos\CustomerRepo::findByEmail( $email );
        if ( $customer === null ) {
            return '<p><em>No membership record found on this account.</em></p>';
        }

        // Active gift-sourced entitlements (gifted memberships) — separate
        // from subscription state because gifts are time-bounded grants
        // with no auto-renew, no payment method to manage.
        $giftEnts = [];
        foreach ( \LGMS\Repos\EntitlementRepo::activeForCustomer( (int) $customer['id'] ) as $ent ) {
            if ( ( $ent['source_type'] ?? '' ) === 'gift_code' && ( $ent['kind'] ?? '' ) === \LGMS\Repos\EntitlementRepo::KIND_MEMBERSHIP_TIER ) {
                $giftEnts[] = $ent;
            }
        }

        // Active subs from our DB.
        $subs = [];
        try {
            $stmt = \LGMS\Db::pdo()->prepare(
                "SELECT stripe_subscription_id, stripe_price_id, status, current_period_start, current_period_end, cancel_at_period_end
                 FROM subscriptions
                 WHERE customer_id = ? AND status IN ('active','trialing','past_due')
                 ORDER BY id DESC"
            );
            $stmt->execute( [ (int) $customer['id'] ] );
            $subs = $stmt->fetchAll( \PDO::FETCH_ASSOC );
        } catch ( \Throwable $_ ) {
            $subs = [];
        }

        $portalEndpoint      = esc_url_raw( rtrim( (string) home_url( '/billing' ), '/' ) . '/v1/portal' );
        $productsUrl         = esc_url_raw( rtrim( (string) home_url( '/billing' ), '/' ) . '/v1/products' );
        $configUrl           = esc_url_raw( rtrim( (string) home_url( '/billing' ), '/' ) . '/v1/config' );
        $cancelEndpoint      = esc_url_raw( rest_url( 'lg-member-sync/v1/me/cancel-subscription' ) );
        $switchEndpoint      = esc_url_raw( rest_url( 'lg-member-sync/v1/me/switch-plan' ) );
        $setupIntentEndpoint  = esc_url_raw( rest_url( 'lg-member-sync/v1/me/create-setup-intent' ) );
        $setDefaultPmEndpoint = esc_url_raw( rest_url( 'lg-member-sync/v1/me/set-default-payment-method' ) );
        $getPmsEndpoint       = esc_url_raw( rest_url( 'lg-member-sync/v1/me/payment-methods' ) );
        $deletePmEndpoint     = esc_url_raw( rest_url( 'lg-member-sync/v1/me/delete-payment-method' ) );
        $getInvoicesEndpoint  = esc_url_raw( rest_url( 'lg-member-sync/v1/me/invoices' ) );
        $nonce               = wp_create_nonce( 'wp_rest' );
        $emailEsc            = esc_attr( $email );

        // Build the hero summary line that /lgjoin/ renders for active
        // subscribers, so the manage-membership page reads with the same
        // "You're already a member" framing — single source of truth.
        $heroSummary = '';
        if ( $subs !== [] ) {
            $top         = $subs[0];
            $tierLabel   = self::tierLabelForPrice( (string) $top['stripe_price_id'] );
            $endShort    = (string) ( $top['current_period_end'] ?? '' );
            $endShort    = $endShort !== '' ? substr( $endShort, 0, 10 ) : '';
            $statusLabel = (string) $top['status'];
            $heroSummary = 'Active <strong>' . esc_html( $tierLabel ?: 'membership' ) . '</strong> subscription'
                         . ' &middot; status ' . esc_html( $statusLabel )
                         . ( $endShort !== '' ? ' &middot; renews ' . esc_html( $endShort ) : '' );
        } elseif ( $giftEnts !== [] ) {
            $top      = $giftEnts[0];
            $giftTier = self::tierLabelForRef( (string) $top['ref'] );
            $giftEnd  = (string) ( $top['expires_at'] ?? '' );
            $giftEnd  = $giftEnd !== '' ? substr( $giftEnd, 0, 10 ) : '';
            $heroSummary = '🎁 Active gifted <strong>' . esc_html( $giftTier ?: 'membership' ) . '</strong>'
                         . ( $giftEnd !== '' ? ' &middot; expires ' . esc_html( $giftEnd ) : '' );
        }

        ob_start();
        ?>
        <div class="lg-manage-sub">
            <?php if ( $heroSummary !== '' ) : ?>
                <div class="lg-manage-sub__hero" style="margin:0 0 1.6em;">
                    <h3 style="margin:0 0 .35em;font-size:1.4em;">You're already a member</h3>
                    <p style="margin:0;color:#444;"><?php echo $heroSummary; ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $giftEnts !== [] ) : ?>
                <?php foreach ( $giftEnts as $ent ) :
                    $giftTier   = self::tierLabelForRef( (string) $ent['ref'] );
                    $giftEnds   = (string) ( $ent['expires_at'] ?? '' );
                    $giftStarts = (string) ( $ent['starts_at']  ?? '' );
                    $giftDays   = self::daysRemaining( $giftEnds );
                ?>
                <div class="lg-manage-sub__card lg-manage-sub__card--gift" style="border:1px solid #ECB351;border-radius:6px;padding:1em 1.2em;margin-bottom:1em;max-width:640px;background:#fbf6e8;">
                    <h4 style="margin:0 0 0.5em;">
                        🎁 <?php echo esc_html( $giftTier ?: 'Gifted membership' ); ?>
                        <span style="font-size:0.85em;font-weight:400;opacity:.7;">(gift)</span>
                    </h4>
                    <p style="margin:0.2em 0;color:#444;">
                        <?php if ( $giftEnds !== '' ) : ?>
                            Expires <strong><?php echo esc_html( self::shortDate( $giftEnds ) ); ?></strong>
                            <?php if ( $giftDays !== null ) : ?>
                                <span style="color:#888;font-size:0.9em;">(<?php echo esc_html( (string) $giftDays ); ?> day<?php echo $giftDays === 1 ? '' : 's'; ?> left)</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <em>No expiration on file.</em>
                        <?php endif; ?>
                    </p>
                    <p style="margin:.4em 0 0;font-size:.88em;color:#666;">
                        Gifted memberships don't auto-renew. Set up a subscription now and we won't charge you until your gift ends &mdash;
                        you stay covered without interruption.
                    </p>
                    <div style="margin-top:.9em;">
                        <a class="lg-manage-sub__btn lg-manage-sub__btn--primary" href="<?php echo esc_url( home_url( '/lgjoin/' ) ); ?>" style="display:inline-block;background:#ECB351;color:#1f1d1a;padding:.6em 1.15em;border-radius:6px;font-weight:600;text-decoration:none;">
                            Set up subscription &mdash; <?php echo $giftDays !== null && $giftDays > 0 ? 'first charge in ' . esc_html( (string) $giftDays ) . ' day' . ( $giftDays === 1 ? '' : 's' ) : 'no charge today'; ?> &rarr;
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ( $subs === [] ) : ?>
                <?php if ( $giftEnts === [] ) : ?>
                    <p>You don't have an active membership right now.</p>
                    <p><a href="<?php echo esc_url( home_url( '/lgjoin/' ) ); ?>">Pick a plan to get started &rarr;</a></p>
                <?php endif; ?>
            <?php else : ?>
                <?php foreach ( $subs as $sub ) :
                    $subId   = (string) $sub['stripe_subscription_id'];
                    $tier    = self::tierLabelForPrice( (string) $sub['stripe_price_id'] );
                    $endsAt  = (string) ( $sub['current_period_end'] ?? '' );
                    $cape    = (int) ( $sub['cancel_at_period_end'] ?? 0 ) === 1;
                    $daysLeft = self::daysRemaining( $endsAt );
                ?>
                <div class="lg-manage-sub__card" style="border:1px solid #ddd;border-radius:6px;padding:1em 1.2em;margin-bottom:1em;max-width:640px;" data-lg-sub="<?php echo esc_attr( $subId ); ?>">
                    <h4 style="margin:0 0 0.5em;"><?php echo esc_html( $tier ?: 'Membership' ); ?></h4>
                    <p style="margin:0.2em 0;color:#444;">
                        Status: <strong><?php echo esc_html( (string) $sub['status'] ); ?></strong><br>
                        <?php if ( $cape ) : ?>
                            Ends on <strong data-lg-renew-date><?php echo esc_html( self::shortDate( $endsAt ) ); ?></strong> &mdash; will not renew.
                            <?php if ( $daysLeft !== null ) : ?>
                                <span style="color:#888;font-size:0.9em;">(<?php echo esc_html( (string) $daysLeft ); ?> day<?php echo $daysLeft === 1 ? '' : 's'; ?> left)</span>
                            <?php endif; ?>
                        <?php else : ?>
                            Renews on <strong data-lg-renew-date><?php echo esc_html( self::shortDate( $endsAt ) ); ?></strong>
                            <?php if ( $daysLeft !== null ) : ?>
                                <span style="color:#888;font-size:0.9em;">(<?php echo esc_html( (string) $daysLeft ); ?> day<?php echo $daysLeft === 1 ? '' : 's'; ?> left)</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>

                    <?php if ( (string) $sub['status'] === 'past_due' ) : ?>
                        <div style="margin-top:0.8em;padding:0.7em 1em;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;color:#856404;font-size:0.92em;">
                            <strong>Payment failed.</strong> Your access is still active while we retry. Please update your payment method in the <strong>Payment methods</strong> section below.
                        </div>
                    <?php endif; ?>

                    <div class="lg-manage-sub__actions" style="margin-top:1em;">
                        <button type="button" class="lg-manage-sub__btn" data-lg-action="switch" data-lg-sub="<?php echo esc_attr( $subId ); ?>" data-lg-current-price="<?php echo esc_attr( (string) $sub['stripe_price_id'] ); ?>">Change plan</button>
                        <?php if ( ! $cape ) : ?>
                            <button type="button" class="lg-manage-sub__btn" data-lg-action="cancel" data-lg-sub="<?php echo esc_attr( $subId ); ?>">Cancel subscription</button>
                        <?php else : ?>
                            <em style="color:#666;">Already scheduled for cancellation.</em>
                        <?php endif; ?>
                    </div>

                    <div class="lg-manage-sub__switcher" data-lg-switcher style="display:none;margin-top:1em;border-top:1px solid #eee;padding-top:1em;">
                        <p>Pick a plan to switch to:</p>
                        <div data-lg-plans>Loading plans&hellip;</div>
                    </div>

                    <div class="lg-manage-sub__cancel" data-lg-canceller style="display:none;margin-top:1em;border-top:1px solid #eee;padding-top:1em;">
                        <p>When would you like the cancellation to take effect?</p>
                        <label style="display:block;margin:0.3em 0;">
                            <input type="radio" name="cancel-when-<?php echo esc_attr( $subId ); ?>" value="period_end" checked>
                            <strong>At the end of my current billing period</strong> (<?php echo esc_html( self::shortDate( $endsAt ) ); ?>) &mdash; recommended.
                        </label>
                        <label style="display:block;margin:0.3em 0;">
                            <input type="radio" name="cancel-when-<?php echo esc_attr( $subId ); ?>" value="immediate">
                            <strong>Immediately</strong> &mdash; access ends right away. (Refunds are reviewed via the <a href="<?php echo esc_url( home_url( '/request-refund/' ) ); ?>">refund request form</a>.)
                        </label>
                        <button type="button" class="lg-manage-sub__btn" data-lg-action="cancel-confirm" data-lg-sub="<?php echo esc_attr( $subId ); ?>">Confirm cancellation</button>
                        <button type="button" class="lg-manage-sub__btn" data-lg-action="cancel-back" style="margin-left:6px;">Never mind</button>
                    </div>

                    <div class="lg-manage-sub__result" data-lg-result aria-live="polite" style="margin-top:1em;"></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="lg-pm-wallet">
                <h3 class="lg-pm-wallet__heading">Payment methods</h3>
                <div id="lg-pm-wallet-list" class="lg-pm-wallet__list">
                    <p class="lg-pm-wallet__loading">Loading&hellip;</p>
                </div>
                <div id="lg-pm-wallet-feedback" hidden></div>
                <button type="button" class="lg-pm-wallet__add" id="lg-pm-add-card">
                    &#43; Add a payment method
                </button>
            </div>

            <div class="lg-billing-history" style="max-width:680px;margin-top:2em;padding-top:1.5em;border-top:1px solid #e0e0e0;">
                <h3 style="font-size:1.05em;font-weight:700;margin:0 0 1em;color:var(--lg-ink,#292929);">Billing history</h3>
                <div id="lg-billing-list" style="border-top:1px solid #f0f0f0;"></div>
            </div>
        </div>

        <!-- Card update modal -->
        <div class="lg-pay-modal" id="lg-card-update-modal" hidden>
            <div class="lg-pay-modal__backdrop"></div>
            <div class="lg-pay-modal__card">
                <button class="lg-pay-modal__close" id="lg-card-modal-close">&times;</button>
                <div class="lg-pay-modal__custom">
                    <h3 style="margin:0 0 0.5em;font-size:1.1em;">Add a payment method</h3>
                    <div id="lg-card-pe"></div>
                    <div id="lg-card-error" class="lg-stripe-modal__error" hidden></div>
                    <button type="button" id="lg-card-save" class="lg-stripe-modal__pay" style="margin-top:1em;">Save card</button>
                </div>
            </div>
        </div>

        <script src="https://js.stripe.com/basil/stripe.js"></script>
        <script>
        (function(){
            const PORTAL         = '<?php echo esc_js( $portalEndpoint ); ?>';
            const PROD           = '<?php echo esc_js( $productsUrl ); ?>';
            const CONFIG         = '<?php echo esc_js( $configUrl ); ?>';
            const CANCEL         = '<?php echo esc_js( $cancelEndpoint ); ?>';
            const SWITCH         = '<?php echo esc_js( $switchEndpoint ); ?>';
            const SETUP_INTENT   = '<?php echo esc_js( $setupIntentEndpoint ); ?>';
            const SET_DEFAULT_PM = '<?php echo esc_js( $setDefaultPmEndpoint ); ?>';
            const GET_PMS        = '<?php echo esc_js( $getPmsEndpoint ); ?>';
            const DELETE_PM      = '<?php echo esc_js( $deletePmEndpoint ); ?>';
            const GET_INVOICES   = '<?php echo esc_js( $getInvoicesEndpoint ); ?>';
            const NONCE          = <?php echo wp_json_encode( $nonce ); ?>;
            const EMAIL          = <?php echo wp_json_encode( $email ); ?>;

            let stripe = null, cardElements = null;

            async function getStripe() {
                if (stripe) return stripe;
                const cfg = await (await fetch(CONFIG)).json();
                if (!cfg.publishableKey) throw new Error('Stripe not configured.');
                stripe = Stripe(cfg.publishableKey);
                return stripe;
            }

            // Card update modal
            const cardModal   = document.getElementById('lg-card-update-modal');
            const cardClose   = document.getElementById('lg-card-modal-close');
            const cardPeEl    = document.getElementById('lg-card-pe');
            const cardErrEl   = document.getElementById('lg-card-error');
            const cardSaveBtn = document.getElementById('lg-card-save');

            function showCardError(msg) {
                cardErrEl.textContent = msg;
                cardErrEl.hidden = false;
            }
            function clearCardError() { cardErrEl.hidden = true; cardErrEl.textContent = ''; }

            async function openCardModal() {
                clearCardError();
                cardPeEl.innerHTML = '<p style="color:#888;font-size:0.9em;">Loading…</p>';
                cardModal.hidden = false;
                document.body.classList.add('lg-modal-open');
                cardSaveBtn.disabled = true;

                try {
                    const s = await getStripe();

                    // Create SetupIntent on server.
                    const { status: siStatus, body: siBody } = await postJson(SETUP_INTENT, {});
                    if (!siBody.ok) { showCardError(siBody.error || 'Could not start card update.'); return; }

                    cardElements = s.elements({ clientSecret: siBody.client_secret, appearance: { theme: 'stripe' } });
                    const pe = cardElements.create('payment');
                    cardPeEl.innerHTML = '';
                    pe.mount(cardPeEl);
                    pe.on('ready', () => { cardSaveBtn.disabled = false; });
                    pe.on('change', (e) => { if (e.complete) clearCardError(); });
                } catch (err) {
                    showCardError('Could not load card form: ' + err.message);
                }
            }

            function closeCardModal() {
                cardModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
                if (cardElements) { cardElements = null; cardPeEl.innerHTML = ''; }
                cardSaveBtn.disabled = true;
                clearCardError();
            }

            if (cardClose) cardClose.addEventListener('click', closeCardModal);
            cardModal?.querySelector('.lg-pay-modal__backdrop')?.addEventListener('click', closeCardModal);

            document.querySelectorAll('[data-lg-action="update-card"]').forEach(function(btn) {
                btn.addEventListener('click', openCardModal);
            });

            cardSaveBtn?.addEventListener('click', async function() {
                clearCardError();
                cardSaveBtn.disabled = true;
                cardSaveBtn.textContent = 'Saving…';
                try {
                    const s = await getStripe();
                    const { setupIntent, error } = await s.confirmSetup({
                        elements: cardElements,
                        confirmParams: {},
                        redirect: 'if_required',
                    });
                    if (error) { showCardError(error.message); cardSaveBtn.disabled = false; cardSaveBtn.textContent = 'Save card'; return; }

                    const pmId = typeof setupIntent.payment_method === 'string'
                        ? setupIntent.payment_method
                        : (setupIntent.payment_method?.id || '');
                    const { body } = await postJson(SET_DEFAULT_PM, { payment_method_id: pmId });
                    if (!body.ok) { showCardError(body.error || 'Card saved but could not set as default. Contact support.'); cardSaveBtn.disabled = false; cardSaveBtn.textContent = 'Save card'; return; }

                    closeCardModal();
                    await loadAndRenderPms();
                    showWalletFeedback('Payment method added and set as default.', false);
                } catch (err) {
                    showCardError('Network error: ' + err.message);
                    cardSaveBtn.disabled = false;
                    cardSaveBtn.textContent = 'Save card';
                }
            });

            // ── Payment method wallet ────────────────────────────────────
            const pmWalletList     = document.getElementById('lg-pm-wallet-list');
            const pmWalletFeedback = document.getElementById('lg-pm-wallet-feedback');
            const pmAddCardBtn     = document.getElementById('lg-pm-add-card');

            if (pmAddCardBtn) pmAddCardBtn.addEventListener('click', openCardModal);

            function showWalletFeedback(msg, isError) {
                if (!pmWalletFeedback) return;
                pmWalletFeedback.className = 'lg-pm-wallet__feedback ' + (isError ? 'lg-pm-wallet__feedback--error' : 'lg-pm-wallet__feedback--ok');
                pmWalletFeedback.textContent = msg;
                pmWalletFeedback.hidden = false;
                setTimeout(() => { pmWalletFeedback.hidden = true; }, 5000);
            }

            function brandDisplay(brand) {
                const map = { visa: 'VISA', mastercard: 'MC', amex: 'AMEX', discover: 'DISC', unionpay: 'UP', jcb: 'JCB', diners: 'DC' };
                return map[brand] || brand.replace(/_/g,' ').toUpperCase().slice(0,5);
            }

            function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

            function expiryMeta(month, year) {
                const now = new Date(), expMs = new Date(year, month) - now;
                if (expMs < 0) return { label: 'Expired ' + String(month).padStart(2,'0') + '/' + year, cls: 'lg-pm-wallet__expiry--expired' };
                if (expMs < 90 * 86400000) return { label: 'Expires soon — ' + String(month).padStart(2,'0') + '/' + year, cls: 'lg-pm-wallet__expiry--soon' };
                return { label: 'Expires ' + String(month).padStart(2,'0') + '/' + year, cls: '' };
            }

            function renderPms(pms) {
                if (!pmWalletList) return;
                if (!pms.length) {
                    pmWalletList.innerHTML = '<p class="lg-pm-wallet__empty">No payment methods saved yet.</p>';
                    return;
                }
                // Inline styles on layout-critical elements so BuddyBoss theme
                // class selectors can't override them (CSS !important only wins
                // when specificity ties; inline always wins specificity).
                const itemStyle    = 'display:flex;align-items:center;gap:1em;padding:0.85em 0;border-bottom:1px solid #f0f0f0;';
                const brandStyle   = 'flex-shrink:0;width:3.6em;text-align:center;font-weight:700;font-size:0.85em;padding:0.2em 0.45em;border:1px solid #ddd;border-radius:4px;background:#f8f9fa;font-style:normal;';
                const infoStyle    = 'flex:1;min-width:0;';
                const actionsStyle = 'display:flex;align-items:center;gap:0.65em;flex-shrink:0;';
                const sepStyle     = 'color:#ccc;user-select:none;';

                pmWalletList.innerHTML = pms.map(pm => {
                    const exp = expiryMeta(pm.exp_month, pm.exp_year);
                    const isOnly = pms.length === 1;
                    return `<div class="lg-pm-wallet__item${pm.is_default ? ' lg-pm-wallet__item--default' : ''}" data-pm-id="${pm.id}" style="${itemStyle}">
                        <div class="lg-pm-wallet__brand lg-pm-wallet__brand--${pm.brand}" style="${brandStyle}">${brandDisplay(pm.brand)}</div>
                        <div class="lg-pm-wallet__info" style="${infoStyle}">
                            <div class="lg-pm-wallet__name" style="font-weight:500;">
                                ${capitalize(pm.brand)} &bull;&bull;&bull;&bull; ${pm.last4}
                                ${pm.is_default ? '<span class="lg-pm-wallet__default-badge">Default</span>' : ''}
                            </div>
                            <div class="lg-pm-wallet__expiry ${exp.cls}">${exp.label}</div>
                        </div>
                        <div class="lg-pm-wallet__actions" style="${actionsStyle}">
                            ${!pm.is_default
                                ? `<button class="lg-pm-wallet__action" data-pm-action="make-default" data-pm-id="${pm.id}">Make default</button>
                                   <span class="lg-pm-wallet__sep" style="${sepStyle}">|</span>`
                                : ''}
                            <button class="lg-pm-wallet__action lg-pm-wallet__action--danger"
                                data-pm-action="remove" data-pm-id="${pm.id}"
                                ${isOnly ? 'disabled title="Cannot remove your only payment method"' : ''}>Remove</button>
                        </div>
                    </div>`;
                }).join('');

                pmWalletList.querySelectorAll('[data-pm-action="make-default"]').forEach(btn => {
                    btn.addEventListener('click', async function() {
                        const pmId = this.dataset.pmId;
                        this.disabled = true; this.textContent = 'Saving…';
                        const { body } = await postJson(SET_DEFAULT_PM, { payment_method_id: pmId });
                        if (body.ok) { await loadAndRenderPms(); showWalletFeedback('Default payment method updated.', false); }
                        else { showWalletFeedback(body.error || 'Could not update.', true); this.disabled = false; this.textContent = 'Make default'; }
                    });
                });

                pmWalletList.querySelectorAll('[data-pm-action="remove"]').forEach(btn => {
                    btn.addEventListener('click', async function() {
                        if (!confirm('Remove this card? This cannot be undone.')) return;
                        const pmId = this.dataset.pmId;
                        this.disabled = true; this.textContent = 'Removing…';
                        const { body } = await postJson(DELETE_PM, { payment_method_id: pmId });
                        if (body.ok) { await loadAndRenderPms(); showWalletFeedback('Card removed.', false); }
                        else { showWalletFeedback(body.error || 'Could not remove.', true); this.disabled = false; this.textContent = 'Remove'; }
                    });
                });
            }

            async function loadAndRenderPms() {
                if (!pmWalletList) return;
                pmWalletList.innerHTML = '<p class="lg-pm-wallet__loading">Loading payment methods…</p>';
                try {
                    const res  = await fetch(GET_PMS, { headers: { 'X-WP-Nonce': NONCE } });
                    const data = await res.json();
                    renderPms(data.ok ? (data.payment_methods || []) : []);
                    if (!data.ok) showWalletFeedback(data.error || 'Could not load payment methods.', true);
                } catch (err) {
                    pmWalletList.innerHTML = '<p class="lg-pm-wallet__empty">Could not load payment methods.</p>';
                }
            }

            loadAndRenderPms();

            // ── Billing history ──────────────────────────────────────────
            function fmtMoney(cents, currency) {
                try {
                    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(cents / 100);
                } catch (e) {
                    return '$' + (cents / 100).toFixed(2);
                }
            }
            function fmtInvDate(dateStr) {
                const d = new Date(dateStr + 'T12:00:00');
                return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
            function invStatusBadge(status) {
                const map = {
                    paid:           { bg: '#e8f7ec', color: '#1a5d2c', border: '#c1e6cc', label: 'Paid' },
                    open:           { bg: '#fef3c7', color: '#92400e', border: '#fcd34d', label: 'Open' },
                    void:           { bg: '#f3f4f6', color: '#6b7280', border: '#d1d5db', label: 'Void' },
                    uncollectible:  { bg: '#fde8e8', color: '#8a1c1c', border: '#f5b3b3', label: 'Failed' },
                    draft:          { bg: '#f3f4f6', color: '#6b7280', border: '#d1d5db', label: 'Draft' },
                };
                const s = map[status] || { bg: '#f3f4f6', color: '#6b7280', border: '#d1d5db', label: status };
                return `<span style="display:inline-block;flex-shrink:0;font-size:0.72em;font-weight:600;padding:0.15em 0.6em;border-radius:999px;background:${s.bg};color:${s.color};border:1px solid ${s.border};white-space:nowrap;">${s.label}</span>`;
            }
            function renderInvoices(invoices) {
                const list = document.getElementById('lg-billing-list');
                if (!list) return;
                if (!invoices.length) {
                    list.innerHTML = '<p style="padding:1.2em;color:#5b6066;font-size:0.92em;text-align:center;border:1px dashed #ddd;border-radius:8px;margin:0;">No billing history yet.</p>';
                    return;
                }
                const rowS  = 'display:flex;align-items:center;gap:0.75em;padding:0.65em 0;border-bottom:1px solid #f0f0f0;';
                const dateS = 'flex-shrink:0;width:6.5em;font-size:0.87em;color:#5b6066;';
                const descS = 'flex:1;min-width:0;font-size:0.92em;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                const amtS  = 'flex-shrink:0;font-weight:600;font-size:0.92em;';
                const pdfS  = 'flex-shrink:0;font-size:0.85em;color:#0066c0;text-decoration:none;white-space:nowrap;';
                list.innerHTML = invoices.map(inv => {
                    const pdf = inv.invoice_pdf
                        ? `<a href="${inv.invoice_pdf}" target="_blank" rel="noopener" style="${pdfS}">PDF ↓</a>`
                        : '';
                    return `<div style="${rowS}">
                        <span style="${dateS}">${fmtInvDate(inv.date)}</span>
                        <span style="${descS}" title="${inv.description}">${inv.description || '—'}</span>
                        <span style="${amtS}">${fmtMoney(inv.amount, inv.currency)}</span>
                        ${invStatusBadge(inv.status)}
                        ${pdf}
                    </div>`;
                }).join('');
            }
            async function loadAndRenderInvoices() {
                const list = document.getElementById('lg-billing-list');
                if (!list) return;
                list.innerHTML = '<p style="padding:0.5em 0;color:#5b6066;font-size:0.92em;font-style:italic;">Loading…</p>';
                try {
                    const res  = await fetch(GET_INVOICES, { headers: { 'X-WP-Nonce': NONCE } });
                    const data = await res.json();
                    renderInvoices(data.ok ? (data.invoices || []) : []);
                    if (!data.ok) list.innerHTML = `<p style="color:#8a1c1c;font-size:0.92em;">${data.error || 'Could not load billing history.'}</p>`;
                } catch (err) {
                    list.innerHTML = '<p style="color:#8a1c1c;font-size:0.92em;">Could not load billing history.</p>';
                }
            }
            loadAndRenderInvoices();

            let products = null;

            async function loadProducts() {
                if (products !== null) return products;
                try {
                    const res  = await fetch(PROD);
                    const data = await res.json();
                    products = Array.isArray(data) ? data : (data.products || []);
                } catch (e) {
                    products = [];
                }
                return products;
            }

            function showResult(card, html, isError) {
                const el = card.querySelector('[data-lg-result]');
                el.innerHTML = '<div style="padding:8px 12px;border-radius:4px;background:' + (isError ? '#fde8e8' : '#e8f7ec') + ';color:' + (isError ? '#900' : '#080') + ';">' + html + '</div>';
            }

            async function postJson(url, payload) {
                const res = await fetch(url, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify(payload),
                });
                return { status: res.status, body: await res.json() };
            }

            // Cancel button → reveal cancel section
            document.querySelectorAll('[data-lg-action="cancel"]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    const card = btn.closest('.lg-manage-sub__card');
                    card.querySelector('[data-lg-canceller]').style.display = 'block';
                    card.querySelector('[data-lg-switcher]').style.display = 'none';
                });
            });
            document.querySelectorAll('[data-lg-action="cancel-back"]').forEach(function(btn){
                btn.addEventListener('click', function(){
                    btn.closest('[data-lg-canceller]').style.display = 'none';
                });
            });

            // Cancel confirm
            document.querySelectorAll('[data-lg-action="cancel-confirm"]').forEach(function(btn){
                btn.addEventListener('click', async function(){
                    const card = btn.closest('.lg-manage-sub__card');
                    const subId = btn.dataset.lgSub;
                    const when = card.querySelector('input[name="cancel-when-' + subId + '"]:checked').value;
                    const immediate = when === 'immediate';
                    if (!confirm('Cancel this subscription ' + (immediate ? 'immediately (you will lose access right away)' : 'at the end of your current billing period') + '?')) return;
                    btn.disabled = true;
                    btn.textContent = 'Working...';
                    try {
                        const { status, body } = await postJson(CANCEL, { sub_id: subId, immediate: immediate });
                        if (status === 200 && body.ok) {
                            showResult(card, body.message, false);
                            card.querySelector('[data-lg-canceller]').style.display = 'none';
                            // Visually mark the card as scheduled-for-cancellation.
                            card.style.opacity = '0.7';
                        } else {
                            showResult(card, body.error || 'Could not cancel.', true);
                            btn.disabled = false;
                            btn.textContent = 'Confirm cancellation';
                        }
                    } catch (err) {
                        showResult(card, 'Network error: ' + err.message, true);
                        btn.disabled = false;
                        btn.textContent = 'Confirm cancellation';
                    }
                });
            });

            // Switch plan button → load products + reveal picker
            document.querySelectorAll('[data-lg-action="switch"]').forEach(function(btn){
                btn.addEventListener('click', async function(){
                    const card = btn.closest('.lg-manage-sub__card');
                    const switcher = card.querySelector('[data-lg-switcher]');
                    const plansEl  = card.querySelector('[data-lg-plans]');
                    switcher.style.display = 'block';
                    card.querySelector('[data-lg-canceller]').style.display = 'none';
                    const list = await loadProducts();
                    const currentPriceId = btn.dataset.lgCurrentPrice;
                    const subId = btn.dataset.lgSub;

                    // Flatten product → price options. Skip one-time prices (no recurring interval).
                    const rows = [];
                    (Array.isArray(list) ? list : []).forEach(p => {
                        (p.prices || []).forEach(pr => {
                            if (!pr.interval) return; // skip one-time
                            rows.push({
                                product:  p.name,
                                priceId:  pr.stripe_price_id,
                                interval: pr.interval,
                                amount:   pr.unit_amount_cents,
                                currency: (pr.currency || 'USD').toUpperCase(),
                                isCurrent: pr.stripe_price_id === currentPriceId,
                            });
                        });
                    });
                    if (rows.length === 0) {
                        plansEl.innerHTML = '<em>No plans available.</em>';
                        return;
                    }
                    const currentRow = rows.find(r => r.isCurrent);
                    const currentLabel = currentRow
                        ? currentRow.product + ' &mdash; $' + (currentRow.amount / 100).toFixed(2) + '/' + currentRow.interval
                        : 'your current plan';
                    const renewDate = card.querySelector('[data-lg-renew-date]')?.textContent || 'your next renewal date';

                    function buildTimingSection(selectedRow) {
                        if (!selectedRow) {
                            return '<fieldset style="margin-top:1em;border:1px solid #eee;padding:0.8em 1em;color:#888;">' +
                                '<legend>When should the change take effect?</legend>' +
                                '<em>Select a plan above to see timing options.</em>' +
                            '</fieldset>';
                        }
                        const isUpgrade = selectedRow.amount > (currentRow ? currentRow.amount : 0);
                        if (isUpgrade) {
                            return '<fieldset style="margin-top:1em;border:1px solid #eee;padding:0.8em 1em;">' +
                                '<legend>When should the change take effect?</legend>' +
                                '<label style="display:block;margin:0.3em 0;">' +
                                    '<input type="radio" name="timing-' + subId + '" value="now" checked> ' +
                                    '<strong>Switch now</strong> &mdash; the full $' + (selectedRow.amount / 100).toFixed(2) + '/' + selectedRow.interval + ' price is charged today and your new plan starts immediately.' +
                                '</label>' +
                            '</fieldset>';
                        } else {
                            return '<fieldset style="margin-top:1em;border:1px solid #eee;padding:0.8em 1em;">' +
                                '<legend>When should the change take effect?</legend>' +
                                '<label style="display:block;margin:0.3em 0;">' +
                                    '<input type="radio" name="timing-' + subId + '" value="period_end" checked> ' +
                                    '<strong>Switch on ' + renewDate + '</strong> &mdash; no charge today; your current plan continues until then.' +
                                '</label>' +
                            '</fieldset>';
                        }
                    }

                    plansEl.innerHTML =
                        '<p style="margin:0 0 0.6em;font-size:0.9em;color:#555;">Current plan: <strong>' + currentLabel + '</strong></p>' +
                        rows.map(r => {
                            const dollars = (r.amount / 100).toFixed(2);
                            const label = r.product + ' &mdash; $' + dollars + '/' + r.interval + (r.isCurrent ? ' <em>(current)</em>' : '');
                            const disabled = r.isCurrent ? ' disabled' : '';
                            return '<label style="display:block;padding:0.3em 0;">' +
                                '<input type="radio" name="newprice-' + subId + '" value="' + r.priceId + '" data-amount="' + r.amount + '" data-interval="' + r.interval + '" data-label="' + r.product + '"' + disabled + '> ' +
                                label + '</label>';
                        }).join('') +
                        '<div data-lg-timing>' + buildTimingSection(null) + '</div>' +
                        '<div style="margin-top:1em;">' +
                            '<button type="button" class="lg-manage-sub__btn" data-lg-action="switch-confirm" data-lg-sub="' + subId + '">Confirm change</button> ' +
                            '<button type="button" class="lg-manage-sub__btn" data-lg-action="switch-back">Never mind</button>' +
                        '</div>';

                    // Update timing section whenever a plan radio changes.
                    plansEl.querySelectorAll('input[name="newprice-' + subId + '"]').forEach(function(radio) {
                        radio.addEventListener('change', function() {
                            const selectedRow = { amount: parseInt(this.dataset.amount, 10), interval: this.dataset.interval, product: this.dataset.label };
                            plansEl.querySelector('[data-lg-timing]').innerHTML = buildTimingSection(selectedRow);
                        });
                    });

                    plansEl.parentElement.querySelector('[data-lg-action="switch-back"]').addEventListener('click', function(){
                        switcher.style.display = 'none';
                    });
                    plansEl.parentElement.querySelector('[data-lg-action="switch-confirm"]').addEventListener('click', async function(ev){
                        const picked = card.querySelector('input[name="newprice-' + subId + '"]:checked');
                        const timing = (card.querySelector('input[name="timing-' + subId + '"]:checked') || {}).value || 'now';
                        if (!picked) {
                            showResult(card, 'Pick a plan first.', true);
                            return;
                        }
                        const pickedLabel = picked.dataset.label + ' — $' + (parseInt(picked.dataset.amount, 10) / 100).toFixed(2) + '/' + picked.dataset.interval;
                        const msg = timing === 'now'
                            ? 'Upgrade to ' + pickedLabel + '? You will be charged the full price today and your new plan starts immediately.'
                            : 'Schedule the switch to ' + pickedLabel + ' for ' + renewDate + '? Your current plan continues until then and no charge today.';
                        if (!confirm(msg)) return;
                        ev.target.disabled = true;
                        ev.target.textContent = 'Working...';
                        try {
                            const { status, body } = await postJson(SWITCH, { sub_id: subId, new_price_id: picked.value, timing: timing });
                            if (status === 200 && body.ok) {
                                showResult(card, body.message + ' Reload the page to see the updated state.', false);
                                switcher.style.display = 'none';
                            } else {
                                showResult(card, body.error || 'Could not switch plans.', true);
                                ev.target.disabled = false;
                                ev.target.textContent = 'Confirm change';
                            }
                        } catch (err) {
                            showResult(card, 'Network error: ' + err.message, true);
                            ev.target.disabled = false;
                            ev.target.textContent = 'Confirm change';
                        }
                    });
                });
            });

            // Stripe portal link (for card / invoice management).
            const portalLink = document.querySelector('[data-lg-portal]');
            const portalErr  = document.querySelector('[data-lg-portal-error]');
            if (portalLink) {
                portalLink.addEventListener('click', async function(e){
                    e.preventDefault();
                    portalErr.textContent = '';
                    portalLink.textContent = 'Opening...';
                    try {
                        const res = await fetch(PORTAL, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({ email: EMAIL }),
                        });
                        const data = await res.json();
                        if (data.url) {
                            window.open(data.url, '_blank', 'noopener');
                            portalLink.textContent = 'Open the Stripe billing portal →';
                            return;
                        }
                        portalErr.textContent = data.error || 'Could not open portal.';
                    } catch (err) {
                        portalErr.textContent = 'Network error: ' + err.message;
                    } finally {
                        portalLink.textContent = 'Open the Stripe billing portal →';
                    }
                });
            }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [lg_join] — tier picker with sub / one-time options. Posts to
     * /v1/checkout, mounts Stripe embedded checkout. Reads ?promo= URL
     * param and threads it through. Pre-fills email + name from logged-in
     * WP user.
     */
    public static function join( $atts = [] ): string
    {
        $atts = shortcode_atts( [
            'heading'          => 'Choose your membership',
            'subheading'       => '',
            'bullets'          => '',        // pipe-separated
            'popular'          => 'looth3',  // product ref to mark "Most popular"
            'taglines'         => '',        // ref:tagline pipe-separated
            'features_looth2'  => 'Member forums & community|Interviews with guests|AMAs',
            'features_looth3'  => 'Everything in LITE|Demo-based content|Live session archives',
        ], (array) $atts, 'lg_join' );

        $user        = wp_get_current_user();
        $isLoggedIn  = $user->ID > 0;
        $emailValue  = $isLoggedIn ? (string) $user->user_email : '';
        $nameValue   = $isLoggedIn ? trim( (string) ( $user->display_name ?: $user->user_login ) ) : '';

        // If the logged-in user already has an active subscription, point them
        // at the Stripe Customer Portal instead of letting them double-buy.
        $activeSub = $isLoggedIn && $emailValue !== '' ? self::lookupActiveSub( $emailValue ) : null;
        if ( $activeSub !== null ) {
            return self::renderActiveSubBlock( $activeSub );
        }

        $base       = rtrim( (string) home_url( '/billing' ), '/' );
        $endpoints  = [
            'products'       => esc_url_raw( $base . '/v1/products' ),
            'config'         => esc_url_raw( $base . '/v1/config' ),
            'checkout'       => esc_url_raw( $base . '/v1/checkout' ),
            'affiliateClick' => esc_url_raw( $base . '/v1/affiliate-click' ),
        ];

        $promoFromUrl = isset( $_GET['promo'] ) ? (string) $_GET['promo'] : '';
        $promoFromUrl = preg_replace( '/[^A-Za-z0-9_\-]/', '', $promoFromUrl );

        // Country override via ?country=XX URL param.
        $countryFromUrl = isset( $_GET['country'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $_GET['country'] ) ) : '';
        if ( strlen( $countryFromUrl ) !== 2 ) {
            $countryFromUrl = '';
        }

        // ?preview=single — shows single-tier mockup for internal team review.
        $previewSingle = isset( $_GET['preview'] ) && $_GET['preview'] === 'single';

        $heading      = esc_html( (string) $atts['heading'] );
        $subheading   = esc_html( (string) $atts['subheading'] );
        $bulletsRaw   = trim( (string) $atts['bullets'] );
        $bullets      = $bulletsRaw !== '' ? array_filter( array_map( 'trim', explode( '|', $bulletsRaw ) ) ) : [];
        $popularRef   = (string) $atts['popular'];
        $taglinesRaw  = trim( (string) $atts['taglines'] );
        $taglineMap   = [];
        if ( $taglinesRaw !== '' ) {
            foreach ( explode( '|', $taglinesRaw ) as $pair ) {
                $parts = explode( ':', $pair, 2 );
                if ( count( $parts ) === 2 ) {
                    $taglineMap[ trim( $parts[0] ) ] = trim( $parts[1] );
                }
            }
        }

        // Build per-tier feature lists from shortcode atts.
        $featuresMap = [];
        foreach ( [ 'looth1', 'looth2', 'looth3', 'looth4' ] as $ref ) {
            $key = "features_{$ref}";
            if ( ! empty( $atts[ $key ] ) ) {
                $featuresMap[ $ref ] = array_values( array_filter( array_map( 'trim', explode( '|', (string) $atts[ $key ] ) ) ) );
            }
        }

        $email        = esc_attr( $emailValue );
        $name         = esc_attr( $nameValue );
        $promoEsc     = esc_attr( (string) $promoFromUrl );
        $endpointsJs  = wp_json_encode( $endpoints );
        $authUrl      = esc_url_raw( rest_url( 'lg-member-sync/v1/gift-auth' ) );
        $configJs     = wp_json_encode( [
            'popular'       => $popularRef,
            'taglines'      => $taglineMap,
            'features'      => $featuresMap,
            'loggedIn'      => $isLoggedIn,
            'authUrl'       => $authUrl,
            'forgotUrl'     => wp_lostpassword_url(),
            'previewSingle' => $previewSingle,
        ] );

        ob_start();
        ?>
        <div class="lg-join">
            <header class="lg-join__hero">
                <h2><?php echo $heading; ?></h2>
                <?php if ( $subheading !== '' ) : ?>
                    <p><?php echo $subheading; ?></p>
                <?php endif; ?>
                <?php if ( $bullets !== [] ) : ?>
                    <ul>
                        <?php foreach ( $bullets as $b ) : ?>
                            <li><?php echo esc_html( $b ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </header>

            <div class="lg-join__trial-banner" data-lg-trial-banner hidden>
                <span class="lg-join__trial-banner-inner">&#10003; 7-day free trial on all plans &mdash; no charge until day 8</span>
            </div>

            <?php if ( $previewSingle ) : ?>
            <div class="lg-join__preview-bar">
                <span>&#9888; Preview mode: single tier</span>
                <a href="<?php echo esc_url( remove_query_arg( 'preview' ) ); ?>" class="lg-join__preview-exit">Exit preview</a>
            </div>
            <?php else : ?>
            <div class="lg-join__preview-bar lg-join__preview-bar--hint">
                <a href="<?php echo esc_url( add_query_arg( 'preview', 'single' ) ); ?>" class="lg-join__preview-link">&#128065; Preview single-tier layout</a>
            </div>
            <?php endif; ?>

            <div class="lg-join__region-note" data-lg-region-note hidden></div>

            <div class="lg-join__tiers" data-lg-join-tiers>
                <p class="lg-join__loading">Loading plans&hellip;</p>
            </div>

            <div class="lg-pay-methods lg-join__pay-methods-bar" aria-label="Accepted payment methods">
                <span class="lg-pay-methods__label">Secure checkout &mdash; we accept</span>
                <ul class="lg-pay-methods__list">
                    <li class="lg-pm lg-pm--visa" title="Visa"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="13" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-style="italic" font-size="13" fill="#fff" letter-spacing="0.5">VISA</text></svg></li>
                    <li class="lg-pm lg-pm--mc" title="Mastercard"><svg viewBox="0 0 32 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="13" cy="10" r="6.5" fill="#EB001B"/><circle cx="19" cy="10" r="6.5" fill="#F79E1B" fill-opacity=".95"/><path d="M16 5.2a6.5 6.5 0 0 0 0 9.6 6.5 6.5 0 0 0 0-9.6z" fill="#FF5F00"/></svg></li>
                    <li class="lg-pm lg-pm--amex" title="American Express"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="12" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-size="9" fill="#fff" letter-spacing="0.5">AMEX</text></svg></li>
                    <li class="lg-pm lg-pm--apple" title="Apple Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#fff" d="M11.6 6.1c-.7.8-1.7 1.5-2.8 1.4-.1-1.1.4-2.2 1-2.9.7-.8 1.9-1.4 2.8-1.4.1 1.1-.4 2.2-1 2.9zm1 1.6c-1.6-.1-2.9.9-3.7.9-.7 0-1.9-.9-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 2.9-.4 7.2 1.2 9.5.8 1.1 1.7 2.4 3 2.4 1.2-.1 1.7-.8 3.2-.8 1.5 0 1.9.8 3.2.7 1.3 0 2.2-1.2 3-2.3.9-1.3 1.3-2.6 1.3-2.7-.1 0-2.5-1-2.6-3.8 0-2.4 2-3.5 2.1-3.6-1.1-1.6-2.8-1.8-3.7-1.9z"/><text x="22" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#fff">Pay</text></svg></li>
                    <li class="lg-pm lg-pm--google" title="Google Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M9.4 11.4v3.5h-1.1V6.3h2.9c.7 0 1.4.3 1.9.8.5.5.8 1.2.8 1.9 0 .7-.3 1.4-.8 1.9-.5.5-1.1.8-1.9.8h-1.8zm0-4.1v3.1h1.9c.4 0 .8-.2 1.1-.5.6-.6.6-1.5 0-2.1-.3-.3-.7-.5-1.1-.5h-1.9zm6.4 1.2c.8 0 1.5.2 2 .7.5.4.7 1 .7 1.8v3.6h-1.1v-.8h-.1c-.5.7-1.1 1-1.8 1-.6 0-1.1-.2-1.5-.6-.4-.3-.6-.8-.6-1.3 0-.6.2-1 .6-1.4.4-.3 1-.5 1.7-.5.6 0 1.1.1 1.5.3v-.3c0-.4-.1-.7-.4-1-.3-.3-.6-.4-1-.4-.6 0-1.1.3-1.5.8l-1-.6c.5-.8 1.3-1.3 2.5-1.3zm-1.5 4.4c0 .2.1.4.3.6.2.2.4.2.7.2.4 0 .8-.1 1.1-.4.3-.3.5-.7.5-1.1-.3-.3-.8-.4-1.4-.4-.4 0-.8.1-1 .3-.2.2-.2.5-.2.8zm10.4-4.2L21.1 17h-1.2l1.4-3-2.4-5.3h1.2l1.7 4.1 1.7-4.1h1.2z" fill="#5F6368"/><path d="M33 10.7c0-.3 0-.6-.1-.9h-4.5v1.7h2.6c-.1.6-.4 1.1-.9 1.4v1.2h1.5c.9-.8 1.4-2 1.4-3.4z" fill="#4285F4"/><path d="M28.4 15.5c1.3 0 2.4-.4 3.2-1.2l-1.5-1.2c-.4.3-1 .5-1.7.5-1.3 0-2.4-.9-2.8-2h-1.6v1.2c.8 1.6 2.5 2.7 4.4 2.7z" fill="#34A853"/><path d="M25.6 11.6c-.2-.6-.2-1.3 0-1.9V8.5H24c-.7 1.3-.7 2.9 0 4.3l1.6-1.2z" fill="#FBBC04"/><path d="M28.4 7.6c.7 0 1.4.3 1.9.7l1.4-1.4c-.9-.8-2.1-1.3-3.3-1.2-1.9 0-3.6 1.1-4.4 2.7l1.6 1.2c.4-1.1 1.4-2 2.8-2z" fill="#EA4335"/><text x="36" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#5F6368">Pay</text></svg></li>
                </ul>
            </div>

            <!-- Sign-up modal — uses .lg-pay-modal which is proven to work against BuddyBoss -->
            <div class="lg-pay-modal" data-lg-signup-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-signup-modal-title">
                <div class="lg-pay-modal__backdrop" data-lg-signup-close></div>
                <div class="lg-pay-modal__card lg-pay-modal__card--signup">
                    <button type="button" class="lg-pay-modal__close" data-lg-signup-close aria-label="Close">&times;</button>
                    <div class="lg-pay-modal__body lg-pay-modal__body--signup">
                    <div class="lg-join__form" data-lg-join-form>
                        <h3 class="lg-join__form-heading" id="lg-signup-modal-title" data-lg-form-heading>Almost there</h3>
                        <div class="lg-join__form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1em 1.2em;">
                            <?php if ( $isLoggedIn ) : ?>
                            <div class="lg-join__field" style="grid-column: 1 / -1;">
                                <label>Email</label>
                                <div class="lg-join__static" style="padding:.55em .75em;background:#f4f4f0;border:1px solid rgba(0,0,0,0.1);border-radius:6px;color:#333;font-size:.95em;">
                                    <strong><?php echo $email; ?></strong>
                                    <span style="float:right;font-size:.82em;color:#888;">signed in</span>
                                </div>
                                <input type="hidden" name="email" value="<?php echo $email; ?>">
                                <input type="hidden" name="email_confirm" value="<?php echo $email; ?>">
                            </div>
                            <?php else : ?>
                            <div class="lg-join__field">
                                <label>Email <input type="email" name="email" value="<?php echo $email; ?>" autocomplete="email" required></label>
                            </div>
                            <div class="lg-join__field">
                                <label>Confirm email <input type="email" name="email_confirm" value="<?php echo $email; ?>" autocomplete="email" required></label>
                                <small data-lg-email-mismatch class="lg-pwd-mismatch" hidden>Emails don&rsquo;t match.</small>
                            </div>
                            <?php endif; ?>
                            <?php if ( ! $isLoggedIn ) : ?>
                            <div class="lg-join__field">
                                <label>Password
                                    <span class="lg-pwd-wrap">
                                        <input type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="Pick a password (8+ characters)">
                                        <button type="button" class="lg-pwd-eye" data-lg-pwd-eye-for="password" aria-label="Show password"><svg class="lg-pwd-eye__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                    </span>
                                </label>
                                <small>This becomes your account password so you can log in any time to manage your subscription.</small>
                            </div>
                            <div class="lg-join__field">
                                <label>Confirm password
                                    <span class="lg-pwd-wrap">
                                        <input type="password" name="password_confirm" minlength="8" required autocomplete="new-password" placeholder="Re-enter your password">
                                        <button type="button" class="lg-pwd-eye" data-lg-pwd-eye-for="password_confirm" aria-label="Show password"><svg class="lg-pwd-eye__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                    </span>
                                </label>
                                <small data-lg-pwd-mismatch class="lg-pwd-mismatch" hidden>Passwords don&rsquo;t match.</small>
                            </div>
                            <?php endif; ?>
                            <?php if ( $isLoggedIn ) : ?>
                            <div class="lg-join__field" style="grid-column: 1 / -1;">
                                <label>Profile name <em style="opacity:.6;font-weight:400;">(what other members will see)</em></label>
                                <div class="lg-join__static" style="padding:.55em .75em;background:#f4f4f0;border:1px solid rgba(0,0,0,0.1);border-radius:6px;color:#333;font-size:.95em;">
                                    <strong><?php echo $name; ?></strong>
                                </div>
                                <input type="hidden" name="name" value="<?php echo $name; ?>">
                                <small>Edit your profile name from your <a href="<?php echo esc_url( home_url( '/manage-subscription/' ) ); ?>">membership page</a>.</small>
                            </div>
                            <?php else : ?>
                            <div class="lg-join__field" style="grid-column: 1 / -1;">
                                <label>Profile name <em style="opacity:.6;font-weight:400;">(what other members will see)</em>
                                    <input type="text" name="name" value="<?php echo $name; ?>" required>
                                </label>
                                <small>This is the name other members see in forums, comments, and the activity feed &mdash; not optional.</small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <details class="lg-join__discount" <?php echo $promoFromUrl !== '' ? 'open' : ''; ?>>
                            <summary>Have a discount code?</summary>
                            <div class="lg-join__discount-row">
                                <input type="text" name="promo_code" placeholder="e.g. PATREON5" value="<?php echo $promoEsc; ?>" autocomplete="off" maxlength="64">
                                <small data-lg-promo-status></small>
                            </div>
                        </details>
                        <div class="lg-join__form-actions">
                            <button type="button" class="lg-join__continue is-primary" data-lg-continue>Continue to checkout</button>
                            <button type="button" class="lg-join__back" data-lg-back>Change plan</button>
                        </div>
                    </div>
                    <div class="lg-pay-methods lg-pay-modal__pay-methods" aria-label="Accepted payment methods">
                        <span class="lg-pay-methods__label">Secure checkout &mdash; we accept</span>
                        <ul class="lg-pay-methods__list">
                            <li class="lg-pm lg-pm--visa" title="Visa"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="13" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-style="italic" font-size="13" fill="#fff" letter-spacing="0.5">VISA</text></svg></li>
                            <li class="lg-pm lg-pm--mc" title="Mastercard"><svg viewBox="0 0 32 20" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><circle cx="13" cy="10" r="6.5" fill="#EB001B"/><circle cx="19" cy="10" r="6.5" fill="#F79E1B" fill-opacity=".95"/><path d="M16 5.2a6.5 6.5 0 0 0 0 9.6 6.5 6.5 0 0 0 0-9.6z" fill="#FF5F00"/></svg></li>
                            <li class="lg-pm lg-pm--amex" title="American Express"><svg viewBox="0 0 48 16" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><text x="24" y="12" text-anchor="middle" font-family="Arial Black, Helvetica, sans-serif" font-weight="900" font-size="9" fill="#fff" letter-spacing="0.5">AMEX</text></svg></li>
                            <li class="lg-pm lg-pm--apple" title="Apple Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="#fff" d="M11.6 6.1c-.7.8-1.7 1.5-2.8 1.4-.1-1.1.4-2.2 1-2.9.7-.8 1.9-1.4 2.8-1.4.1 1.1-.4 2.2-1 2.9zm1 1.6c-1.6-.1-2.9.9-3.7.9-.7 0-1.9-.9-3.1-.8-1.6 0-3.1.9-3.9 2.4-1.7 2.9-.4 7.2 1.2 9.5.8 1.1 1.7 2.4 3 2.4 1.2-.1 1.7-.8 3.2-.8 1.5 0 1.9.8 3.2.7 1.3 0 2.2-1.2 3-2.3.9-1.3 1.3-2.6 1.3-2.7-.1 0-2.5-1-2.6-3.8 0-2.4 2-3.5 2.1-3.6-1.1-1.6-2.8-1.8-3.7-1.9z"/><text x="22" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#fff">Pay</text></svg></li>
                            <li class="lg-pm lg-pm--google" title="Google Pay"><svg viewBox="0 0 60 22" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M9.4 11.4v3.5h-1.1V6.3h2.9c.7 0 1.4.3 1.9.8.5.5.8 1.2.8 1.9 0 .7-.3 1.4-.8 1.9-.5.5-1.1.8-1.9.8h-1.8zm0-4.1v3.1h1.9c.4 0 .8-.2 1.1-.5.6-.6.6-1.5 0-2.1-.3-.3-.7-.5-1.1-.5h-1.9zm6.4 1.2c.8 0 1.5.2 2 .7.5.4.7 1 .7 1.8v3.6h-1.1v-.8h-.1c-.5.7-1.1 1-1.8 1-.6 0-1.1-.2-1.5-.6-.4-.3-.6-.8-.6-1.3 0-.6.2-1 .6-1.4.4-.3 1-.5 1.7-.5.6 0 1.1.1 1.5.3v-.3c0-.4-.1-.7-.4-1-.3-.3-.6-.4-1-.4-.6 0-1.1.3-1.5.8l-1-.6c.5-.8 1.3-1.3 2.5-1.3zm-1.5 4.4c0 .2.1.4.3.6.2.2.4.2.7.2.4 0 .8-.1 1.1-.4.3-.3.5-.7.5-1.1-.3-.3-.8-.4-1.4-.4-.4 0-.8.1-1 .3-.2.2-.2.5-.2.8zm10.4-4.2L21.1 17h-1.2l1.4-3-2.4-5.3h1.2l1.7 4.1 1.7-4.1h1.2z" fill="#5F6368"/><path d="M33 10.7c0-.3 0-.6-.1-.9h-4.5v1.7h2.6c-.1.6-.4 1.1-.9 1.4v1.2h1.5c.9-.8 1.4-2 1.4-3.4z" fill="#4285F4"/><path d="M28.4 15.5c1.3 0 2.4-.4 3.2-1.2l-1.5-1.2c-.4.3-1 .5-1.7.5-1.3 0-2.4-.9-2.8-2h-1.6v1.2c.8 1.6 2.5 2.7 4.4 2.7z" fill="#34A853"/><path d="M25.6 11.6c-.2-.6-.2-1.3 0-1.9V8.5H24c-.7 1.3-.7 2.9 0 4.3l1.6-1.2z" fill="#FBBC04"/><path d="M28.4 7.6c.7 0 1.4.3 1.9.7l1.4-1.4c-.9-.8-2.1-1.3-3.3-1.2-1.9 0-3.6 1.1-4.4 2.7l1.6 1.2c.4-1.1 1.4-2 2.8-2z" fill="#EA4335"/><text x="36" y="14.5" font-family="Helvetica Neue, Helvetica, Arial, sans-serif" font-weight="500" font-size="9" fill="#5F6368">Pay</text></svg></li>
                        </ul>
                    </div>
                    </div>
                </div>
            </div>

            <div class="lg-pay-modal" data-lg-join-checkout-modal hidden role="dialog" aria-modal="true" aria-label="Secure checkout">
                <div class="lg-pay-modal__backdrop" data-lg-join-checkout-close></div>
                <div class="lg-pay-modal__card">
                    <button type="button" class="lg-pay-modal__close" data-lg-join-checkout-close aria-label="Close checkout">&times;</button>

                    <!-- Embedded path (one-time + regional setup): Stripe owns the iframe + Pay button. -->
                    <div class="lg-pay-modal__body" data-lg-join-checkout></div>

                    <!-- In-modal processing overlay: covers the whole card while
                         confirm() runs so the user sees an unmistakable "we're
                         working on it" state instead of a frozen-looking form. -->
                    <div class="lg-modal-processing" data-lg-join-modal-processing hidden aria-hidden="true">
                        <div class="lg-modal-processing__spinner" aria-hidden="true"></div>
                        <p class="lg-modal-processing__label">Processing payment&hellip;</p>
                    </div>

                    <!-- Custom path (subscription): we mount Stripe Elements ourselves and render our own Pay button. -->
                    <div class="lg-pay-modal__custom lg-stripe-modal" data-lg-join-checkout-custom hidden>
                        <div class="lg-stripe-modal__header">
                            <div class="lg-stripe-modal__heading">Complete your purchase</div>
                            <div class="lg-stripe-modal__amount" data-lg-pay-amount>&nbsp;</div>
                            <div class="lg-stripe-modal__sublabel" data-lg-pay-sublabel>&nbsp;</div>
                        </div>
                        <div class="lg-stripe-modal__pe" data-lg-join-payment-element></div>
                        <div class="lg-stripe-modal__error" data-lg-join-checkout-error role="alert" hidden></div>
                        <button type="button" class="lg-stripe-modal__pay" data-lg-join-checkout-pay disabled>
                            <span data-lg-pay-label>Pay</span>
                        </button>
                        <p class="lg-stripe-modal__secured">
                            <svg class="lg-stripe-modal__secured-lock" xmlns="http://www.w3.org/2000/svg" width="11" height="13" viewBox="0 0 11 13" aria-hidden="true"><path fill="currentColor" d="M5.5 0C3.567 0 2 1.567 2 3.5V5h-.5A1.5 1.5 0 0 0 0 6.5v5A1.5 1.5 0 0 0 1.5 13h8a1.5 1.5 0 0 0 1.5-1.5v-5A1.5 1.5 0 0 0 9.5 5H9V3.5C9 1.567 7.433 0 5.5 0Zm0 1.5A2 2 0 0 1 7.5 3.5V5h-4V3.5a2 2 0 0 1 2-2Z"/></svg>
                            <span>Powered by</span>
                            <svg class="lg-stripe-modal__secured-mark" xmlns="http://www.w3.org/2000/svg" width="42" height="18" viewBox="0 0 60 25" aria-hidden="true" role="img" aria-label="Stripe"><path fill="#635BFF" d="M59.64 14.28h-8.06c.19 1.93 1.6 2.55 3.2 2.55 1.64 0 2.96-.37 4.05-.95v3.32a8.33 8.33 0 0 1-4.56 1.1c-4.01 0-6.83-2.5-6.83-7.48 0-4.19 2.39-7.52 6.3-7.52 3.92 0 5.96 3.28 5.96 7.5 0 .4-.04 1.26-.06 1.48zm-5.92-5.62c-1.03 0-2.17.73-2.17 2.58h4.25c0-1.85-1.07-2.58-2.08-2.58zM40.95 20.3c-1.44 0-2.32-.6-2.9-1.04l-.02 4.63-4.12.87V5.57h3.76l.08 1.02a4.7 4.7 0 0 1 3.23-1.29c2.9 0 5.62 2.6 5.62 7.4 0 5.23-2.7 7.6-5.65 7.6zM40 9.04c-.95 0-1.54.34-1.97.81l.02 6.12c.4.44.98.78 1.95.78 1.52 0 2.54-1.65 2.54-3.87 0-2.15-1.04-3.84-2.54-3.84zM28.24 5.57h4.13v14.44h-4.13V5.57zm0-4.7L32.37 0v3.36l-4.13.88V.88zm-4.32 9.35v9.79H19.8V5.57h3.7l.12 1.22c1-1.77 3.07-1.41 3.62-1.22v3.79c-.52-.17-2.29-.43-3.32.86zm-8.55 4.72c0 2.43 2.6 1.68 3.12 1.46v3.36c-.55.3-1.54.54-2.89.54a4.15 4.15 0 0 1-4.27-4.24l.01-13.17 4.02-.86v3.54h3.14V9.1h-3.14l.01 5.85zm-4.91.7c0 2.97-2.31 4.66-5.73 4.66a11.2 11.2 0 0 1-4.46-.93v-3.93c1.38.75 3.1 1.31 4.46 1.31.92 0 1.53-.24 1.53-1C6.26 13.77 0 14.51 0 9.95 0 7.04 2.28 5.3 5.62 5.3c1.36 0 2.72.2 4.09.75v3.88a9.23 9.23 0 0 0-4.1-1.06c-.86 0-1.44.25-1.44.93 0 1.85 6.29.97 6.29 5.83z"/></svg>
                        </p>
                    </div>
                </div>
            </div>

            <div class="lg-processing" data-lg-join-processing hidden role="status" aria-live="polite" aria-label="Processing payment">
                <div class="lg-processing__card">
                    <div class="lg-processing__spinner" aria-hidden="true"></div>
                    <h3 class="lg-processing__title">Still processing your payment&hellip;</h3>
                    <p class="lg-processing__body">Your payment went through. We&rsquo;re finalizing your subscription &mdash; please don&rsquo;t close this tab. You&rsquo;ll be redirected in a moment.</p>
                </div>
            </div>

            <!-- Existing-account modal: fired when /gift-auth says incorrect password
                 for an email that already has a WP user. The same markup also lives
                 in the gift shortcode; duplicated here because pages that only host
                 [lg_join] would otherwise have no DOM for the modal to attach to. -->
            <div class="lg-existacct" data-lg-existacct-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-existacct-title-join">
                <div class="lg-existacct__backdrop" data-lg-existacct-cancel></div>
                <div class="lg-existacct__card">
                    <button type="button" class="lg-existacct__close" data-lg-existacct-cancel aria-label="Close">&times;</button>
                    <h3 id="lg-existacct-title-join" class="lg-existacct__title">This email already has an account</h3>
                    <p class="lg-existacct__body" data-lg-existacct-body></p>
                    <div class="lg-existacct__actions">
                        <a class="lg-existacct__btn lg-existacct__btn--primary" data-lg-existacct-login href="#">Log in &amp; manage subscription</a>
                        <a class="lg-existacct__btn lg-existacct__btn--ghost"   data-lg-existacct-forgot hidden href="#">Forgot your password?</a>
                    </div>
                    <p class="lg-existacct__alt"><a href="#" data-lg-existacct-cancel>Use a different email instead</a></p>
                </div>
            </div>

            <style>
                .lg-existacct { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
                .lg-existacct[hidden] { display: none !important; }
                .lg-existacct__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
                .lg-existacct__card { position: relative; background: #fff; border-radius: 12px; padding: 1.7em 1.6em; max-width: 460px; width: 100%; box-shadow: 0 16px 50px rgba(0,0,0,0.4); color: #1f1d1a; }
                .lg-existacct__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
                .lg-existacct__close:hover { color: #000; background: #f5f5f5; }
                .lg-existacct__title { margin: 0 0 .55em; font-size: 1.2em; font-weight: 700; line-height: 1.3; padding-right: 2em; }
                .lg-existacct__body { margin: 0 0 1.2em; font-size: .95em; line-height: 1.5; color: #333; }
                .lg-existacct__actions { display: flex; gap: .65em; flex-wrap: wrap; }
                .lg-existacct__btn { padding: .65em 1.2em; border-radius: 6px; font-weight: 600; font-size: .92em; text-decoration: none; display: inline-block; cursor: pointer; }
                .lg-existacct__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a !important; }
                .lg-existacct__btn--primary:hover { opacity: .88; }
                .lg-existacct__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a !important; }
                .lg-existacct__btn--ghost:hover { background: rgba(0,0,0,0.04); }
                .lg-existacct__alt { margin: 1em 0 0; font-size: .85em; color: #666; }
                body.lg-modal-open { overflow: hidden !important; }
            </style>

            <style>
                .lg-giftwarn { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
                .lg-giftwarn[hidden] { display: none !important; }
                .lg-giftwarn__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.6); }
                .lg-giftwarn__card { position: relative; background: #fff; border-radius: 12px; padding: 1.6em 1.5em; max-width: 460px; width: 100%; box-shadow: 0 16px 50px rgba(0,0,0,0.4); color: #1f1d1a; }
                .lg-giftwarn__close { position: absolute; top: .55em; right: .55em; width: 2em; height: 2em; padding: 0; background: #fff; border: 1px solid rgba(0,0,0,0.15); border-radius: 50%; font-size: 1.35em; line-height: 1; cursor: pointer; color: #444; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.12); }
                .lg-giftwarn__close:hover { color: #000; background: #f5f5f5; }
                .lg-giftwarn__title { margin: 0 0 .55em; font-size: 1.2em; font-weight: 700; line-height: 1.3; padding-right: 2em; }
                .lg-giftwarn__body { margin: 0 0 1em; font-size: .95em; line-height: 1.5; color: #333; }
                .lg-giftwarn__check { display: flex; align-items: flex-start; gap: .6em; padding: .8em .9em; margin-bottom: 1.1em; background: rgba(255,200,80,0.14); border: 1px solid rgba(255,180,40,0.45); border-radius: 6px; cursor: pointer; font-size: .92em; line-height: 1.4; font-weight: 500; }
                .lg-giftwarn__check input { margin-top: .15em; flex-shrink: 0; }
                .lg-giftwarn__actions { display: flex; gap: .65em; justify-content: flex-end; flex-wrap: wrap; }
                .lg-giftwarn__btn { padding: .6em 1.15em; border-radius: 6px; font-weight: 600; font-size: .92em; cursor: pointer; border: none; transition: opacity .15s, background .15s; }
                .lg-giftwarn__btn--ghost { background: transparent; border: 1.5px solid rgba(0,0,0,0.2); color: #1f1d1a; }
                .lg-giftwarn__btn--ghost:hover { background: rgba(0,0,0,0.04); }
                .lg-giftwarn__btn--primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; }
                .lg-giftwarn__btn--primary:hover { opacity: .88; }
                .lg-giftwarn__btn:disabled { opacity: .45; cursor: not-allowed; }
                /* Pay modal styles (.lg-pay-modal* + .lg-stripe-modal* + .lg-modal-processing*)
                   are defined in assets/lg-shortcodes.css — single source of truth shared
                   with [lg_gift]. */

                /* Fields use the form grid; inputs fill their cell so columns align. */
                .lg-join__field label { display: block; }
                .lg-join__field input[type=email],
                .lg-join__field input[type=text],
                .lg-join__field input[type=password] { width: 100%; box-sizing: border-box; }

                /* Subtle inline password reveal: icon sits inside the input on the right. */
                .lg-pwd-wrap { position: relative; display: block; }
                .lg-pwd-wrap input { width: 100%; padding-right: 2.6em !important; box-sizing: border-box; }
                .lg-pwd-eye {
                    position: absolute; right: .35em; top: 50%; transform: translateY(-50%);
                    width: 1.9em; height: 1.9em; padding: 0;
                    background: transparent; border: none; cursor: pointer;
                    color: rgba(0,0,0,0.55);
                    display: flex; align-items: center; justify-content: center;
                    transition: color .15s;
                }
                .lg-pwd-eye:hover { color: rgba(0,0,0,0.85); }
                .lg-pwd-eye:focus-visible { outline: 2px solid var(--lg-amber, #ECB351); outline-offset: 2px; border-radius: 4px; }
                .lg-pwd-eye__icon { width: 1.15em; height: 1.15em; display: block; }
                .lg-pwd-mismatch { color: #b91c1c !important; font-size: .85em; margin-top: .3em; }

                /* Sign-up modal — uses the unified .lg-pay-modal__card shell.
                   Override the default 720px so the signup form stays
                   comfortably narrow, restore inner padding (the shared
                   __body has padding:0 because the checkout iframe paints
                   its own padding), and make the pay-methods bar sit flush
                   inside the card. */
                .lg-pay-modal__card--signup { max-width: 620px !important; }
                .lg-pay-modal__body--signup { padding: 1.8em 1.7em 1.4em !important; }
                .lg-pay-modal__pay-methods {
                    margin: 1.2em -1.7em -1.4em;
                    padding: .9em 1.7em !important;
                    border-top: 1px solid rgba(0,0,0,0.08);
                    background: rgba(0,0,0,0.025);
                    justify-content: center !important;
                    display: flex !important;
                    align-items: center !important;
                    gap: .7em !important;
                    flex-wrap: wrap !important;
                }

                /* Post-payment processing lock — shown when Stripe fires onComplete.
                   Sits above everything (max z-index) so the user cannot click into
                   nav, close the tab silently (beforeunload warns), or otherwise
                   navigate away while the post-pay redirect to /welcome/ is in flight. */
                .lg-processing { position: fixed !important; inset: 0 !important; z-index: 2147483647 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; background: rgba(0,0,0,0.85); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
                .lg-processing[hidden] { display: none !important; }
                .lg-processing__card { background: #fff; border-radius: 12px; padding: 2em 1.8em; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,0.5); color: #1f1d1a; }
                .lg-processing__spinner { width: 44px; height: 44px; margin: 0 auto 1em; border: 4px solid rgba(0,0,0,0.1); border-top-color: var(--lg-amber, #ECB351); border-radius: 50%; animation: lg-processing-spin 0.9s linear infinite; }
                .lg-processing__title { margin: 0 0 .6em; font-size: 1.15em; font-weight: 700; }
                .lg-processing__body  { margin: 0; font-size: .92em; line-height: 1.5; color: #444; }
                @keyframes lg-processing-spin { to { transform: rotate(360deg); } }
                /* Pay-modal lock styles live in assets/lg-shortcodes.css under
                   .lg-pay-modal[data-lg-locked="1"]. */
            </style>
            <div class="lg-giftwarn" data-lg-giftwarn-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-giftwarn-title">
                <div class="lg-giftwarn__backdrop" data-lg-giftwarn-cancel></div>
                <div class="lg-giftwarn__card">
                    <button type="button" class="lg-giftwarn__close" data-lg-giftwarn-cancel aria-label="Close">&times;</button>
                    <h3 id="lg-giftwarn-title" class="lg-giftwarn__title">You're already covered by a gift</h3>
                    <p class="lg-giftwarn__body" data-lg-giftwarn-body></p>
                    <div class="lg-giftwarn__actions">
                        <button type="button" class="lg-giftwarn__btn lg-giftwarn__btn--ghost" data-lg-giftwarn-cancel>Maybe later</button>
                        <button type="button" class="lg-giftwarn__btn lg-giftwarn__btn--primary" data-lg-giftwarn-confirm>Set up subscription</button>
                    </div>
                </div>
            </div>

            <div class="lg-join__error" data-lg-join-error aria-live="polite"></div>
        </div>

        <!-- Basil release of Stripe.js — required for stripe.initCheckout()
             (custom UI mode for subscription path). The Stripe SDK
             API-version pin on the Slim side must match. Other shortcodes
             stay on /v3/ since they only use embedded checkout. -->
        <script src="https://js.stripe.com/basil/stripe.js"></script>
        <script>
        (function(){
            function lgGetRef() { try { return localStorage.getItem('lg_ref') || ''; } catch(_) { return ''; } }

            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const PROMO     = <?php echo wp_json_encode( $promoFromUrl ); ?>;

            // Capture ?ref= affiliate slug on landing, persist across sessions,
            // and fire a server-side click event so misses are visible in reports.
            (function() {
                try {
                    var r = new URLSearchParams(window.location.search).get('ref');
                    if (r) {
                        r = r.replace(/[^a-z0-9_-]/gi, '').slice(0, 80);
                        localStorage.setItem('lg_ref', r);
                        fetch(ENDPOINTS.affiliateClick, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ ref: r }),
                            keepalive: true,
                        }).catch(function(){});
                    }
                } catch(_) {}
            })();
            const COUNTRY_OVERRIDE = <?php echo wp_json_encode( $countryFromUrl ); ?>;
            const CONFIG    = <?php echo $configJs; ?>;
            // Resolved at runtime: URL override > Cloudflare trace > none.
            let DETECTED_COUNTRY = COUNTRY_OVERRIDE || '';

            // Detect visitor country in this priority:
            //   1. URL override (?country=XX) — already set above
            //   2. Cloudflare's /cdn-cgi/trace (only works on CF-proxied zones;
            //      currently dev is not proxied, but prod likely is or will be)
            //   3. ipapi.co/json/ — free third-party geolocation, no API key,
            //      30k/month free tier, CORS-enabled. Used as fallback so the
            //      same code works regardless of whether the zone is CF-proxied.
            async function detectCountry(){
                if (DETECTED_COUNTRY) return DETECTED_COUNTRY;
                // Path 1: Cloudflare edge
                try {
                    const res = await fetch('/cdn-cgi/trace', { cache: 'no-store' });
                    if (res.ok) {
                        const text = await res.text();
                        const m = text.match(/^loc=([A-Z]{2})$/m);
                        if (m && m[1] !== 'XX' && m[1] !== 'T1') {
                            DETECTED_COUNTRY = m[1];
                            return DETECTED_COUNTRY;
                        }
                    }
                } catch (_) { /* fall through to ipapi */ }
                // Path 2: third-party geolocation
                try {
                    const res = await fetch('https://ipapi.co/json/', { cache: 'no-store' });
                    if (res.ok) {
                        const data = await res.json();
                        const cc = (data && data.country_code) ? String(data.country_code).toUpperCase() : '';
                        if (cc.length === 2 && cc !== 'XX') {
                            DETECTED_COUNTRY = cc;
                        }
                    }
                } catch (_) { /* offline; give up silently */ }
                return DETECTED_COUNTRY;
            }

            // Approximate USD → local FX for cosmetic display only. Stripe
            // always charges USD; the customer's bank does the actual FX.
            // Refresh quarterly (or whenever you remember). Display drift is
            // disclaimed to the customer.
            const FX = {
                IN:{c:'INR',s:'₹',  r:83},   BR:{c:'BRL',s:'R$', r:5},
                MX:{c:'MXN',s:'MX$',r:17},   NG:{c:'NGN',s:'₦',  r:1500},
                PH:{c:'PHP',s:'₱',  r:56},   ID:{c:'IDR',s:'Rp', r:16000},
                PK:{c:'PKR',s:'₨',  r:280},  BD:{c:'BDT',s:'৳',  r:110},
                VN:{c:'VND',s:'₫',  r:24000},EG:{c:'EGP',s:'E£', r:50},
                KE:{c:'KES',s:'KSh',r:130},  GH:{c:'GHS',s:'GH₵',r:12},
                ET:{c:'ETB',s:'Br', r:115},  TZ:{c:'TZS',s:'TSh',r:2700},
                UG:{c:'UGX',s:'USh',r:3700}, MM:{c:'MMK',s:'K',  r:2100},
                KH:{c:'KHR',s:'៛',  r:4100}, TR:{c:'TRY',s:'₺',  r:32},
                AR:{c:'ARS',s:'AR$',r:1000}, CO:{c:'COP',s:'COL$',r:4000},
                PE:{c:'PEN',s:'S/', r:3.7},  ZA:{c:'ZAR',s:'R',  r:18},
                UA:{c:'UAH',s:'₴',  r:38},   PL:{c:'PLN',s:'zł', r:4},
                RO:{c:'RON',s:'lei',r:4.6},  TH:{c:'THB',s:'฿',  r:36},
                MY:{c:'MYR',s:'RM', r:4.7},  CL:{c:'CLP',s:'CLP$',r:950},
                MA:{c:'MAD',s:'MAD',r:10},   JO:{c:'JOD',s:'JD', r:0.71},
            };

            function roundLocal(n){
                if (n < 10)     return Math.round(n*10)/10;
                if (n < 100)    return Math.round(n);
                if (n < 1000)   return Math.round(n/5)*5;
                if (n < 10000)  return Math.round(n/50)*50;
                if (n < 100000) return Math.round(n/500)*500;
                return Math.round(n/1000)*1000;
            }

            function localHint(usdCents){
                if (!DETECTED_COUNTRY || !FX[DETECTED_COUNTRY]) return '';
                const fx = FX[DETECTED_COUNTRY];
                const local = roundLocal((usdCents / 100) * fx.r);
                return ' (≈ ' + fx.s + local.toLocaleString() + ')';
            }

            const tiersEl      = document.querySelector('[data-lg-join-tiers]');
            const signupModal  = document.querySelector('[data-lg-signup-modal]');
            const formEl       = document.querySelector('[data-lg-join-form]');
            const formHeadEl   = document.querySelector('[data-lg-form-heading]');

            // Portal to body so BuddyBoss containing-blocks can't trap position:fixed
            if (signupModal && signupModal.parentNode !== document.body) {
                document.body.appendChild(signupModal);
            }

            function openSignupModal() {
                if (signupModal) { signupModal.hidden = false; document.body.classList.add('lg-modal-open'); }
            }
            function closeSignupModal() {
                if (signupModal) { signupModal.hidden = true; document.body.classList.remove('lg-modal-open'); }
            }
            document.querySelectorAll('[data-lg-signup-close]').forEach(el => {
                el.addEventListener('click', () => { teardownCheckoutMount(); closeSignupModal(); pendingPriceId = null; document.querySelectorAll('.lg-join__tier.is-selected, .lg-join__buy.is-selected, .lg-join__trial-btn.is-selected').forEach(e => e.classList.remove('is-selected')); });
            });
            const continueBt = document.querySelector('[data-lg-continue]');
            const backBt     = document.querySelector('[data-lg-back]');
            const checkoutEl = document.querySelector('[data-lg-join-checkout]');
            const customEl   = document.querySelector('[data-lg-join-checkout-custom]');
            const peEl       = document.querySelector('[data-lg-join-payment-element]');
            const payBt      = document.querySelector('[data-lg-join-checkout-pay]');
            const payLabelEl = document.querySelector('[data-lg-pay-label]');
            const customErrorEl = document.querySelector('[data-lg-join-checkout-error]');
            const modalProcessingEl = document.querySelector('[data-lg-join-modal-processing]');
            const payAmountEl   = document.querySelector('[data-lg-pay-amount]');
            const paySublabelEl = document.querySelector('[data-lg-pay-sublabel]');
            const errorEl    = document.querySelector('[data-lg-join-error]');
            // Existing-account modal — fired when /gift-auth says incorrect
            // password for an email that already has a WP user. Push the
            // visitor toward /manage-subscription/ instead of letting them
            // start a duplicate sub.
            const existAcctModal   = document.querySelector('[data-lg-existacct-modal]');
            const existAcctBody    = document.querySelector('[data-lg-existacct-body]');
            const existAcctLogin   = document.querySelector('[data-lg-existacct-login]');
            const existAcctForgot  = document.querySelector('[data-lg-existacct-forgot]');
            if (existAcctModal && existAcctModal.parentNode !== document.body) document.body.appendChild(existAcctModal);

            function showExistingAccountModal(opts) {
                const target   = '<?php echo esc_js( esc_url_raw( home_url( '/manage-subscription/' ) ) ); ?>';
                const loginUrl = '<?php echo esc_js( esc_url_raw( wp_login_url() ) ); ?>'
                               + '?redirect_to=' + encodeURIComponent(target);
                if (existAcctBody) {
                    existAcctBody.innerHTML =
                        '<strong>' + opts.email + '</strong> already has an account. Log in and we' + String.fromCharCode(39) + 'll take you straight to your subscription dashboard where you can change plan, update your card, or cancel.<br><br>' +
                        '<small style="color:#888;">' + (opts.error || '') + '</small>';
                }
                if (existAcctLogin)  existAcctLogin.href  = loginUrl;
                if (existAcctForgot) {
                    existAcctForgot.href = '<?php echo esc_js( esc_url_raw( wp_lostpassword_url() ) ); ?>';
                    existAcctForgot.hidden = !opts.forgot;
                }
                if (existAcctModal) existAcctModal.hidden = false;
                document.body.classList.add('lg-modal-open');
            }
            function hideExistingAccountModal() {
                if (existAcctModal) existAcctModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
            }
            document.querySelectorAll('[data-lg-existacct-cancel]').forEach(el => {
                el.addEventListener('click', (e) => { e.preventDefault(); hideExistingAccountModal(); });
            });

            // Password eyeball toggles (works for both password + confirm)
            document.querySelectorAll('[data-lg-pwd-eye-for]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const target = document.querySelector('input[name="' + btn.dataset.lgPwdEyeFor + '"]');
                    if (!target) return;
                    target.type = (target.type === 'password') ? 'text' : 'password';
                    btn.setAttribute('aria-label', target.type === 'password' ? 'Show password' : 'Hide password');
                });
            });

            // Live password-match check
            const pwdEl     = document.querySelector('input[name="password"]');
            const pwd2El    = document.querySelector('input[name="password_confirm"]');
            const mismatchEl= document.querySelector('[data-lg-pwd-mismatch]');
            function checkPwdMatch() {
                if (!pwdEl || !pwd2El || !mismatchEl) return;
                const a = pwdEl.value, b = pwd2El.value;
                mismatchEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (pwdEl)  pwdEl.addEventListener('input',  checkPwdMatch);
            if (pwd2El) pwd2El.addEventListener('input', checkPwdMatch);

            const emailInput = document.querySelector('input[name="email"]');
            const emailConfirmInput = document.querySelector('input[name="email_confirm"]');
            const emailMismatchEl   = document.querySelector('[data-lg-email-mismatch]');
            const nameInput  = document.querySelector('input[name="name"]');

            // Live email-match check (mirror of pwd check above)
            function checkEmailMatch() {
                if (!emailInput || !emailConfirmInput || !emailMismatchEl) return;
                const a = (emailInput.value || '').trim().toLowerCase();
                const b = (emailConfirmInput.value || '').trim().toLowerCase();
                emailMismatchEl.hidden = !(a.length > 0 && b.length > 0 && a !== b);
            }
            if (emailInput)        emailInput.addEventListener('input',        checkEmailMatch);
            if (emailConfirmInput) emailConfirmInput.addEventListener('input', checkEmailMatch);

            let stripe         = null;
            let mountedSession = null;     // embedded mode handle (one-time + regional)
            let customCheckout = null;     // custom mode handle (subscription)
            let paymentElement = null;
            let pendingPriceId = null;
            let pendingLabel   = '';

            function showError(msg){ errorEl.textContent = msg || ''; }

            function dollars(cents){
                return '$' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2);
            }

            function priceLabel(price){
                const hint = localHint(price.unit_amount_cents);
                if (price.type === 'recurring' && price.interval === 'month') {
                    return 'Subscribe — ' + dollars(price.unit_amount_cents) + '/month' + hint;
                }
                if (price.type === 'recurring' && price.interval === 'year') {
                    return 'Subscribe — ' + dollars(price.unit_amount_cents) + '/year' + hint;
                }
                if (price.type === 'one_time') {
                    return 'Pay once — ' + dollars(price.unit_amount_cents) + ' / year' + hint;
                }
                return dollars(price.unit_amount_cents) + hint;
            }

            // Order: monthly sub, yearly sub, one-time
            function sortPrices(prices){
                const order = (p) => {
                    if (p.type === 'recurring' && p.interval === 'month') return 0;
                    if (p.type === 'recurring' && p.interval === 'year')  return 1;
                    if (p.type === 'one_time')                            return 2;
                    return 99;
                };
                return [...prices].sort((a, b) => order(a) - order(b));
            }

            async function loadProducts(){
                showError('');
                try {
                    await detectCountry();
                    const url  = ENDPOINTS.products + (DETECTED_COUNTRY ? '?country=' + encodeURIComponent(DETECTED_COUNTRY) : '');
                    const res  = await fetch(url);
                    const json = await res.json();
                    if (!json.products || json.products.length === 0) {
                        tiersEl.innerHTML = '<p>No memberships available right now.</p>';
                        return;
                    }
                    // If any returned price has a region_tag, show a small note
                    // ("Regional pricing for IN") so customers understand why
                    // the price they see may differ from a friend's.
                    const hasRegional = json.products.some(p => (p.prices || []).some(pr => pr.region_tag));
                    if (hasRegional && json.detected_country) {
                        const noteEl = document.querySelector('[data-lg-region-note]');
                        if (noteEl) {
                            const fxNote = (DETECTED_COUNTRY && FX[DETECTED_COUNTRY])
                                ? ' Local-currency figures are approximate; Stripe bills in USD and your bank applies the exchange rate at the time of payment.'
                                : '';
                            noteEl.innerHTML = '<strong>Regional pricing</strong> applied for ' + json.detected_country + '.' + fxNote;
                            noteEl.hidden = false;
                        }
                    }
                    renderTiers(json.products);
                } catch (err) {
                    showError('Failed to load memberships: ' + err.message);
                }
            }

            function buildTierCard(prod, prices, isPopular, isMock) {
                const card = document.createElement('div');
                card.className = 'lg-join__tier';
                if (isPopular) {
                    card.classList.add('is-popular');
                    const badge = document.createElement('span');
                    badge.className = 'lg-join__tier-badge';
                    badge.textContent = 'Most popular';
                    card.appendChild(badge);
                }

                const title = document.createElement('h3');
                title.className = 'lg-join__tier-name';
                title.textContent = prod.name;
                card.appendChild(title);

                const tagline = document.createElement('p');
                tagline.className = 'lg-join__tier-tagline';
                tagline.textContent = (CONFIG.taglines && CONFIG.taglines[prod.ref]) || '';
                card.appendChild(tagline);

                // Feature list
                const feats = (CONFIG.features && CONFIG.features[prod.ref]) || prod.features || [];
                if (feats.length > 0) {
                    const ul = document.createElement('ul');
                    ul.className = 'lg-join__features';
                    feats.forEach(function(f){
                        const li = document.createElement('li');
                        li.className = 'lg-join__feature';
                        li.textContent = f;
                        ul.appendChild(li);
                    });
                    card.appendChild(ul);
                }

                // Price buttons
                const list = document.createElement('div');
                list.className = 'lg-join__tier-prices';
                prices.forEach(function(price){
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'lg-join__buy';
                    if (price.type === 'recurring' && price.interval === 'year') btn.classList.add('is-primary');
                    btn.textContent = priceLabel(price);
                    if (isMock) {
                        btn.disabled = true;
                        btn.title = 'Preview only — not a live price';
                    } else {
                        btn.addEventListener('click', () => selectPrice(price, prod, btn));
                    }
                    list.appendChild(btn);
                });
                card.appendChild(list);


                return card;
            }

            function renderTiers(products){
                tiersEl.innerHTML = '';

                // Show trial banner if any tier has a trial
                const anyTrial = products.some(p => p.prices.some(pr => (pr.trial_days || 0) > 0));
                const trialBanner = document.querySelector('[data-lg-trial-banner]');
                if (trialBanner) trialBanner.hidden = !anyTrial;

                if (CONFIG.previewSingle) {
                    renderSingleTierPreview();
                    return;
                }

                products.forEach(function(prod){
                    const sorted    = sortPrices(prod.prices);
                    const isPopular = (prod.ref && CONFIG.popular && prod.ref === CONFIG.popular);
                    const card      = buildTierCard(prod, sorted, isPopular, false);
                    tiersEl.appendChild(card);

                    // Pulse the popular tier's yearly button on load to draw the eye.
                    if (isPopular) {
                        const yearlyBtn = card.querySelector('.lg-join__buy.is-primary');
                        if (yearlyBtn) {
                            setTimeout(() => {
                                yearlyBtn.classList.add('is-pulsing');
                                // Remove class after animation ends so it can retrigger if needed
                                yearlyBtn.addEventListener('animationend', () => yearlyBtn.classList.remove('is-pulsing'), { once: true });
                            }, 800);
                            yearlyBtn.addEventListener('click', () => yearlyBtn.classList.remove('is-pulsing'), { once: true });
                        }
                    }
                });
            }

            function renderSingleTierPreview(){
                const mockProd = {
                    name: 'Looth Group',
                    ref:  'single',
                    features: ['Member forums & community', 'Interviews & AMAs', 'Demo-based content', 'Live session archives'],
                };
                const mockPrices = [
                    { stripe_price_id: 'mock_month', type: 'recurring', interval: 'month', unit_amount_cents: 800,  trial_days: 7 },
                    { stripe_price_id: 'mock_year',  type: 'recurring', interval: 'year',  unit_amount_cents: 8000, trial_days: 7 },
                    { stripe_price_id: 'mock_once',  type: 'one_time',  interval: null,    unit_amount_cents: 9600 },
                ];
                const card = buildTierCard(mockProd, mockPrices, false, true);
                card.style.maxWidth = '420px';
                card.style.margin   = '0 auto';
                tiersEl.appendChild(card);
            }

            // Step 1: customer picks a price → highlight selected card, reveal form panel.
            // `opts.silent` skips scroll-into-view + auto-focus — used when this fires
            // on page load via the default-selection auto-pick.
            function selectPrice(price, prod, btn, opts){
                opts = opts || {};
                showError('');
                pendingPriceId = price.stripe_price_id;
                pendingLabel   = opts.label || priceLabel(price);

                // Highlight selected card
                document.querySelectorAll('.lg-join__tier').forEach(c => c.classList.remove('is-selected'));
                const card = btn.closest('.lg-join__tier');
                if (card) card.classList.add('is-selected');

                // Highlight selected button. Clears every other price and trial
                // button across all tiers so only one reads as the active pick.
                document.querySelectorAll('.lg-join__buy.is-selected, .lg-join__trial-btn.is-selected').forEach(b => b.classList.remove('is-selected'));
                btn.classList.add('is-selected');

                const displayLabel = pendingLabel.replace(/^Subscribe\s*—\s*|^Pay once\s*—\s*/, '');
                formHeadEl.textContent = 'Continue to ' + prod.name + ' — ' + displayLabel;
                // If checkout was already mounted (user clicked again), tear it down.
                teardownCheckoutMount();
                if (!opts.silent) {
                    openSignupModal();
                    setTimeout(function(){
                        if (!emailInput.value.trim()) emailInput.focus();
                        else continueBt.focus();
                    }, 80);
                }
            }

            // Step 2: customer confirms → mount Stripe Checkout.
            async function startCheckout(){
                showError('');
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }
                if (emailConfirmInput) {
                    const emailConfirm = (emailConfirmInput.value || '').trim();
                    if (emailConfirm.toLowerCase() !== email.toLowerCase()) {
                        showError('The two email fields must match.');
                        emailConfirmInput.focus();
                        return;
                    }
                }
                if (!pendingPriceId) { showError('Pick a plan first.'); return; }

                const name = (nameInput.value || '').trim();
                if (!name) { showError('Profile name is required.'); nameInput.focus(); return; }

                // Auth-before-Stripe: if the visitor isnt logged in, run gift-auth
                // FIRST so the cookie is set before Stripe redirects them. Same
                // pattern as the gift purchase form.
                if (!CONFIG.loggedIn && !window.__lgJoinAuthed) {
                    const passwordEl = document.querySelector('input[name="password"]');
                    const password   = passwordEl ? passwordEl.value : '';
                    if (!password || password.length < 8) {
                        showError('Please enter a password (8+ characters).');
                        if (passwordEl) passwordEl.focus();
                        return;
                    }
                    const passwordConfirmEl = document.querySelector('input[name="password_confirm"]');
                    if (passwordConfirmEl && passwordConfirmEl.value !== password) {
                        showError('Passwords don' + String.fromCharCode(39) + 't match.');
                        passwordConfirmEl.focus();
                        return;
                    }
                    continueBt.disabled = true;
                    const origAuth = continueBt.textContent;
                    continueBt.textContent = 'Signing in…';
                    try {
                        const authRes = await fetch(CONFIG.authUrl, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({
                                email, password,
                                display_name:      name,
                                confirmed_consent: true,
                            }),
                        });
                        const authData = await authRes.json();
                        if (!authData.ok) {
                            showExistingAccountModal({
                                email,
                                error:  authData.error || 'Sign-in failed.',
                                forgot: !!authData.forgot,
                            });
                            continueBt.disabled    = false;
                            continueBt.textContent = origAuth;
                            return;
                        }
                        window.__lgJoinAuthed = true;
                    } catch (e) {
                        showError('Network error during sign-in. Please try again.');
                        continueBt.disabled    = false;
                        continueBt.textContent = origAuth;
                        return;
                    }
                    continueBt.textContent = origAuth;
                }

                teardownCheckoutMount();
                continueBt.disabled = true;
                const orig = continueBt.textContent;
                continueBt.textContent = 'Loading…';

                try {
                    const body = {
                        price_id: pendingPriceId,
                        email:    email,
                        name:     (nameInput.value || '').trim(),
                    };
                    if (window.__lgAckActiveGift) {
                        body.acknowledged_active_gift = true;
                    }
                    const promoInput = document.querySelector('input[name="promo_code"]');
                    const typedPromo = promoInput ? (promoInput.value || '').trim() : '';
                    const finalPromo = typedPromo !== '' ? typedPromo : (PROMO || '');
                    if (finalPromo) body.promo_code = finalPromo;
                    if (DETECTED_COUNTRY) body.country = DETECTED_COUNTRY;
                    const lgRef = lgGetRef();
                    if (lgRef) body.ref = lgRef;

                    const sessRes  = await fetch(ENDPOINTS.checkout, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(body),
                    });
                    const sessData = await sessRes.json();
                    if (sessData.needs_gift_confirmation) {
                        showGiftWarnModal(sessData.active_gift || {});
                        return;
                    }
                    if (!sessData.clientSecret) {
                        showError(sessData.error || 'Could not start checkout.');
                        return;
                    }

                    if (!stripe) {
                        const cfg = await (await fetch(ENDPOINTS.config)).json();
                        if (!cfg.publishableKey) { showError('Stripe not configured.'); return; }
                        stripe = Stripe(cfg.publishableKey);
                    }

                    const uiMode = sessData.ui_mode || 'embedded';
                    if (uiMode === 'custom') {
                        await mountCustomCheckout(sessData.clientSecret);
                    } else {
                        await mountEmbeddedCheckout(sessData.clientSecret);
                    }
                } catch (err) {
                    showError('Network error: ' + err.message);
                } finally {
                    continueBt.disabled    = false;
                    continueBt.textContent = orig;
                }
            }

            const giftWarnModal   = document.querySelector('[data-lg-giftwarn-modal]');
            const giftWarnBody    = document.querySelector('[data-lg-giftwarn-body]');
            const giftWarnCheck   = document.querySelector('[data-lg-giftwarn-confirm-check]');
            const giftWarnConfirm = document.querySelector('[data-lg-giftwarn-confirm]');
            if (giftWarnModal && giftWarnModal.parentNode !== document.body) document.body.appendChild(giftWarnModal);

            function tierLabel(tier) {
                return ({ looth1:'Looth Public', looth2:'Looth LITE', looth3:'Looth PRO', looth4:'Looth Premium Plus' })[tier] || tier;
            }
            function showGiftWarnModal(active) {
                const tier  = tierLabel(active.tier || '');
                const days  = parseInt(active.days_remaining, 10) || 0;
                const exp   = active.expires_at || '';
                if (giftWarnBody) {
                    const expNice = exp ? new Date(exp + 'T00:00:00').toLocaleDateString(undefined, { month:'short', day:'numeric', year:'numeric' }) : '';
                    giftWarnBody.innerHTML =
                        'You have <strong>' + days + ' day' + (days === 1 ? '' : 's') + ' of ' + tier + '</strong> left from a redeemed gift' +
                        (expNice ? ' (through <strong>' + expNice + '</strong>)' : '') + '. ' +
                        '<br><br>' +
                        'No problem &mdash; if you set up a subscription now, ' +
                        '<strong>we won&rsquo;t charge you until your gift ends' + (expNice ? ' on ' + expNice : '') + '</strong>. ' +
                        'You stay covered without interruption and you only pay once your gift time is up.';
                }
                if (giftWarnConfirm) giftWarnConfirm.disabled = false;
                if (giftWarnModal)   giftWarnModal.hidden = false;
                document.body.classList.add('lg-modal-open');
            }
            function hideGiftWarnModal() {
                if (giftWarnModal) giftWarnModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
            }
            // Confirm-checkbox is gone — confirm is enabled the moment the
            // modal opens (since first charge is now deferred to gift expiry,
            // there's nothing to second-guess).
            document.querySelectorAll('[data-lg-giftwarn-cancel]').forEach(el => {
                el.addEventListener('click', hideGiftWarnModal);
            });
            if (giftWarnConfirm) {
                giftWarnConfirm.addEventListener('click', () => {
                    window.__lgAckActiveGift = true;
                    hideGiftWarnModal();
                    startCheckout();
                });
            }

            const joinCheckoutModal = document.querySelector('[data-lg-join-checkout-modal]');
            if (joinCheckoutModal && joinCheckoutModal.parentNode !== document.body) {
                document.body.appendChild(joinCheckoutModal);
            }

            function showCheckoutError(msg) {
                if (!customErrorEl) return;
                if (msg) {
                    customErrorEl.textContent = msg;
                    customErrorEl.hidden = false;
                } else {
                    customErrorEl.textContent = '';
                    customErrorEl.hidden = true;
                }
            }

            function teardownCheckoutMount() {
                if (mountedSession) {
                    try { mountedSession.destroy(); } catch (_) {}
                    mountedSession = null;
                }
                if (paymentElement) {
                    try { paymentElement.unmount(); } catch (_) {}
                    paymentElement = null;
                }
                customCheckout = null;
                if (checkoutEl) checkoutEl.innerHTML = '';
                if (peEl) peEl.innerHTML = '';
                if (customEl) customEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                showCheckoutError('');
                if (payBt) payBt.disabled = true;
            }

            async function mountEmbeddedCheckout(clientSecret) {
                if (customEl) customEl.hidden = true;
                if (checkoutEl) checkoutEl.hidden = false;
                mountedSession = await stripe.initEmbeddedCheckout({
                    clientSecret: clientSecret,
                    onComplete: showJoinProcessingOverlay,
                });
                // Open the modal BEFORE mounting so Stripe's iframe has
                // real dimensions to lay out against.
                if (joinCheckoutModal) joinCheckoutModal.hidden = false;
                document.body.classList.add('lg-modal-open');
                mountedSession.mount(checkoutEl);
            }

            async function mountCustomCheckout(clientSecret) {
                // Open the modal with the processing overlay covering everything.
                // Keep the custom block hidden until paymentElement fires 'ready'
                // — otherwise the Pay button shows a beat before the card form,
                // which reads as broken/half-loaded.
                if (checkoutEl) checkoutEl.hidden = true;
                if (customEl) customEl.hidden = true;
                if (joinCheckoutModal) joinCheckoutModal.hidden = false;
                if (modalProcessingEl) modalProcessingEl.hidden = false;
                document.body.classList.add('lg-modal-open');

                customCheckout = await stripe.initCheckout({
                    fetchClientSecret: async () => clientSecret,
                });

                // Reflect Stripe's "ready to confirm" state into the Pay button.
                customCheckout.on('change', (session) => {
                    if (!payBt) return;
                    payBt.disabled = !session.canConfirm;
                    const cents = session && session.total && session.total.total
                        ? session.total.total.amount
                        : null;
                    const formatted = (typeof cents === 'number' && !isNaN(cents))
                        ? '$' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2)
                        : '';
                    if (payLabelEl) payLabelEl.textContent = formatted ? 'Pay ' + formatted : 'Pay';
                    if (payAmountEl) { payAmountEl.textContent = formatted; payAmountEl.hidden = !formatted; }
                    if (paySublabelEl) { paySublabelEl.textContent = pendingLabel || ""; paySublabelEl.hidden = !pendingLabel; }
                });

                paymentElement = customCheckout.createPaymentElement();
                paymentElement.on('ready', () => {
                    // Element rendered → reveal the form + button together,
                    // then drop the processing overlay.
                    if (customEl) customEl.hidden = false;
                    if (modalProcessingEl) modalProcessingEl.hidden = true;
                });
                paymentElement.mount(peEl);
            }

            async function onPayClick() {
                if (!customCheckout || !payBt || payBt.disabled) return;
                showCheckoutError('');

                // Lock the modal at click time — the X / backdrop become inert
                // so a panic-click can't tear down the form mid-charge.
                if (joinCheckoutModal) joinCheckoutModal.dataset.lgLocked = '1';

                const origLabel = payLabelEl ? payLabelEl.textContent : '';
                payBt.disabled = true;
                if (modalProcessingEl) modalProcessingEl.hidden = false;

                try {
                    const result = await customCheckout.confirm();
                    if (result && result.error) {
                        // Re-open the escape hatches so the customer can fix
                        // the error and retry, or back out cleanly.
                        if (joinCheckoutModal) delete joinCheckoutModal.dataset.lgLocked;
                        if (modalProcessingEl) modalProcessingEl.hidden = true;
                        showCheckoutError(result.error.message || 'Payment failed. Please try again.');
                        if (payLabelEl) payLabelEl.textContent = origLabel || 'Pay';
                        payBt.disabled = false;
                        return;
                    }

                    // Success path — drive the redirect ourselves so Stripe's
                    // beforeunload doesn't fire on its auto-redirect:
                    //   1) Read the session ID off the live custom-checkout
                    //   2) Hide the modal
                    //   3) Unmount the Payment Element (removes Stripe's beforeunload)
                    //   4) Show processing overlay during the brief /v1/return → /activity/ hop
                    //   5) window.location.href ourselves
                    let sessionId = '';
                    try { sessionId = (customCheckout.session && customCheckout.session().id) || ''; } catch (_) {}
                    const returnUrl = sessionId
                        ? '<?php echo esc_js( esc_url_raw( home_url( '/billing/v1/return' ) ) ); ?>?session_id=' + encodeURIComponent(sessionId)
                        : '<?php echo esc_js( esc_url_raw( home_url( '/activity/' ) ) ); ?>';

                    if (joinCheckoutModal) joinCheckoutModal.hidden = true;
                    teardownCheckoutMount();

                    joinPaymentCompleted = true;
                    if (joinProcessingOverlay) joinProcessingOverlay.hidden = false;
                    document.body.classList.add('lg-modal-open');

                    window.location.href = returnUrl;
                } catch (err) {
                    if (joinCheckoutModal) delete joinCheckoutModal.dataset.lgLocked;
                    if (modalProcessingEl) modalProcessingEl.hidden = true;
                    showCheckoutError('Network error: ' + (err && err.message ? err.message : err));
                    if (payLabelEl) payLabelEl.textContent = origLabel || 'Pay';
                    payBt.disabled = false;
                }
            }
            if (payBt) payBt.addEventListener('click', onPayClick);

            function closeJoinCheckoutModal() {
                if (joinCheckoutModal) joinCheckoutModal.hidden = true;
                document.body.classList.remove('lg-modal-open');
                teardownCheckoutMount();
            }

            // Two-stage close protection (see gift flow above for full rationale).
            let joinPaymentInFlight = false;
            let joinPaymentCompleted = false;
            const joinProcessingOverlay = document.querySelector('[data-lg-join-processing]');
            if (joinProcessingOverlay && joinProcessingOverlay.parentNode !== document.body) {
                document.body.appendChild(joinProcessingOverlay);
            }
            function markJoinPaymentInFlight() {
                joinPaymentInFlight = true;
                window.addEventListener('beforeunload', preventJoinNavWhileProcessing);
            }
            function showJoinProcessingOverlay() {
                joinPaymentCompleted = true;
                // Lock the modal NOW (post-pay) — X gone, backdrop click-through
                // disabled — so the user can't accidentally tear down the iframe
                // mid-redirect. Until this runs they could still back out.
                if (joinCheckoutModal) joinCheckoutModal.dataset.lgLocked = '1';
                markJoinPaymentInFlight();
                if (joinProcessingOverlay) joinProcessingOverlay.hidden = false;
                document.body.classList.add('lg-modal-open');
                // Stripe is about to redirect — same rationale as gift flow.
                window.removeEventListener('beforeunload', preventJoinNavWhileProcessing);
            }
            function preventJoinNavWhileProcessing(e) {
                if (!joinPaymentInFlight && !joinPaymentCompleted) return;
                e.preventDefault();
                e.returnValue = '';
                return '';
            }

            document.querySelectorAll('[data-lg-join-checkout-close]').forEach(el => {
                el.addEventListener('click', () => {
                    if (joinPaymentCompleted) { showJoinProcessingOverlay(); return; }
                    if (joinPaymentInFlight) return;
                    closeJoinCheckoutModal();
                });
            });

            // Wire step 2 buttons
            continueBt.addEventListener('click', startCheckout);
            backBt.addEventListener('click', function(){
                pendingPriceId = null;
                document.querySelectorAll('.lg-join__tier').forEach(c => c.classList.remove('is-selected'));
                document.querySelectorAll('.lg-join__buy.is-selected, .lg-join__trial-btn.is-selected').forEach(b => b.classList.remove('is-selected'));
                closeJoinCheckoutModal();
                closeSignupModal();
            });

            loadProducts();
        })();
        </script>
        <?php
        return (string) ob_get_clean();
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

        // Active-subscriber heads-up: RedeemController already refuses
        // redemption for emails with an active sub. Surface that on the
        // page so the user doesn't waste time filling the form just to
        // get rejected — and offer a path to manage / cancel the sub.
        $hasActiveSub = false;
        $activeSubEnds = '';
        if ( $isLoggedIn && $emailValue !== '' ) {
            try {
                $pdo  = \LGMS\Db::pdo();
                $stmt = $pdo->prepare(
                    "SELECT s.current_period_end
                       FROM subscriptions s
                       JOIN customers c ON c.id = s.customer_id
                      WHERE c.email = ?
                        AND s.status IN ('active','trialing','past_due')
                      ORDER BY s.id DESC LIMIT 1"
                );
                $stmt->execute( [ $emailValue ] );
                $row = $stmt->fetch( \PDO::FETCH_ASSOC );
                if ( $row !== false ) {
                    $hasActiveSub  = true;
                    $activeSubEnds = $row['current_period_end'] !== null ? substr( (string) $row['current_period_end'], 0, 10 ) : '';
                }
            } catch ( \Throwable $_ ) {}
        }

        // Pre-fill the code from ?code=... in the URL (links from gift email).
        $codeFromUrl = isset( $_GET['code'] ) ? (string) $_GET['code'] : '';
        $codeFromUrl = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $codeFromUrl ) );
        if ( strlen( $codeFromUrl ) > 12 ) {
            $codeFromUrl = substr( $codeFromUrl, 0, 12 );
        }

        // If the code in the URL maps to a sent gift (recipient_email set),
        // staple the email to the code: pre-fill and lock the email field.
        // Anonymous-mode codes (buyer forwards manually) leave the field
        // editable.
        $stapledEmail = '';
        if ( $codeFromUrl !== '' && strlen( $codeFromUrl ) === 12 ) {
            try {
                $stmt = \LGMS\Db::pdo()->prepare(
                    'SELECT recipient_email FROM gift_codes
                      WHERE code = ?
                        AND recipient_email IS NOT NULL
                        AND recipient_email <> ""
                      LIMIT 1'
                );
                $stmt->execute( [ $codeFromUrl ] );
                $stapledEmail = (string) ( $stmt->fetchColumn() ?: '' );
            } catch ( \Throwable $e ) {
                error_log( 'lg_redeem_gift: recipient_email lookup failed: ' . $e->getMessage() );
            }
        }
        if ( $stapledEmail !== '' ) {
            $emailValue = $stapledEmail;
        }

        // Recipient mismatch: you're logged in, but the gift was sent to a
        // different email. We treat this as logged-out for the purposes of
        // the redeem flow — the recipient must authenticate before the
        // membership lands on their account.
        $wrongUserSignedIn = $isLoggedIn
            && $stapledEmail !== ''
            && strtolower( (string) $user->user_email ) !== strtolower( $stapledEmail );
        $treatAsLoggedIn = $isLoggedIn && ! $wrongUserSignedIn;
        if ( $wrongUserSignedIn ) {
            $nameValue = '';
        }

        // Wrong-user hard fail: a logged-in session whose email doesn't match
        // the gift's recipient. Render the refusal and return early — no
        // form, no fallback. They have to sign out and authenticate as the
        // recipient.
        if ( $wrongUserSignedIn ) {
            $logoutUrl  = esc_url( wp_logout_url( get_permalink() ) );
            $sessEmail  = esc_html( (string) $user->user_email );
            $recipient  = esc_html( $stapledEmail );
            $headingHt  = esc_html( (string) $atts['heading'] );
            return '<div class="lg-redeem-gift">'
                 . '<h3 class="lg-redeem-gift__heading">' . $headingHt . '</h3>'
                 . '<div class="lg-redeem-gift__wronguser" style="margin:0;padding:1.1em 1.2em;background:#fff3f0;border:1px solid #d97757;border-radius:8px;font-size:.95em;line-height:1.5;color:#1f1d1a;">'
                 .   '<strong style="font-size:1.05em;">You&rsquo;re not ' . $recipient . '. This gift isn&rsquo;t for you.</strong><br>'
                 .   'You&rsquo;re signed in as <strong>' . $sessEmail . '</strong>. This code was sent to <strong>' . $recipient . '</strong>, so only that account can redeem it.<br>'
                 .   '<a href="' . $logoutUrl . '" style="display:inline-block;margin-top:.7em;padding:.5em 1em;background:#1f1d1a;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:.92em;">Sign out and try again</a>'
                 . '</div>'
                 . '</div>';
        }

        // Does the recipient_email already have a WP user? (Logged-out
        // visitor for an existing account → render the sign-in variant.)
        $emailHasExistingUser = false;
        if ( $stapledEmail !== '' ) {
            $emailHasExistingUser = ( get_user_by( 'email', $stapledEmail ) !== false );
        }
        $renderSigninVariant = $emailHasExistingUser && ! $treatAsLoggedIn;

        $heading  = esc_html( (string) $atts['heading'] );
        $email    = esc_attr( $emailValue );
        $name     = esc_attr( $nameValue );
        $codeAttr = esc_attr( $codeFromUrl );
        $endpoint = esc_js( $endpointUrl );
        $emailLocked = ( $stapledEmail !== '' );

        ob_start();
        ?>
        <div class="lg-redeem-gift">
            <h3 class="lg-redeem-gift__heading"><?php echo $heading; ?></h3>
            <?php if ( $hasActiveSub ) : ?>
            <div class="lg-redeem-gift__active-sub-park" style="margin:0 0 1.2em;padding:1em 1.1em;background:#fbf6e8;border:1px solid #ECB351;border-radius:8px;font-size:.95em;line-height:1.5;color:#1f1d1a;">
                <strong style="font-size:1.05em;">You already have an active subscription<?php echo $activeSubEnds !== '' ? ' &mdash; renews ' . esc_html( $activeSubEnds ) : ''; ?>.</strong><br>
                No problem. Park this gift and it&rsquo;ll activate the day your subscription ends, so you&rsquo;re covered without a gap and without paying for overlap. Nothing to manage in between.
                <p style="margin:.6em 0 0;font-size:.85em;color:#666;">
                    Prefer to redeem right now? <a href="<?php echo esc_url( home_url( '/manage-subscription/' ) ); ?>">Cancel your subscription</a> first, then come back here once it expires.
                </p>
            </div>
            <?php endif; ?>
            <?php if ( $renderSigninVariant ) : ?>
            <div class="lg-redeem-gift__intro" style="margin:0 0 1.1em;padding:.85em 1em;background:rgba(135,152,106,0.10);border:1px solid rgba(135,152,106,0.35);border-radius:8px;font-size:.93em;line-height:1.45;color:#1f1d1a;">
                <strong>This email already has an account.</strong>
                Sign in below and we&rsquo;ll add this gift to your existing membership.
            </div>
            <?php endif; ?>
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
                        value="<?php echo $codeAttr; ?>"
                        style="text-transform:uppercase;letter-spacing:0.1em;"
                    >
                </label>
                <label class="lg-redeem-gift__label">
                    <span>Email</span>
                    <input
                        type="email"
                        name="email"
                        required
                        value="<?php echo $email; ?>"
                        <?php if ( $emailLocked ) : ?>readonly aria-readonly="true" style="background:#f4f4f0;cursor:not-allowed;color:#444;"<?php endif; ?>
                    >
                    <?php if ( $emailLocked ) : ?>
                    <small style="display:block;margin-top:.3em;color:rgba(0,0,0,0.55);font-size:.85em;line-height:1.4;">
                        This gift was sent to <strong><?php echo esc_html( $emailValue ); ?></strong>. Your account will be created (or signed into) under this email so the membership lands where the sender intended.
                    </small>
                    <?php endif; ?>
                </label>
                <?php if ( ! $renderSigninVariant ) : ?>
                <label class="lg-redeem-gift__label">
                    <span>Name <em style="opacity:.6;">(shown to other members)</em></span>
                    <input type="text" name="name" value="<?php echo $name; ?>" required>
                </label>
                <?php endif; ?>
                <?php if ( ! $treatAsLoggedIn ) : ?>
                <label class="lg-redeem-gift__label">
                    <span>Password</span>
                    <?php if ( $renderSigninVariant ) : ?>
                    <input type="password" name="password" minlength="8" required autocomplete="current-password" placeholder="Your account password">
                    <small style="display:block;margin-top:.3em;color:rgba(0,0,0,0.55);font-size:.85em;line-height:1.4;">
                        <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgot your password?</a>
                    </small>
                    <?php else : ?>
                    <input type="password" name="password" minlength="8" required autocomplete="new-password" placeholder="Pick a password (8+ characters)">
                    <small style="display:block;margin-top:.3em;color:rgba(0,0,0,0.55);font-size:.85em;line-height:1.4;">
                        This becomes your account password so you can log in any time to manage your membership.
                    </small>
                    <?php endif; ?>
                </label>
                <?php endif; ?>
                <button type="submit" class="lg-redeem-gift__submit"><?php echo $renderSigninVariant ? 'Sign in &amp; redeem' : 'Redeem &amp; activate my account'; ?></button>
            </form>
            <div class="lg-redeem-gift__result" data-lg-redeem-result aria-live="polite"></div>

            <style>
                .lg-welcome { position: fixed !important; inset: 0 !important; z-index: 2147483600 !important; display: flex !important; align-items: center !important; justify-content: center !important; padding: 1em !important; }
                .lg-welcome[hidden] { display: none !important; }
                .lg-welcome__backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.65); }
                .lg-welcome__card { position: relative; background: #fff; border-radius: 14px; padding: 1.8em 1.7em; max-width: 440px; width: 100%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.45); color: #1f1d1a; }
                .lg-welcome__icon { font-size: 2.6em; margin-bottom: .25em; line-height: 1; }
                .lg-welcome__title { margin: 0 0 .55em; font-size: 1.3em; font-weight: 700; }
                .lg-welcome__body { margin: 0 0 1.2em; font-size: .96em; line-height: 1.5; color: #333; }
                .lg-welcome__btn { display: inline-block; padding: .7em 1.4em; background: var(--lg-amber, #ECB351); color: #1f1d1a !important; border-radius: 8px; font-weight: 700; text-decoration: none; transition: opacity .15s; }
                .lg-welcome__btn:hover { opacity: .9; }
            </style>
            <div class="lg-welcome" data-lg-welcome-modal hidden role="dialog" aria-modal="true" aria-labelledby="lg-welcome-title">
                <div class="lg-welcome__backdrop"></div>
                <div class="lg-welcome__card">
                    <div class="lg-welcome__icon" aria-hidden="true">&#127881;</div>
                    <h3 id="lg-welcome-title" class="lg-welcome__title">Welcome to the Looth Group!</h3>
                    <p class="lg-welcome__body">
                        Your gift code is redeemed and your account is live. Jump into the activity feed to meet other members, browse the archive, and join a forum.
                    </p>
                    <a class="lg-welcome__btn" data-lg-welcome-go href="<?php echo esc_url( home_url( '/activity/' ) ); ?>">Take me to the feed &rarr;</a>
                </div>
            </div>
        </div>
        <script>
        (function(){
            const ENDPOINT = '<?php echo $endpoint; ?>';
            const form     = document.querySelector('[data-lg-redeem]');
            const resultEl = document.querySelector('[data-lg-redeem-result]');
            const submitBt = form.querySelector('button[type="submit"]');
            if (!form) return;

            // Cache the user's input so we can re-POST with a chosen strategy
            // without making them retype.
            let pending = null;

            async function postRedeem(payload){
                const res  = await fetch(ENDPOINT, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                return res.json();
            }

            const AUTH_URL    = '<?php echo esc_js( esc_url_raw( rest_url( 'lg-member-sync/v1/gift-auth' ) ) ); ?>';
            const ALREADY_IN  = <?php echo $treatAsLoggedIn ? 'true' : 'false'; ?>;
            const EMAIL_HAS_USER = <?php echo $emailHasExistingUser ? 'true' : 'false'; ?>;
            const welcomeEl   = document.querySelector('[data-lg-welcome-modal]');
            if (welcomeEl && welcomeEl.parentNode !== document.body) document.body.appendChild(welcomeEl);

            async function finalizeLogin(email, password, displayName){
                if (ALREADY_IN) return { ok: true };
                try {
                    const res = await fetch(AUTH_URL, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({
                            email, password,
                            display_name: displayName,
                            confirmed_consent: true,
                            redemption_code: (form.querySelector('input[name="code"]')?.value || '').trim().toUpperCase(),
                        }),
                    });
                    return await res.json();
                } catch (e) {
                    return { ok: false, error: 'Could not finish account setup. Try logging in manually.' };
                }
            }

            function showWelcome(){
                if (!welcomeEl) {
                    window.location.href = '<?php echo esc_js( esc_url( home_url( '/activity/' ) ) ); ?>';
                    return;
                }
                welcomeEl.hidden = false;
                document.body.classList.add('lg-modal-open');
            }

            function renderSuccess(json){
                resultEl.className   = 'lg-redeem-gift__result is-success';
                resultEl.textContent = json.message + ' (expires ' + json.expires_at + ')';
                form.reset();
                pending = null;
                // gift-auth already ran and set the cookie before redeem,
                // so we can hop straight to the welcome modal + activity.
                showWelcome();
            }

            function renderError(msg, portalUrl){
                resultEl.className = 'lg-redeem-gift__result is-error';
                if (portalUrl) {
                    resultEl.innerHTML = msg + ' <a href="' + portalUrl + '" target="_blank">Manage your subscription</a>';
                } else {
                    resultEl.textContent = msg;
                }
            }

            // Inline login form shown when an anonymous redeemer hits a
            // tier conflict — they have to authenticate before deciding how
            // to apply the new code to an existing membership.
            function renderConflictLogin(payload) {
                resultEl.className = 'lg-redeem-gift__result is-warn';
                resultEl.innerHTML = '';

                const wrap = document.createElement('div');
                wrap.style.cssText = 'padding:1em 1.1em;background:rgba(255,200,80,0.08);border:1px solid rgba(255,180,40,0.4);border-radius:8px;';
                wrap.innerHTML =
                    '<p style="margin:0 0 .8em;"><strong>This email already has an active membership.</strong><br>' +
                    'Log in to add this gift to your account &mdash; this protects you from anyone else stacking time onto your account without your permission.</p>' +
                    '<div class="lg-redeem-gift__loginrow" style="display:flex;flex-direction:column;gap:.55em;">' +
                        '<input type="email" data-lg-conflict-email value="' + payload.email.replace(/"/g, '&quot;') + '" readonly style="width:100%;padding:.55em .8em;border:1px solid rgba(0,0,0,0.15);border-radius:6px;background:#f4f4f0;color:#444;">' +
                        '<input type="password" data-lg-conflict-pass placeholder="Your account password" autocomplete="current-password" style="width:100%;padding:.55em .8em;border:1px solid rgba(0,0,0,0.15);border-radius:6px;">' +
                    '</div>' +
                    '<div data-lg-conflict-error style="display:none;margin-top:.55em;color:#b91c1c;font-size:.9em;"></div>' +
                    '<div style="margin-top:.85em;display:flex;align-items:center;gap:.85em;flex-wrap:wrap;">' +
                        '<button type="button" data-lg-conflict-go style="padding:.55em 1.1em;background:var(--lg-amber,#ECB351);color:#1f1d1a;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Log in &amp; apply</button>' +
                        '<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" style="font-size:.85em;">Forgot your password?</a>' +
                    '</div>';
                resultEl.appendChild(wrap);

                const passEl = wrap.querySelector('[data-lg-conflict-pass]');
                const errEl  = wrap.querySelector('[data-lg-conflict-error]');
                const btn    = wrap.querySelector('[data-lg-conflict-go]');
                passEl.focus();

                btn.addEventListener('click', async () => {
                    const pwd = passEl.value;
                    if (!pwd || pwd.length < 8) {
                        errEl.textContent = 'Please enter your account password.';
                        errEl.style.display = 'block';
                        return;
                    }
                    errEl.style.display = 'none';
                    btn.disabled = true;
                    btn.textContent = 'Signing in…';

                    try {
                        const res = await fetch(AUTH_URL, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({
                                email:    payload.email,
                                password: pwd,
                                display_name: payload.name,
                                confirmed_consent: true,
                                redemption_code: payload.code,
                            }),
                        });
                        const data = await res.json();
                        if (!data.ok) {
                            errEl.textContent = (data.error || 'Sign-in failed.') +
                                (data.forgot ? ' Use "Forgot your password?" if you need a reset.' : '');
                            errEl.style.display = 'block';
                            btn.disabled    = false;
                            btn.textContent = 'Log in & apply';
                            return;
                        }
                        // Auth cookie is now set. Reload to /lggift/?code=XXX
                        // so the page renders with CONFIG.loggedIn = true and
                        // the next submit hits the ALREADY_IN strategy picker.
                        const url = window.location.pathname + '?code=' + encodeURIComponent(payload.code);
                        window.location.href = url;
                    } catch (e) {
                        errEl.textContent = 'Network error. Please try again.';
                        errEl.style.display = 'block';
                        btn.disabled    = false;
                        btn.textContent = 'Log in & apply';
                    }
                });
            }

            function renderChoice(json){
                pending = { code: json._payload.code, email: json._payload.email, name: json._payload.name };
                const recommended = json.recommended;

                const wrap = document.createElement('div');
                wrap.className = 'lg-redeem-gift__choice';

                const intro = document.createElement('p');
                intro.innerHTML =
                    'You already have <strong>' + json.current.days_remaining +
                    ' days</strong> of <strong>' + json.current.tier + '</strong> active. ' +
                    'How do you want to apply this <strong>' + json.incoming.duration_days +
                    '-day ' + json.incoming.tier + '</strong> code?';
                wrap.appendChild(intro);

                const list = document.createElement('div');
                list.className = 'lg-redeem-gift__options';
                json.options.forEach(function(opt){
                    const id = 'lg-opt-' + opt.id;
                    const row = document.createElement('label');
                    row.className = 'lg-redeem-gift__option';
                    row.htmlFor   = id;
                    row.innerHTML =
                        '<input type="radio" name="strategy" id="' + id + '" value="' + opt.id + '"' +
                        (opt.id === recommended ? ' checked' : '') + '> ' +
                        '<span>' + opt.label + '</span>';
                    list.appendChild(row);
                });
                wrap.appendChild(list);

                const apply = document.createElement('button');
                apply.type        = 'button';
                apply.textContent = 'Apply';
                apply.className   = 'lg-redeem-gift__submit';
                apply.addEventListener('click', applyChoice);
                wrap.appendChild(apply);

                resultEl.className = 'lg-redeem-gift__result';
                resultEl.innerHTML = '';
                resultEl.appendChild(wrap);
            }

            async function applyChoice(){
                const picked = document.querySelector('input[name="strategy"]:checked');
                if (!picked || !pending) return;
                resultEl.className = 'lg-redeem-gift__result is-pending';
                resultEl.textContent = 'Applying…';
                try {
                    const json = await postRedeem(Object.assign({}, pending, { strategy: picked.value }));
                    if (json.ok && !json.requires_choice) {
                        renderSuccess(json);
                    } else {
                        renderError(json.error || 'Unable to apply choice.');
                    }
                } catch (err) {
                    renderError('Network error: ' + err.message);
                }
            }

            form.addEventListener('submit', async function(e){
                e.preventDefault();
                resultEl.textContent = 'Working…';
                resultEl.className   = 'lg-redeem-gift__result is-pending';
                submitBt.disabled    = true;

                const payload = {
                    code:  (form.code.value  || '').trim().toUpperCase(),
                    email: (form.email.value || '').trim(),
                    name:  (form.name && form.name.value ? form.name.value.trim() : ''),
                };
                const password = (form.password ? form.password.value : '');

                // Force-login gate: if the email already has a WP account,
                // we MUST authenticate before redeeming so a stranger with
                // the gift code can't decide what happens to someone else's
                // membership. /gift-auth handles the three cases:
                //   - existing user, correct pw  -> ok, cookie set, proceed
                //   - existing user, wrong pw    -> "Incorrect password"
                //   - new user                   -> created + logged in
                if (!ALREADY_IN) {
                    if (!password || password.length < 8) {
                        resultEl.className = 'lg-redeem-gift__result is-error';
                        resultEl.textContent = 'Please enter a password (8+ characters).';
                        submitBt.disabled = false;
                        return;
                    }
                    try {
                        const authRes = await fetch(AUTH_URL, {
                            method:  'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body:    JSON.stringify({
                                email:    payload.email,
                                password: password,
                                display_name:      payload.name,
                                confirmed_consent: true,
                                redemption_code:   payload.code,
                            }),
                        });
                        const authData = await authRes.json();
                        if (!authData.ok) {
                            resultEl.className = 'lg-redeem-gift__result is-error';
                            resultEl.innerHTML =
                                '<strong>This email already has an account.</strong> ' +
                                'Please enter your account password to log in and redeem this gift.<br>' +
                                '<small>' + (authData.error || 'Sign-in failed.') +
                                (authData.forgot ? ' &middot; <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgot your password?</a>' : '') +
                                '</small>';
                            submitBt.disabled = false;
                            return;
                        }
                        // For existing-user sign-in variant: reload to the
                        // redeem page (code preserved) so the visitor lands
                        // in a fully logged-in state with the standard redeem
                        // form, then submits to actually redeem.
                        if (EMAIL_HAS_USER) {
                            const url = window.location.pathname + '?code=' + encodeURIComponent(payload.code);
                            window.location.href = url;
                            return;
                        }
                        // For brand-new users: proceed to redeem on the
                        // same submission — they explicitly chose to
                        // create an account + redeem in one step.
                    } catch (e) {
                        resultEl.className = 'lg-redeem-gift__result is-error';
                        resultEl.textContent = 'Network error during sign-in. Please try again.';
                        submitBt.disabled = false;
                        return;
                    }
                }

                resultEl.textContent = 'Redeeming…';
                try {
                    const json = await postRedeem(payload);

                    if (json.ok && json.requires_choice) {
                        if (ALREADY_IN) {
                            // Logged-in: they own this account, show the
                            // strategy picker.
                            json._payload = payload;
                            renderChoice(json);
                        } else {
                            // Anonymous: render an inline login form so they
                            // can authenticate without leaving the page.
                            renderConflictLogin(payload);
                        }
                    } else if (json.ok && json.queued) {
                        // Queued redemption — gift will activate when sub ends.
                        const startDate = json.starts_at ? new Date(json.starts_at).toLocaleDateString(undefined, { month:'long', day:'numeric', year:'numeric' }) : 'when your subscription ends';
                        resultEl.innerHTML = '<div style="margin:1em 0;padding:1.1em 1.2em;background:#fbf6e8;border:1px solid #ECB351;border-radius:8px;line-height:1.5;color:#1f1d1a;">' +
                            '<strong style="font-size:1.05em;">🎁 Gift parked!</strong><br>' +
                            'Your gift will activate on <strong>' + startDate + '</strong>, the day your current subscription ends. ' +
                            'Nothing else to do — when the time comes, your membership rolls right over.' +
                            '</div>';
                    } else if (json.ok) {
                        renderSuccess(json);
                    } else if (json.requires_queue) {
                        // Server says: you have an active sub, retry with queue flag.
                        // Auto-retry once with queue_until_sub_ends so the user
                        // gets the parked-state UI without a second click.
                        const retry = await postRedeem(Object.assign({}, payload, { queue_until_sub_ends: true }));
                        if (retry.ok && retry.queued) {
                            const startDate = retry.starts_at ? new Date(retry.starts_at).toLocaleDateString(undefined, { month:'long', day:'numeric', year:'numeric' }) : 'when your subscription ends';
                            resultEl.innerHTML = '<div style="margin:1em 0;padding:1.1em 1.2em;background:#fbf6e8;border:1px solid #ECB351;border-radius:8px;line-height:1.5;color:#1f1d1a;">' +
                                '<strong style="font-size:1.05em;">🎁 Gift parked!</strong><br>' +
                                'Your gift will activate on <strong>' + startDate + '</strong>, the day your current subscription ends.' +
                                '</div>';
                        } else {
                            renderError(retry.error || 'Could not park gift.', retry.portal_url);
                        }
                    } else {
                        renderError(json.error || 'Unable to redeem code.', json.portal_url);
                    }
                } catch (err) {
                    renderError('Network error: ' + err.message);
                } finally {
                    submitBt.disabled = false;
                }
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Look up the active subscription (active/trialing/past_due) for an email,
     * if any. Returns a compact array for rendering, or null.
     *
     * @return array{tier:?string, price_label:string, current_period_end:?string}|null
     */
    private static function lookupActiveSub( string $email ): ?array
    {
        $pdo = \LGMS\Db::pdo();

        $stmt = $pdo->prepare( 'SELECT id FROM customers WHERE email = ? AND deleted_at IS NULL LIMIT 1' );
        $stmt->execute( [ $email ] );
        $cid = $stmt->fetchColumn();
        if ( $cid === false ) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT s.stripe_price_id, s.status, s.current_period_end, p.ref AS tier, pr.unit_amount_cents, pr.interval AS itv
             FROM subscriptions s
             JOIN prices   pr ON pr.stripe_price_id = s.stripe_price_id
             JOIN products p  ON p.id = pr.product_id
             WHERE s.customer_id = ?
               AND s.status IN ('active','trialing','past_due')
             ORDER BY s.id DESC LIMIT 1"
        );
        $stmt->execute( [ (int) $cid ] );
        $row = $stmt->fetch( \PDO::FETCH_ASSOC );
        if ( $row === false ) {
            return null;
        }

        $cents = (int) $row['unit_amount_cents'];
        $itv   = (string) ( $row['itv'] ?? '' );
        $price = '$' . number_format( $cents / 100, $cents % 100 === 0 ? 0 : 2 ) . '/' . ( $itv ?: 'month' );

        return [
            'tier'               => $row['tier'] !== null ? (string) $row['tier'] : null,
            'status'             => (string) $row['status'],
            'price_label'        => $price,
            'current_period_end' => $row['current_period_end'] !== null ? (string) $row['current_period_end'] : null,
        ];
    }

    /**
     * Active-member variant of the join page. The full management UI lives
     * in the lg_manage_membership shortcode (which already renders the
     * "You're already a member" hero when there's an active sub or gift),
     * so /lgjoin/ for an existing member just delegates to it. Single
     * source of truth for the active-member experience.
     *
     * The $sub argument is kept for API compatibility but the render path
     * re-queries through manageSubscription so it picks up gifts +
     * payment methods + billing history too — not just the one sub row.
     */
    private static function renderActiveSubBlock( array $sub ): string
    {
        return (string) do_shortcode( '[lg_manage_membership]' );
    }

    public static function refundRequest( $atts = [] ): string
    {
        $atts = shortcode_atts( [
            'heading' => 'Request a refund',
        ], (array) $atts, 'lg_refund_request' );

        $user     = wp_get_current_user();
        $loggedIn = $user->ID > 0;

        if ( ! $loggedIn ) {
            return '<p><em>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> to request a refund.</em></p>';
        }

        $emailValue  = (string) $user->user_email;
        $nameValue   = trim( (string) ( $user->display_name ?: $user->user_login ) );
        $endpoint    = esc_url_raw( rest_url( 'lg-member-sync/v1/refund-request' ) );
        $heading     = esc_html( (string) $atts['heading'] );
        $emailAttr   = esc_attr( $emailValue );
        $nameAttr    = esc_attr( $nameValue );
        $windowDays  = max( 1, (int) get_option( 'lgms_refund_window_days', '30' ) );

        // For logged-in users, look up their actual purchases so we can show
        // them what's eligible for refund. Anonymous users get a free-form
        // request flow (no items shown; admin reviews everything).
        $items = $loggedIn ? self::eligibleRefundItems( $emailValue, $windowDays ) : [];

        $reasons = [
            'I was charged in error or did not intend to subscribe',
            'Duplicate or unauthorized charge',
            'I was charged after canceling my subscription',
            'I cannot access the content I paid for',
            'The service is not working as advertised',
            'A technical issue is preventing me from using the site',
            'Other (please explain in comments)',
        ];

        ob_start();
        ?>
        <div class="lg-refund">
            <h3 class="lg-refund__heading"><?php echo $heading; ?></h3>
            <p class="lg-refund__intro">Sorry to see you go. Tell us a bit about why and we'll process your refund.</p>
            <p class="lg-refund__policy" style="font-size:0.95em;color:#444;">
                <strong>Our refund policy:</strong> We refund subscription charges and gift purchases within
                <strong><?php echo (int) $windowDays; ?> days</strong> of the original charge.
                Items outside the window are reviewed case-by-case &mdash; submit a request and we'll get back to you.
            </p>
            <form class="lg-refund__form" data-lg-refund>
                <div class="lg-refund__row">
                    <label class="lg-refund__label"><span>Name</span>
                        <input type="text" name="name" required value="<?php echo $nameAttr; ?>">
                    </label>
                    <label class="lg-refund__label"><span>Email</span>
                        <input type="email" name="email" required value="<?php echo $emailAttr; ?>">
                    </label>
                </div>

                <?php if ( $loggedIn && $items !== [] ) : ?>
                <fieldset class="lg-refund__fieldset">
                    <legend>What would you like refunded? <em style="opacity:.6;">(pick one &mdash; submit again for additional items)</em></legend>
                    <div class="lg-refund__items">
                        <?php foreach ( $items as $i => $item ) :
                            $id    = 'lg-refund-item-' . $i;
                            $value = $item['kind'] . ':' . $item['id'];
                            $note  = $item['eligible']
                                ? '<em style="color:#080;">Within refund window</em>'
                                : '<em style="color:#b00;">Outside ' . (int) $windowDays . '-day window &mdash; we will still review your request</em>';
                        ?>
                            <label class="lg-refund__item" for="<?php echo esc_attr( $id ); ?>" style="display:block;padding:0.4em 0;">
                                <input type="radio" id="<?php echo esc_attr( $id ); ?>" name="items[]" value="<?php echo esc_attr( $value ); ?>" data-eligible="<?php echo $item['eligible'] ? '1' : '0'; ?>">
                                <strong><?php echo esc_html( $item['label'] ); ?></strong>
                                <span style="color:#666;">&mdash; <?php echo esc_html( $item['detail'] ); ?></span>
                                <br>
                                <span style="margin-left:1.6em;font-size:0.9em;"><?php echo $note; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php elseif ( $loggedIn ) : ?>
                    <p style="color:#666;font-style:italic;">We did not find any refundable purchases on your account. You can still submit a request below if you believe this is in error.</p>
                <?php endif; ?>

                <fieldset class="lg-refund__fieldset">
                    <legend>Why are you requesting a refund? <em style="opacity:.6;">(select all that apply)</em></legend>
                    <div class="lg-refund__reasons">
                        <?php foreach ( $reasons as $reason ) : $id = 'lg-refund-r-' . sanitize_title( $reason ); ?>
                            <label class="lg-refund__reason" for="<?php echo esc_attr( $id ); ?>">
                                <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="reasons[]" value="<?php echo esc_attr( $reason ); ?>">
                                <span><?php echo esc_html( $reason ); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <label class="lg-refund__label lg-refund__label--full">
                    <span>Anything else you'd like us to know? <em style="opacity:.6;">(optional)</em></span>
                    <textarea name="comments" rows="4"></textarea>
                </label>

                <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">

                <div class="lg-refund__submit-row">
                    <button type="submit" class="lg-refund__submit">Send refund request</button>
                </div>
            </form>
            <div class="lg-refund__result" data-lg-refund-result aria-live="polite"></div>
        </div>
        <script>
        (function(){
            const ENDPOINT = '<?php echo esc_js( $endpoint ); ?>';
            const form     = document.querySelector('[data-lg-refund]');
            const resultEl = document.querySelector('[data-lg-refund-result]');
            const submitBt = form ? form.querySelector('button[type="submit"]') : null;
            if (!form) return;

            form.addEventListener('submit', async function(e){
                e.preventDefault();
                const reasons = Array.from(form.querySelectorAll('input[name="reasons[]"]:checked')).map(i => i.value);
                const items   = Array.from(form.querySelectorAll('input[name="items[]"]:checked')).map(i => i.value);
                if (reasons.length === 0) {
                    resultEl.className   = 'lg-refund__result is-error';
                    resultEl.textContent = 'Please select at least one reason.';
                    return;
                }
                const payload = {
                    name:     (form.name.value     || '').trim(),
                    email:    (form.email.value    || '').trim(),
                    reasons:  reasons,
                    items:    items,
                    comments: (form.comments.value || '').trim(),
                    website:  (form.website.value  || '').trim(),
                };
                submitBt.disabled = true;
                resultEl.className   = 'lg-refund__result is-pending';
                resultEl.textContent = 'Sending...';
                try {
                    const res  = await fetch(ENDPOINT, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(payload),
                    });
                    const data = await res.json();
                    if (data.ok) {
                        form.style.display    = 'none';
                        resultEl.className    = 'lg-refund__result is-success';
                        resultEl.innerHTML    = '<strong>Thanks - we got your request.</strong> We will review it within a couple of business days and email you when the refund is processed.';
                    } else {
                        resultEl.className   = 'lg-refund__result is-error';
                        resultEl.textContent = data.error || 'Could not send your request. Please try again.';
                    }
                } catch (err) {
                    resultEl.className   = 'lg-refund__result is-error';
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

    /**
     * Refundable items for the given email. Returns subscriptions whose
     * latest charge is within $windowDays plus gift purchases (grouped by
     * checkout session) where any unredeemed/unvoided codes remain. Items
     * outside the window are still returned with eligible=false so the
     * customer can see them and request a manual review.
     *
     * @return list<array{kind:string,id:string,label:string,detail:string,eligible:bool}>
     */
    private static function eligibleRefundItems( string $email, int $windowDays ): array
    {
        try {
            $customer = \LGMS\Repos\CustomerRepo::findByEmail( $email );
        } catch ( \Throwable $_ ) {
            return [];
        }
        if ( $customer === null ) {
            return [];
        }
        $customerId = (int) $customer['id'];
        $cutoffTs   = time() - ( $windowDays * 86400 );
        $items      = [];

        try {
            // Active subscriptions. Use current_period_start as the effective
            // "last charged at" -- accurate enough for the window check; the
            // admin endpoint refunds the actual latest paid invoice.
            $stmt = \LGMS\Db::pdo()->prepare(
                "SELECT stripe_subscription_id, stripe_price_id, status, current_period_start, current_period_end
                 FROM subscriptions
                 WHERE customer_id = ? AND status IN ('active','trialing','past_due')
                 ORDER BY id DESC"
            );
            $stmt->execute( [ $customerId ] );
            foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
                $chargedAt = $row['current_period_start'];
                $eligible  = $chargedAt && strtotime( (string) $chargedAt ) >= $cutoffTs;
                $tier      = self::tierLabelForPrice( (string) $row['stripe_price_id'] );
                $detail    = $tier
                    ? "{$tier}, last charged " . self::shortDate( (string) $chargedAt )
                    : 'last charged ' . self::shortDate( (string) $chargedAt );
                $items[] = [
                    'kind'     => 'subscription',
                    'id'       => (string) $row['stripe_subscription_id'],
                    'label'    => 'Subscription',
                    'detail'   => $detail,
                    'eligible' => (bool) $eligible,
                ];
            }

            // Gift purchases grouped by checkout session.
            $stmt = \LGMS\Db::pdo()->prepare(
                "SELECT stripe_session_id, MIN(created_at) AS purchased_at, COUNT(*) AS qty,
                        SUM(redeemed_at IS NOT NULL) AS redeemed,
                        SUM(voided_at   IS NOT NULL) AS voided
                 FROM gift_codes
                 WHERE purchased_by = ? AND stripe_session_id IS NOT NULL
                 GROUP BY stripe_session_id
                 ORDER BY MIN(id) DESC"
            );
            $stmt->execute( [ $customerId ] );
            foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
                $totalQty = (int) $row['qty'];
                $voided   = (int) $row['voided'];
                $redeemed = (int) $row['redeemed'];
                $active   = $totalQty - $voided - $redeemed;
                if ( $active <= 0 ) {
                    continue; // nothing left to refund (already redeemed or voided)
                }
                $purchasedAt = (string) $row['purchased_at'];
                $eligible    = $purchasedAt && strtotime( $purchasedAt ) >= $cutoffTs;
                $detail      = "{$totalQty}-seat purchase on " . self::shortDate( $purchasedAt );
                if ( $redeemed > 0 ) {
                    $detail .= " ({$active} unredeemed codes refundable; {$redeemed} already used)";
                } else {
                    $detail .= " ({$active} active codes)";
                }
                $items[] = [
                    'kind'     => 'gift_purchase',
                    'id'       => (string) $row['stripe_session_id'],
                    'label'    => 'Gift purchase',
                    'detail'   => $detail,
                    'eligible' => (bool) $eligible,
                ];
            }
        } catch ( \Throwable $_ ) {
            return [];
        }

        return $items;
    }

    private static function tierLabelForPrice( string $priceId ): string
    {
        if ( $priceId === '' ) {
            return '';
        }
        try {
            $stmt = \LGMS\Db::pdo()->prepare(
                'SELECT pr.name AS product_name FROM prices pp JOIN products pr ON pr.id = pp.product_id WHERE pp.stripe_price_id = ? LIMIT 1'
            );
            $stmt->execute( [ $priceId ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            return $row ? (string) $row['product_name'] : '';
        } catch ( \Throwable $_ ) {
            return '';
        }
    }

    /**
     * Friendly label from a tier ref (looth1/looth2/looth3) by joining
     * to the products table. Used for gift-sourced entitlements where
     * we have the tier ref but no Stripe price ID to look up by.
     */
    private static function tierLabelForRef( string $ref ): string
    {
        if ( $ref === '' ) {
            return '';
        }
        try {
            $stmt = \LGMS\Db::pdo()->prepare(
                'SELECT name FROM products WHERE ref = ? LIMIT 1'
            );
            $stmt->execute( [ $ref ] );
            $row = $stmt->fetch( \PDO::FETCH_ASSOC );
            if ( $row && (string) $row['name'] !== '' ) {
                return (string) $row['name'];
            }
        } catch ( \Throwable $_ ) {}
        return match ( $ref ) {
            'looth2' => 'Looth LITE',
            'looth3' => 'Looth PRO',
            default  => ucfirst( $ref ),
        };
    }

    private static function shortDate( string $datetime ): string
    {
        $ts = $datetime ? strtotime( $datetime ) : false;
        return $ts ? gmdate( 'M j, Y', $ts ) : 'unknown date';
    }

    private static function daysRemaining( string $datetime ): ?int
    {
        $ts = $datetime ? strtotime( $datetime ) : false;
        if ( ! $ts ) return null;
        $diff = $ts - time();
        return $diff > 0 ? (int) ceil( $diff / DAY_IN_SECONDS ) : 0;
    }

    /**
     * [lg_regional_fail] — landing page after a regional verify-fail.
     *
     * Slim's ReturnHandler::handleRegionalVerify() 302's the browser here when
     * the billing-address country and/or card-issuer country don't match the
     * region_tag the customer tried to buy at. The redirect URL carries:
     *   ?reason=region_mismatch
     *   &region_tag=regional_a|regional_b
     *   &billing_country=XX (from PaymentMethod.billing_details.address.country)
     *   &issuer_country=YY  (from PaymentMethod.card.country — bank-set, can't be spoofed)
     *   &standard_price_id=price_xxx (the equivalent standard-tier price for upsell)
     *
     * No state changes here — pure render of the diagnostic. The PM was already
     * detached server-side; no charge ever happened.
     */
    public static function regionalFail( $atts = [] ): string
    {
        $atts = shortcode_atts( [
            'heading' => "We couldn't apply regional pricing",
        ], (array) $atts, 'lg_regional_fail' );

        $reason          = isset( $_GET['reason'] ) ? (string) $_GET['reason'] : '';
        $regionTag       = isset( $_GET['region_tag'] ) ? preg_replace( '/[^a-z_]/', '', (string) $_GET['region_tag'] ) : '';
        $billingCountry  = isset( $_GET['billing_country'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $_GET['billing_country'] ) ) : '';
        $issuerCountry   = isset( $_GET['issuer_country'] )  ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $_GET['issuer_country']  ) ) : '';
        $standardPriceId = isset( $_GET['standard_price_id'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $_GET['standard_price_id'] ) : '';

        $billingCountry  = strlen( $billingCountry ) === 2 ? $billingCountry : '';
        $issuerCountry   = strlen( $issuerCountry  ) === 2 ? $issuerCountry  : '';

        // ?country=US on the join link forces standard pricing irrespective of
        // the visitor's actual auto-detected country, so the customer doesn't
        // bounce back into the same regional flow.
        $joinUrl       = home_url( '/lgjoin/?country=US' );
        $supportEmail  = (string) get_option( 'lgms_refund_email', get_option( 'admin_email', '' ) );
        $supportEmail  = is_email( $supportEmail ) ? $supportEmail : '';

        // Friendly explanation that names the specific check that failed.
        $regionLabel = $regionTag === 'regional_a' ? 'regional discount' : ( $regionTag === 'regional_b' ? 'regional discount' : 'regional pricing' );
        $explanation = '';
        if ( $billingCountry !== '' && $issuerCountry !== '' ) {
            $explanation = sprintf(
                'You entered <strong>%s</strong> as your billing address, and the card you used is issued by a bank in <strong>%s</strong>. To qualify for our %s, both your billing address <em>and</em> your card issuer need to be in the same eligible region.',
                esc_html( $billingCountry ),
                esc_html( $issuerCountry ),
                esc_html( $regionLabel )
            );
        } elseif ( $billingCountry !== '' ) {
            $explanation = sprintf(
                'You entered <strong>%s</strong> as your billing address, which isn\'t in the eligible list for our %s.',
                esc_html( $billingCountry ),
                esc_html( $regionLabel )
            );
        } else {
            $explanation = sprintf(
                'We couldn\'t verify your eligibility for our %s.',
                esc_html( $regionLabel )
            );
        }

        ob_start();
        ?>
        <div class="lg-regional-fail">
            <h3 class="lg-regional-fail__heading"><?php echo esc_html( $atts['heading'] ); ?></h3>

            <p class="lg-regional-fail__intro">
                Your card wasn't charged, and the payment method has been removed from our system &mdash; nothing further is needed from you.
            </p>

            <p class="lg-regional-fail__detail"><?php echo $explanation; /* already escaped above */ ?></p>

            <?php if ( $reason !== 'region_mismatch' ) : ?>
                <p class="lg-regional-fail__notice" style="opacity:.7;font-size:0.9em;">
                    Note: this page is meant to be reached from a checkout-verification redirect. If you arrived here directly, the links below will get you back on track.
                </p>
            <?php endif; ?>

            <div class="lg-regional-fail__actions">
                <a class="lg-regional-fail__cta is-primary" href="<?php echo esc_url( $joinUrl ); ?>">
                    Subscribe at standard pricing
                </a>
                <?php if ( $supportEmail !== '' ) : ?>
                    <a class="lg-regional-fail__cta" href="mailto:<?php echo esc_attr( $supportEmail ); ?>?subject=<?php echo rawurlencode( 'Question about regional pricing eligibility' ); ?>">
                        Contact support
                    </a>
                <?php endif; ?>
            </div>

            <?php if ( $standardPriceId !== '' ) : ?>
                <!-- standard_price_id from referrer: <?php echo esc_html( $standardPriceId ); ?> -->
            <?php endif; ?>
        </div>

        <style>
            .lg-regional-fail { max-width: 640px; margin: 0 auto; padding: 1.5em 0; }
            .lg-regional-fail__heading { margin-top: 0; }
            .lg-regional-fail__intro { font-size: 1.05em; }
            .lg-regional-fail__detail { padding: 0.8em 1em; background: rgba(0,0,0,0.04); border-radius: 6px; }
            .lg-regional-fail__actions { display: flex; flex-wrap: wrap; gap: 0.6em; margin-top: 1.4em; }
            .lg-regional-fail__cta { display: inline-block; padding: 0.6em 1.1em; border-radius: 4px; text-decoration: none; border: 1px solid currentColor; }
            .lg-regional-fail__cta.is-primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; border-color: transparent; font-weight: 600; }
            .lg-regional-fail__cta.is-primary:hover { filter: brightness(0.95); }
        </style>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [lg_subscription_success] — landing page after a successful checkout completion.
     *
     * Slim's ReturnHandler 302's the browser here on every successful path
     * (subscription, regional verify pass, one-time annual, gift). Query params
     * tell us which kind so we can tailor the message:
     *   ?kind=subscription           &tier=looth2|looth3
     *   ?kind=regional_subscription  &tier=looth2|looth3            (regional pass)
     *   ?kind=membership_annual      &tier=looth2|looth3 &expires_at=YYYY-MM-DD
     *   ?kind=gift                   &qty=N                          (codes already emailed)
     *
     * Body content is purely informational — the actual provisioning already
     * happened server-side before this page loads.
     */
    public static function subscriptionSuccess( $atts = [] ): string
    {
        $atts = shortcode_atts( [
            'heading' => "You're in!",
        ], (array) $atts, 'lg_subscription_success' );

        $kind        = isset( $_GET['kind'] )       ? preg_replace( '/[^a-z_]/', '', (string) $_GET['kind'] ) : 'subscription';
        $tier        = isset( $_GET['tier'] )       ? preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $_GET['tier'] ) : '';
        $qty         = isset( $_GET['qty'] )        ? max( 1, (int) $_GET['qty'] ) : 1;
        $expiresAt   = isset( $_GET['expires_at'] ) ? preg_replace( '/[^0-9\-]/', '', (string) $_GET['expires_at'] ) : '';

        $tierLabel = match ( $tier ) {
            'looth2' => 'Looth LITE',
            'looth3' => 'Looth PRO',
            default  => 'Looth membership',
        };

        // Per-kind copy. All branches end with the same next-steps section.
        $headlineHtml = '';
        $bodyHtml     = '';
        switch ( $kind ) {
            case 'gift':
                $headlineHtml = sprintf(
                    'Thanks for your gift purchase &mdash; <strong>%d %s</strong> code%s on the way.',
                    $qty,
                    esc_html( $tierLabel ),
                    $qty === 1 ? '' : 's'
                );
                $bodyHtml = '<p>We just emailed your gift code' . ( $qty === 1 ? '' : 's' ) . ' to the address you used at checkout. Each code can be redeemed at <a href="' . esc_url( home_url( '/lggift/' ) ) . '">our redemption page</a>; share them however you like. Codes don\'t expire until they\'re redeemed.</p>';
                break;

            case 'membership_annual':
                $expiresLine = $expiresAt !== ''
                    ? sprintf( ' Your access runs through <strong>%s</strong>.', esc_html( $expiresAt ) )
                    : '';
                $headlineHtml = sprintf(
                    'Your <strong>%s</strong> annual membership is active.',
                    esc_html( $tierLabel )
                );
                $bodyHtml = '<p>Thanks for joining.' . $expiresLine . ' This was a one-time purchase &mdash; you won\'t be charged again automatically. We\'ll send a reminder before your access ends.</p>';
                break;

            case 'regional_subscription':
                $headlineHtml = sprintf(
                    'Welcome &mdash; your <strong>%s</strong> regional subscription is active.',
                    esc_html( $tierLabel )
                );
                $bodyHtml = '<p>Your billing region was verified and your first invoice has been charged at the regional rate. The same rate applies on every renewal.</p>';
                break;

            case 'subscription':
            default:
                $headlineHtml = sprintf(
                    'Welcome &mdash; your <strong>%s</strong> subscription is active.',
                    esc_html( $tierLabel )
                );
                $bodyHtml = '<p>Thanks for joining. Your first invoice has been paid; you\'ll be billed again automatically when the next period starts.</p>';
                break;
        }

        // Account-management hint applies to every recurring kind, not gifts.
        $manageHint = '';
        if ( $kind !== 'gift' ) {
            $manageUrl  = home_url( '/manage-subscription/' );
            $manageHint = '<p class="lg-success__manage">You can change plan, update your card, or cancel any time at <a href="' . esc_url( $manageUrl ) . '">Manage Subscription</a>.</p>';
        }

        ob_start();
        ?>
        <div class="lg-success">
            <h3 class="lg-success__heading"><?php echo esc_html( $atts['heading'] ); ?></h3>
            <p class="lg-success__headline"><?php echo $headlineHtml; /* already escaped */ ?></p>
            <div class="lg-success__body"><?php echo $bodyHtml; /* contains intentional HTML */ ?></div>
            <?php echo $manageHint; ?>
            <div class="lg-success__actions">
                <a class="lg-success__cta is-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">Head to the community</a>
            </div>
        </div>

        <style>
            .lg-success { max-width: 640px; margin: 0 auto; padding: 1.5em 0; }
            .lg-success__heading { margin-top: 0; }
            .lg-success__headline { font-size: 1.15em; }
            .lg-success__body { padding: 0.8em 1em; background: rgba(0,0,0,0.04); border-radius: 6px; }
            .lg-success__manage { font-size: 0.95em; opacity: 0.85; }
            .lg-success__actions { display: flex; flex-wrap: wrap; gap: 0.6em; margin-top: 1.4em; }
            .lg-success__cta { display: inline-block; padding: 0.6em 1.1em; border-radius: 4px; text-decoration: none; border: 1px solid currentColor; }
            .lg-success__cta.is-primary { background: var(--lg-amber, #ECB351); color: #1f1d1a; border-color: transparent; font-weight: 600; }
            .lg-success__cta.is-primary:hover { filter: brightness(0.95); }
        </style>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * [lg_my_gifts] — buyer-facing gift dashboard.
     *
     * Lists gift codes purchased by the currently-logged-in user, split into
     * four buckets: Unsent, Sent, Redeemed, Voided.
     *
     * Phase D: all four action buttons (Send / Resend / Reassign / Void) are
     * wired to /wp-json/lg-member-sync/v1/me/gift-* via fetch(). Inline forms
     * open in-row for Send and Reassign. Void triggers a confirm dialog. Batch
     * send lets the buyer select multiple unsent codes and supply recipients at once.
     */
    public static function myGifts( $atts = [] ): string
    {
        shortcode_atts( [], (array) $atts, 'lg_my_gifts' );

        $user = wp_get_current_user();
        if ( ! $user || $user->ID === 0 ) {
            return '<div class="lg-mygifts lg-mygifts--anon"><p><em>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> to view your gift codes.</em></p></div>';
        }
        if ( ! user_can( $user, \LGMS\Plugin::GIFT_CAP ) ) {
            return '<div class="lg-mygifts lg-mygifts--noperm"><p><em>Your account doesn\'t have access to gift management. <a href="' . esc_url( home_url( '/lggift-buy/' ) ) . '">Buy a gift code</a> to get started.</em></p></div>';
        }
        $email = (string) $user->user_email;
        if ( $email === '' ) {
            return '<div class="lg-mygifts lg-mygifts--noemail"><p><em>Your account is missing an email address — please contact support.</em></p></div>';
        }

        // If the dashboard link came from an email and carries ?for=<expected>,
        // sanity-check it against the current session — flag the mismatch
        // so the visitor doesn't sit on a stranger's empty dashboard
        // wondering where their codes went.
        $expectedEmail = isset( $_GET['for'] ) ? sanitize_email( rawurldecode( (string) $_GET['for'] ) ) : '';
        if ( $expectedEmail !== '' && strtolower( $expectedEmail ) !== strtolower( $email ) ) {
            $logoutUrl = esc_url( wp_logout_url( get_permalink() ) );
            return '<div class="lg-mygifts lg-mygifts--oops" style="max-width:640px;margin:1em auto;padding:1.2em 1.4em;background:#fff3f0;border:1px solid #d97757;border-radius:8px;color:#1f1d1a;line-height:1.5;">'
                 . '<p style="margin:0 0 .6em;font-size:1.05em;"><strong>Oops &mdash; you&rsquo;re signed in as the wrong account.</strong></p>'
                 . '<p style="margin:0 0 .9em;">This gift dashboard belongs to <strong>' . esc_html( $expectedEmail ) . '</strong>, but you&rsquo;re currently signed in as <strong>' . esc_html( $email ) . '</strong>.</p>'
                 . '<a href="' . $logoutUrl . '" style="display:inline-block;padding:.5em 1em;background:#1f1d1a;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;font-size:.92em;">Sign out and try again</a>'
                 . '</div>';
        }

        try {
            $pdo  = \LGMS\Db::pdo();
            $stmt = $pdo->prepare(
                'SELECT g.id, g.code, g.tier, g.duration_days,
                        g.recipient_email, g.recipient_name, g.gift_message,
                        g.email_sent_at, g.redeemed_at, g.voided_at, g.created_at,
                        rc.email AS redeemer_email
                 FROM gift_codes g
                 JOIN customers c ON c.id = g.purchased_by
                 LEFT JOIN customers rc ON rc.id = g.redeemed_by
                 WHERE c.email = ?
                 ORDER BY g.id DESC'
            );
            $stmt->execute( [ $email ] );
            $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
        } catch ( \Throwable $e ) {
            error_log( 'LGMS [lg_my_gifts]: DB error: ' . $e->getMessage() );
            return '<div class="lg-mygifts lg-mygifts--err"><p><em>Couldn\'t load your gift codes right now. Please try again in a moment.</em></p></div>';
        }

        $unsent   = [];
        $sent     = [];
        $redeemed = [];
        $voided   = [];
        foreach ( $rows as $r ) {
            if ( ! empty( $r['voided_at'] ) ) {
                $voided[] = $r;
            } elseif ( ! empty( $r['redeemed_at'] ) ) {
                $redeemed[] = $r;
            } elseif ( ! empty( $r['recipient_email'] ) ) {
                $sent[] = $r;
            } else {
                $unsent[] = $r;
            }
        }

        $totalActive = count( $unsent ) + count( $sent ) + count( $redeemed );
        $tierLabel   = static fn ( string $t ): string => match ( $t ) {
            'looth2' => 'Looth LITE',
            'looth3' => 'Looth PRO',
            default  => 'Looth',
        };

        $jsConfig = wp_json_encode( [
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'restBase' => rest_url( 'lg-member-sync/v1' ),
        ] );

        ob_start();
        ?>
        <div class="lg-mygifts" id="lg-mygifts-root">
            <header class="lg-mygifts__hero">
                <h2 class="lg-mygifts__heading">My Gifts</h2>
                <?php if ( $totalActive === 0 && count( $voided ) === 0 ) : ?>
                    <p class="lg-mygifts__intro">You haven't purchased any gift codes yet. <a href="<?php echo esc_url( home_url( '/lggift-buy/' ) ); ?>">Buy gift codes →</a></p>
                <?php else : ?>
                    <p class="lg-mygifts__intro">
                        <?php printf( esc_html( '%d total · %d to send · %d sent · %d redeemed' ), $totalActive, count( $unsent ), count( $sent ), count( $redeemed ) ); ?>
                        <a class="lg-mygifts__buy-more" href="<?php echo esc_url( home_url( '/lggift-buy/' ) ); ?>">Buy more →</a>
                    </p>
                <?php endif; ?>
            </header>

            <div class="lg-mygifts__toast" id="lg-gift-toast" aria-live="polite" hidden></div>

            <?php if ( $unsent !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--unsent" id="lg-section-unsent">
                <div class="lg-mygifts__section-header">
                    <h3>Ready to send <span class="lg-mygifts__count" id="lg-unsent-count">(<?php echo count( $unsent ); ?>)</span></h3>
                    <?php if ( count( $unsent ) > 1 ) : ?>
                    <div class="lg-batch-controls" id="lg-batch-controls">
                        <button type="button" class="lg-btn lg-btn--sm" id="lg-batch-toggle">Select for batch send</button>
                        <button type="button" class="lg-btn lg-btn--primary lg-btn--sm" id="lg-batch-send-btn" hidden>
                            Send selected (<span id="lg-batch-count">0</span>)
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <p class="lg-mygifts__hint">Send each code to a recipient — or grab the code value and forward it yourself.</p>

                <div class="lg-batch-panel" id="lg-batch-panel" hidden>
                    <p class="lg-batch-panel__intro">Fill in recipients for the selected codes, then click <strong>Send all</strong>.</p>
                    <div id="lg-batch-rows"></div>
                    <div class="lg-batch-panel__shared">
                        <label>Optional message to include with every email<br>
                            <textarea class="lg-batch-panel__msg-area" id="lg-batch-msg" rows="2" placeholder="Optional message for all recipients…"></textarea>
                        </label>
                        <button type="button" class="lg-btn lg-btn--sm" id="lg-batch-apply-msg">Apply message to all</button>
                    </div>
                    <div class="lg-batch-panel__footer">
                        <button type="button" class="lg-btn lg-btn--primary" id="lg-batch-fire">Send all</button>
                        <button type="button" class="lg-btn" id="lg-batch-cancel">Cancel</button>
                        <span class="lg-batch-panel__progress" id="lg-batch-progress" hidden></span>
                    </div>
                </div>

                <table class="lg-mygifts__table" id="lg-table-unsent">
                    <thead><tr>
                        <th class="lg-col-check" id="lg-col-check-hdr" hidden><input type="checkbox" id="lg-select-all" title="Select all"></th>
                        <th>Tier</th><th>Code</th><th>Created</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $unsent as $r ) : ?>
                        <tr class="lg-row" data-code-id="<?php echo (int) $r['id']; ?>">
                            <td class="lg-col-check" hidden>
                                <input type="checkbox" class="lg-row-check"
                                    data-code-id="<?php echo (int) $r['id']; ?>"
                                    data-tier-label="<?php echo esc_attr( $tierLabel( (string) $r['tier'] ) ); ?>">
                            </td>
                            <td><?php echo esc_html( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td><code class="lg-mygifts__code"><?php echo esc_html( (string) $r['code'] ); ?></code></td>
                            <td class="lg-mygifts__when"><?php echo esc_html( self::shortDate( $r['created_at'] ) ); ?></td>
                            <td class="lg-mygifts__actions">
                                <button type="button" class="lg-btn lg-btn--action lg-action-open-send"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Send →</button>
                                <button type="button" class="lg-btn lg-btn--danger-sm lg-action-void"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Void</button>
                                <form class="lg-inline-form lg-send-form" data-code-id="<?php echo (int) $r['id']; ?>" hidden>
                                    <input type="email" class="lg-field" name="recipient_email" placeholder="Recipient email *" required>
                                    <input type="text"  class="lg-field" name="recipient_name"  placeholder="Recipient name (optional)">
                                    <textarea class="lg-field lg-field--msg" name="message" rows="2" placeholder="Optional personal message…"></textarea>
                                    <div class="lg-inline-form__btns">
                                        <button type="submit" class="lg-btn lg-btn--primary lg-btn--sm">Send gift</button>
                                        <button type="button" class="lg-btn lg-btn--sm lg-form-cancel">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <?php if ( $sent !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--sent" id="lg-section-sent">
                <h3>Sent — awaiting redemption <span class="lg-mygifts__count" id="lg-sent-count">(<?php echo count( $sent ); ?>)</span></h3>
                <table class="lg-mygifts__table" id="lg-table-sent">
                    <thead><tr><th>Tier</th><th>Recipient</th><th>Code</th><th>Sent</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ( $sent as $r ) :
                        $rEmail = (string) ( $r['recipient_email'] ?? '' );
                        $rName  = (string) ( $r['recipient_name']  ?? '' );
                    ?>
                        <tr class="lg-row" data-code-id="<?php echo (int) $r['id']; ?>">
                            <td><?php echo esc_html( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td>
                                <?php if ( $rName !== '' ) : ?>
                                    <strong><?php echo esc_html( $rName ); ?></strong><br>
                                    <span class="lg-mygifts__sub"><?php echo esc_html( $rEmail ); ?></span>
                                <?php else : ?>
                                    <?php echo esc_html( $rEmail ); ?>
                                <?php endif; ?>
                            </td>
                            <td><code class="lg-mygifts__code lg-mygifts__code--small"><?php echo esc_html( (string) $r['code'] ); ?></code></td>
                            <td class="lg-mygifts__when"><?php echo esc_html( self::shortDate( $r['email_sent_at'] ?? $r['created_at'] ) ); ?></td>
                            <td class="lg-mygifts__actions">
                                <button type="button" class="lg-btn lg-btn--action lg-action-resend"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Resend</button>
                                <button type="button" class="lg-btn lg-btn--action lg-action-open-reassign"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Reassign</button>
                                <button type="button" class="lg-btn lg-btn--danger-sm lg-action-void"
                                    data-code-id="<?php echo (int) $r['id']; ?>">Void</button>
                                <form class="lg-inline-form lg-reassign-form" data-code-id="<?php echo (int) $r['id']; ?>" hidden>
                                    <input type="email" class="lg-field" name="recipient_email"
                                        placeholder="New recipient email *"
                                        value="<?php echo esc_attr( $rEmail ); ?>" required>
                                    <input type="text" class="lg-field" name="recipient_name"
                                        placeholder="New recipient name (optional)"
                                        value="<?php echo esc_attr( $rName ); ?>">
                                    <textarea class="lg-field lg-field--msg" name="message" rows="2"
                                        placeholder="Optional personal message…"><?php echo esc_textarea( (string) ( $r['gift_message'] ?? '' ) ); ?></textarea>
                                    <div class="lg-inline-form__btns">
                                        <button type="submit" class="lg-btn lg-btn--primary lg-btn--sm">Reassign &amp; email</button>
                                        <button type="button" class="lg-btn lg-btn--sm lg-form-cancel">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <?php if ( $redeemed !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--redeemed">
                <h3>Redeemed <span class="lg-mygifts__count">(<?php echo count( $redeemed ); ?>)</span></h3>
                <table class="lg-mygifts__table">
                    <thead><tr><th>Tier</th><th>Recipient</th><th>Redeemed by</th><th>When</th></tr></thead>
                    <tbody>
                    <?php foreach ( $redeemed as $r ) :
                        $rName  = (string) ( $r['recipient_name']  ?? '' );
                        $rEmail = (string) ( $r['recipient_email'] ?? '' );
                        $by     = (string) ( $r['redeemer_email']  ?? '' );
                    ?>
                        <tr>
                            <td><?php echo esc_html( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td><?php echo esc_html( $rName !== '' ? $rName : ( $rEmail !== '' ? $rEmail : '—' ) ); ?></td>
                            <td><?php echo esc_html( $by !== '' ? $by : '—' ); ?></td>
                            <td class="lg-mygifts__when"><?php echo esc_html( self::shortDate( $r['redeemed_at'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>

            <?php if ( $voided !== [] ) : ?>
            <section class="lg-mygifts__section lg-mygifts__section--voided">
                <h3>Voided <span class="lg-mygifts__count">(<?php echo count( $voided ); ?>)</span></h3>
                <table class="lg-mygifts__table">
                    <thead><tr><th>Tier</th><th>Code</th><th>Voided</th></tr></thead>
                    <tbody>
                    <?php foreach ( $voided as $r ) : ?>
                        <tr>
                            <td><?php echo esc_html( $tierLabel( (string) $r['tier'] ) ); ?></td>
                            <td><code class="lg-mygifts__code lg-mygifts__code--small"><?php echo esc_html( (string) $r['code'] ); ?></code></td>
                            <td class="lg-mygifts__when"><?php echo esc_html( self::shortDate( $r['voided_at'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
            <?php endif; ?>
        </div><!-- /lg-mygifts -->

        <style>
            .lg-mygifts { max-width: 920px; margin: 0 auto; padding: 1em 1.2em; }
            .lg-mygifts__hero { margin-bottom: 1.4em; }
            .lg-mygifts__heading { margin: 0 0 .25em; font-size: 1.6em; }
            .lg-mygifts__intro { margin: 0; color: rgba(0,0,0,0.6); display: flex; gap: 1em; align-items: baseline; flex-wrap: wrap; }
            .lg-mygifts__buy-more { color: var(--lg-sage, #87986A); text-decoration: none; }
            .lg-mygifts__buy-more:hover { text-decoration: underline; }

            .lg-mygifts__section { margin-top: 1.4em; padding: 1.1em 1.2em; border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; background: #fff; }
            .lg-mygifts__section--unsent { background: rgba(236,179,81,0.05); border-color: rgba(236,179,81,0.3); }
            .lg-mygifts__section--redeemed { opacity: .85; }
            .lg-mygifts__section--voided { opacity: .7; }
            .lg-mygifts__section-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5em; margin-bottom: .3em; }
            .lg-mygifts__section h3 { margin: 0; font-size: 1.05em; font-weight: 600; }
            .lg-mygifts__count { color: rgba(0,0,0,0.5); font-weight: 400; font-size: .9em; }
            .lg-mygifts__hint { margin: .3em 0 .9em; color: rgba(0,0,0,0.55); font-size: .9em; }
            .lg-mygifts__table { width: 100%; border-collapse: collapse; }
            .lg-mygifts__table th { text-align: left; padding: .5em .65em; font-size: .8em; text-transform: uppercase; letter-spacing: .04em; color: rgba(0,0,0,0.5); border-bottom: 1px solid rgba(0,0,0,0.08); font-weight: 600; }
            .lg-mygifts__table td { padding: .65em; border-bottom: 1px solid rgba(0,0,0,0.06); vertical-align: top; }
            .lg-mygifts__code { display: inline-block; padding: .25em .55em; background: rgba(0,0,0,0.04); border: 1px dashed rgba(0,0,0,0.15); border-radius: 4px; font: 14px ui-monospace, Menlo, Consolas, monospace; letter-spacing: .05em; }
            .lg-mygifts__code--small { font-size: 12px; padding: .15em .4em; }
            .lg-mygifts__when { color: rgba(0,0,0,0.55); font-size: .9em; white-space: nowrap; }
            .lg-mygifts__sub { color: rgba(0,0,0,0.55); font-size: .85em; }
            .lg-mygifts__actions { white-space: nowrap; }

            .lg-btn { display: inline-block; padding: .3em .75em; border-radius: 5px; border: 1px solid rgba(0,0,0,0.2); background: #fff; cursor: pointer; font-size: .85em; color: inherit; line-height: 1.4; transition: background .12s, border-color .12s; vertical-align: middle; font-family: inherit; }
            .lg-btn:hover:not(:disabled) { background: rgba(0,0,0,0.05); }
            .lg-btn--primary { background: var(--lg-amber, #ECB351); border-color: transparent; color: #1f1d1a; font-weight: 600; }
            .lg-btn--primary:hover:not(:disabled) { background: #d9a040; }
            .lg-btn--action { background: rgba(135,152,106,0.1); border-color: rgba(135,152,106,0.35); color: #4a5e2a; margin-right: .3em; }
            .lg-btn--action:hover:not(:disabled) { background: rgba(135,152,106,0.2); }
            .lg-btn--danger-sm { background: transparent; border-color: rgba(200,0,0,0.2); color: #a00; font-size: .78em; padding: .2em .5em; margin-left: .2em; }
            .lg-btn--danger-sm:hover:not(:disabled) { background: rgba(200,0,0,0.06); }
            .lg-btn--sm { font-size: .82em; padding: .25em .6em; }
            .lg-btn:disabled { opacity: .5; cursor: not-allowed; }

            .lg-inline-form { margin-top: .7em; padding: .75em; background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.1); border-radius: 6px; display: flex; flex-direction: column; gap: .45em; }
            .lg-field { width: 100%; padding: .4em .6em; border: 1px solid rgba(0,0,0,0.18); border-radius: 4px; font-size: .9em; color: inherit; background: #fff; box-sizing: border-box; font-family: inherit; }
            .lg-field--msg { resize: vertical; min-height: 56px; }
            .lg-inline-form__btns { display: flex; gap: .5em; }

            .lg-mygifts__toast { position: fixed; bottom: 1.5em; left: 50%; transform: translateX(-50%); background: #1f1d1a; color: #fff; padding: .65em 1.2em; border-radius: 6px; font-size: .9em; z-index: 9999; max-width: 420px; text-align: center; pointer-events: none; }
            .lg-mygifts__toast--error { background: #8b0000; }

            .lg-batch-controls { display: flex; gap: .5em; align-items: center; }
            .lg-batch-panel { margin: .75em 0; padding: 1em 1.1em; background: rgba(236,179,81,0.08); border: 1px solid rgba(236,179,81,0.4); border-radius: 8px; }
            .lg-batch-panel__intro { margin: 0 0 .7em; font-size: .9em; }
            .lg-batch-panel__shared { margin-top: .9em; font-size: .88em; }
            .lg-batch-panel__msg-area { width: 100%; margin-top: .3em; box-sizing: border-box; padding: .4em .6em; border: 1px solid rgba(0,0,0,0.18); border-radius: 4px; font-family: inherit; resize: vertical; }
            .lg-batch-panel__footer { display: flex; gap: .7em; align-items: center; margin-top: .9em; flex-wrap: wrap; }
            .lg-batch-panel__progress { font-size: .85em; color: rgba(0,0,0,0.6); }
            .lg-batch-row { margin-bottom: .7em; padding-bottom: .7em; border-bottom: 1px solid rgba(0,0,0,0.07); display: grid; grid-template-columns: 1fr 1fr; gap: .4em; }
            .lg-batch-row:last-child { border-bottom: none; }
            .lg-batch-row__label { grid-column: 1 / -1; font-size: .82em; font-weight: 600; color: rgba(0,0,0,0.55); }
            .lg-batch-row__msg { grid-column: 1 / -1; }

            .lg-col-check { width: 2em; text-align: center; }
            @media (max-width: 640px) {
                .lg-mygifts__table thead { display: none; }
                .lg-mygifts__table tr { display: block; padding: .65em 0; border-bottom: 1px solid rgba(0,0,0,0.08); }
                .lg-mygifts__table td { display: block; padding: .15em 0; border: none; }
                .lg-batch-row { grid-template-columns: 1fr; }
            }
        </style>

        <script>
        (function () {
            'use strict';
            var CFG = <?php echo $jsConfig; ?>;

            function apiCall(endpoint, payload) {
                return fetch(CFG.restBase + '/me/' + endpoint, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
                    body:    JSON.stringify(payload),
                }).then(function(r) {
                    return r.json().then(function(d) { d.__status = r.status; return d; });
                });
            }

            // For send + reassign: if the server flags the recipient as
            // already having an active sub or gift, prompt the buyer to
            // confirm. On confirm, retry with acknowledged_recipient_warning.
            function sendGiftWithRecipientCheck(endpoint, payload) {
                return apiCall(endpoint, payload).then(function(d) {
                    if (!d || !d.needs_recipient_confirmation) return d;
                    var w = d.recipient_warning || {};
                    var msg;
                    if (w.kind === 'subscription') {
                        msg = payload.recipient_email + ' already has an active Looth subscription. ' +
                              'Sending a gift to this address will sit unredeemed unless they cancel their sub first ' +
                              '(redemption blocks if a sub is active). Send anyway?';
                    } else if (w.kind === 'gift') {
                        var days = parseInt(w.days_remaining, 10) || 0;
                        msg = payload.recipient_email + ' already has ' + days + ' day' + (days === 1 ? '' : 's') +
                              ' of an active Looth gift. Stacking a second gift just queues the time after the current one expires. Send anyway?';
                    } else {
                        msg = payload.recipient_email + ' already has a Looth account. Send the gift anyway?';
                    }
                    if (!window.confirm(msg)) {
                        return { ok: false, error: 'Cancelled — gift not sent.', __cancelled: true };
                    }
                    payload.acknowledged_recipient_warning = true;
                    return apiCall(endpoint, payload);
                });
            }

            var toastTimer;
            function toast(msg, isError) {
                var el = document.getElementById('lg-gift-toast');
                if (!el) return;
                el.textContent = msg;
                el.className   = 'lg-mygifts__toast' + (isError ? ' lg-mygifts__toast--error' : '');
                el.hidden      = false;
                clearTimeout(toastTimer);
                toastTimer = setTimeout(function() { el.hidden = true; }, isError ? 5500 : 3500);
            }

            function hideAllForms(table) {
                if (table) table.querySelectorAll('.lg-inline-form').forEach(function(f) { f.hidden = true; });
            }

            function showForm(row, cls) {
                hideAllForms(row.closest('table'));
                var form = row.querySelector(cls);
                if (!form) return;
                form.hidden = false;
                var first = form.querySelector('input,textarea');
                if (first) first.focus();
            }

            function removeRow(row, delay) {
                setTimeout(function() {
                    row.style.transition = 'opacity .35s';
                    row.style.opacity = '0';
                    setTimeout(function() { row.remove(); }, 360);
                }, delay || 0);
            }

            /* ---- Send ---- */
            document.querySelectorAll('.lg-action-open-send').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    showForm(btn.closest('tr'), '.lg-send-form');
                    btn.hidden = true;
                });
            });

            document.querySelectorAll('.lg-send-form').forEach(function(form) {
                form.querySelector('.lg-form-cancel').addEventListener('click', function() {
                    form.hidden = true;
                    var openBtn = form.closest('tr').querySelector('.lg-action-open-send');
                    if (openBtn) openBtn.hidden = false;
                });

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var row    = form.closest('tr');
                    var codeId = parseInt(form.dataset.codeId, 10);
                    var sub    = form.querySelector('[type=submit]');
                    sub.disabled = true; sub.textContent = 'Sending…';

                    sendGiftWithRecipientCheck('gift-send', {
                        code_id:         codeId,
                        recipient_email: form.recipient_email.value.trim(),
                        recipient_name:  form.recipient_name.value.trim(),
                        message:         form.message.value.trim(),
                    }).then(function(d) {
                        if (d.ok) {
                            toast('Gift email sent!');
                            removeRow(row, 400);
                            setTimeout(function() { window.location.reload(); }, 1200);
                        } else {
                            if (!d.__cancelled) toast('Error: ' + (d.error || 'unknown error'), true);
                            sub.disabled = false; sub.textContent = 'Send gift';
                        }
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        sub.disabled = false; sub.textContent = 'Send gift';
                    });
                });
            });

            /* ---- Resend ---- */
            document.querySelectorAll('.lg-action-resend').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Re-send the gift email to the same recipient?')) return;
                    var codeId = parseInt(btn.dataset.codeId, 10);
                    btn.disabled = true; btn.textContent = 'Sending…';
                    apiCall('gift-resend', { code_id: codeId }).then(function(d) {
                        if (d.ok) {
                            toast('Gift email re-sent!');
                        } else {
                            toast('Error: ' + (d.error || 'unknown error'), true);
                        }
                        btn.disabled = false; btn.textContent = 'Resend';
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        btn.disabled = false; btn.textContent = 'Resend';
                    });
                });
            });

            /* ---- Reassign ---- */
            document.querySelectorAll('.lg-action-open-reassign').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    showForm(btn.closest('tr'), '.lg-reassign-form');
                });
            });

            document.querySelectorAll('.lg-reassign-form').forEach(function(form) {
                form.querySelector('.lg-form-cancel').addEventListener('click', function() {
                    form.hidden = true;
                });

                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var row    = form.closest('tr');
                    var codeId = parseInt(form.dataset.codeId, 10);
                    var sub    = form.querySelector('[type=submit]');
                    sub.disabled = true; sub.textContent = 'Reassigning…';

                    sendGiftWithRecipientCheck('gift-reassign', {
                        code_id:         codeId,
                        recipient_email: form.recipient_email.value.trim(),
                        recipient_name:  form.recipient_name.value.trim(),
                        message:         form.message.value.trim(),
                    }).then(function(d) {
                        if (d.ok) {
                            toast('Reassigned and emailed!');
                            setTimeout(function() { window.location.reload(); }, 900);
                        } else {
                            if (!d.__cancelled) toast('Error: ' + (d.error || 'unknown error'), true);
                            sub.disabled = false; sub.textContent = 'Reassign & email';
                        }
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        sub.disabled = false; sub.textContent = 'Reassign & email';
                    });
                });
            });

            /* ---- Void ---- */
            document.querySelectorAll('.lg-action-void').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    if (!confirm('Void this code? It will become permanently unusable.')) return;
                    var row    = btn.closest('tr');
                    var codeId = parseInt(btn.dataset.codeId, 10);
                    btn.disabled = true; btn.textContent = 'Voiding…';
                    apiCall('gift-void', { code_id: codeId }).then(function(d) {
                        if (d.ok) {
                            toast('Code voided.');
                            removeRow(row);
                        } else {
                            toast('Error: ' + (d.error || 'unknown error'), true);
                            btn.disabled = false; btn.textContent = 'Void';
                        }
                    }).catch(function() {
                        toast('Network error — please try again.', true);
                        btn.disabled = false; btn.textContent = 'Void';
                    });
                });
            });

            /* ---- Batch send ---- */
            var batchToggle  = document.getElementById('lg-batch-toggle');
            var batchSendBtn = document.getElementById('lg-batch-send-btn');
            var batchPanel   = document.getElementById('lg-batch-panel');
            var batchCountEl = document.getElementById('lg-batch-count');
            var batchRowsCt  = document.getElementById('lg-batch-rows');
            var batchMsg     = document.getElementById('lg-batch-msg');
            var batchApply   = document.getElementById('lg-batch-apply-msg');
            var batchFire    = document.getElementById('lg-batch-fire');
            var batchCancel  = document.getElementById('lg-batch-cancel');
            var batchProg    = document.getElementById('lg-batch-progress');
            var selAll       = document.getElementById('lg-select-all');
            var batchInMode  = false;

            function getChecked() { return Array.from(document.querySelectorAll('.lg-row-check:checked')); }
            function updateBatchCount() {
                var n = getChecked().length;
                if (batchCountEl) batchCountEl.textContent = n;
                if (batchSendBtn) batchSendBtn.hidden = (n === 0);
            }

            if (batchToggle) {
                batchToggle.addEventListener('click', function() {
                    batchInMode = !batchInMode;
                    batchToggle.textContent = batchInMode ? 'Cancel selection' : 'Select for batch send';
                    document.querySelectorAll('.lg-col-check').forEach(function(el) { el.hidden = !batchInMode; });
                    if (!batchInMode) {
                        document.querySelectorAll('.lg-row-check').forEach(function(cb) { cb.checked = false; });
                        if (batchSendBtn) batchSendBtn.hidden = true;
                        if (batchPanel)   batchPanel.hidden   = true;
                        if (selAll)       selAll.checked      = false;
                    }
                });
            }

            document.querySelectorAll('.lg-row-check').forEach(function(cb) {
                cb.addEventListener('change', updateBatchCount);
            });

            if (selAll) {
                selAll.addEventListener('change', function() {
                    document.querySelectorAll('.lg-row-check').forEach(function(cb) { cb.checked = selAll.checked; });
                    updateBatchCount();
                });
            }

            if (batchSendBtn) {
                batchSendBtn.addEventListener('click', function() {
                    var checked = getChecked();
                    if (checked.length === 0 || !batchPanel || !batchRowsCt) return;
                    batchPanel.hidden = false;
                    batchRowsCt.innerHTML = '';
                    checked.forEach(function(cb) {
                        var div = document.createElement('div');
                        div.className = 'lg-batch-row';
                        div.dataset.codeId = cb.dataset.codeId;
                        div.innerHTML =
                            '<div class="lg-batch-row__label">' + (cb.dataset.tierLabel || 'Code') + ' #' + cb.dataset.codeId + '</div>' +
                            '<input type="email" class="lg-field lg-batch-email" placeholder="Recipient email *" required>' +
                            '<input type="text"  class="lg-field lg-batch-name"  placeholder="Recipient name (optional)">' +
                            '<textarea class="lg-field lg-batch-row__msg lg-batch-msg-row" rows="1" placeholder="Message (optional)…"></textarea>';
                        batchRowsCt.appendChild(div);
                    });
                });
            }

            if (batchApply) {
                batchApply.addEventListener('click', function() {
                    var msg = batchMsg ? batchMsg.value : '';
                    document.querySelectorAll('.lg-batch-msg-row').forEach(function(ta) { ta.value = msg; });
                });
            }

            if (batchCancel) {
                batchCancel.addEventListener('click', function() { if (batchPanel) batchPanel.hidden = true; });
            }

            if (batchFire) {
                batchFire.addEventListener('click', function() {
                    var rows = batchRowsCt ? Array.from(batchRowsCt.querySelectorAll('.lg-batch-row')) : [];
                    if (rows.length === 0) return;

                    var missing = rows.filter(function(r) {
                        var e = r.querySelector('.lg-batch-email');
                        return !e || e.value.trim() === '';
                    });
                    if (missing.length > 0) { toast('Please fill in all recipient emails.', true); return; }

                    batchFire.disabled = true;
                    if (batchCancel) batchCancel.disabled = true;
                    if (batchProg) { batchProg.hidden = false; batchProg.textContent = '0 / ' + rows.length + ' sent'; }

                    var done = 0, errors = 0;

                    function fireNext(i) {
                        if (i >= rows.length) {
                            var msg = 'Sent ' + (done - errors) + ' of ' + rows.length + '.';
                            if (errors) msg += ' ' + errors + ' failed — check your data and retry those individually.';
                            toast(msg, errors > 0);
                            setTimeout(function() { window.location.reload(); }, 1400);
                            return;
                        }
                        var rowEl  = rows[i];
                        var codeId = parseInt(rowEl.dataset.codeId, 10);
                        var email  = rowEl.querySelector('.lg-batch-email').value.trim();
                        var name   = rowEl.querySelector('.lg-batch-name').value.trim();
                        var msg    = rowEl.querySelector('.lg-batch-msg-row').value.trim();

                        apiCall('gift-send', { code_id: codeId, recipient_email: email, recipient_name: name, message: msg, acknowledged_recipient_warning: true })
                            .then(function(d) {
                                done++;
                                if (!d.ok) errors++;
                                if (batchProg) batchProg.textContent = done + ' / ' + rows.length + ' sent';
                                fireNext(i + 1);
                            })
                            .catch(function() {
                                done++; errors++;
                                if (batchProg) batchProg.textContent = done + ' / ' + rows.length + ' sent';
                                fireNext(i + 1);
                            });
                    }
                    fireNext(0);
                });
            }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }



    /**
     * [lg_member_nav] - membership-pages navigation bar.
     * Auto-discovers WP pages containing each membership shortcode and links to them.
     * Hides items whose page does not exist. Highlights the current page.
     */
    public static function memberNav( $atts = [] ): string
    {
        global $wpdb, $post;

        // Pages::navItems() returns the registry-filtered set for the current
        // user's login state — Join hidden from members, Manage hidden from
        // guests, transient pages (welcome / regional fail) excluded entirely.
        $items = [];
        foreach ( Pages::navItems() as $tag => $info ) {
            $items[] = [
                'label' => (string) ( $info['nav_label'] ?? $info['title'] ?? $tag ),
                'tag'   => $tag,
            ];
        }

        $currentId = isset( $post ) && $post ? (int) $post->ID : 0;
        $links     = [];

        foreach ( $items as $item ) {
            $needle = '[' . $item['tag'];
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'page' AND post_status = 'publish'
                   AND post_content LIKE %s
                 ORDER BY ID ASC LIMIT 1",
                '%' . $wpdb->esc_like( $needle ) . '%'
            );
            $pageId = (int) $wpdb->get_var( $sql );
            if ( $pageId <= 0 ) {
                continue;
            }
            $url    = get_permalink( $pageId );
            $isHere = ( $pageId === $currentId );
            $class  = 'lg-member-nav__link' . ( $isHere ? ' is-current' : '' );
            $links[] = sprintf(
                '<a class="%s" href="%s"%s>%s</a>',
                esc_attr( $class ),
                esc_url( $url ),
                $isHere ? ' aria-current="page"' : '',
                esc_html( $item['label'] )
            );
        }

        if ( $links === [] ) {
            return '';
        }

        $css = '
            .lg-member-nav { margin: 0 0 1.5em; padding: 0.5em 0; border-bottom: 1px solid rgba(0,0,0,.08); display: flex; flex-wrap: wrap; gap: 0.25em 1.25em; align-items: center; justify-content: center; }
            .lg-member-nav__link { display: inline-block; padding: 0.35em 0; color: inherit; text-decoration: none; font-size: 0.95em; opacity: 0.7; border-bottom: 2px solid transparent; transition: opacity .15s, border-color .15s; }
            .lg-member-nav__link:hover { opacity: 1; }
            .lg-member-nav__link.is-current { opacity: 1; font-weight: 600; border-bottom-color: currentColor; }
        ';
        $css = preg_replace( '/\s+/', ' ', $css );

        return '<style>' . $css . '</style><nav class="lg-member-nav" aria-label="Membership">' . implode( '', $links ) . '</nav>';
    }
}
