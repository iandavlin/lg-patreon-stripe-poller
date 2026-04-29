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
        add_shortcode( 'lg_join',        [ self::class, 'join'       ] );
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
            'heading' => 'Choose your membership',
        ], (array) $atts, 'lg_join' );

        $user        = wp_get_current_user();
        $isLoggedIn  = $user->ID > 0;
        $emailValue  = $isLoggedIn ? (string) $user->user_email : '';
        $nameValue   = $isLoggedIn ? trim( (string) ( $user->display_name ?: $user->user_login ) ) : '';

        $base       = rtrim( (string) home_url( '/billing' ), '/' );
        $endpoints  = [
            'products' => esc_url_raw( $base . '/v1/products' ),
            'config'   => esc_url_raw( $base . '/v1/config' ),
            'checkout' => esc_url_raw( $base . '/v1/checkout' ),
        ];

        $promoFromUrl = isset( $_GET['promo'] ) ? (string) $_GET['promo'] : '';
        $promoFromUrl = preg_replace( '/[^A-Za-z0-9_\-]/', '', $promoFromUrl );

        $heading      = esc_html( (string) $atts['heading'] );
        $email        = esc_attr( $emailValue );
        $name         = esc_attr( $nameValue );
        $promoEsc     = esc_attr( (string) $promoFromUrl );
        $endpointsJs  = wp_json_encode( $endpoints );

        ob_start();
        ?>
        <div class="lg-join">
            <h2 class="lg-join__heading"><?php echo $heading; ?></h2>

            <div class="lg-join__form">
                <div class="lg-join__field">
                    <label>Email <input type="email" name="email" value="<?php echo $email; ?>" required></label>
                </div>
                <div class="lg-join__field">
                    <label>Name <em style="opacity:.6;">(optional)</em>
                        <input type="text" name="name" value="<?php echo $name; ?>">
                    </label>
                </div>
                <?php if ( $promoFromUrl !== '' ) : ?>
                    <div class="lg-join__promo">
                        Promo code <strong><?php echo esc_html( $promoFromUrl ); ?></strong> will be applied at checkout.
                    </div>
                    <input type="hidden" name="promo_code" value="<?php echo $promoEsc; ?>">
                <?php endif; ?>
            </div>

            <div class="lg-join__tiers" data-lg-join-tiers>
                <p class="lg-join__loading">Loading tiers…</p>
            </div>

            <div class="lg-join__checkout" data-lg-join-checkout style="margin-top:24px;"></div>

            <div class="lg-join__error" data-lg-join-error aria-live="polite" style="color:#b00;"></div>
        </div>

        <script src="https://js.stripe.com/v3/"></script>
        <script>
        (function(){
            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const PROMO     = <?php echo wp_json_encode( $promoFromUrl ); ?>;

            const tiersEl    = document.querySelector('[data-lg-join-tiers]');
            const checkoutEl = document.querySelector('[data-lg-join-checkout]');
            const errorEl    = document.querySelector('[data-lg-join-error]');
            const emailInput = document.querySelector('input[name="email"]');
            const nameInput  = document.querySelector('input[name="name"]');

            let stripe        = null;
            let mountedSession = null;

            function showError(msg){ errorEl.textContent = msg || ''; }

            function dollars(cents){
                return '$' + (cents / 100).toFixed(cents % 100 === 0 ? 0 : 2);
            }

            function priceLabel(price){
                if (price.type === 'recurring' && price.interval === 'month') {
                    return 'Subscribe — ' + dollars(price.unit_amount_cents) + '/month';
                }
                if (price.type === 'recurring' && price.interval === 'year') {
                    return 'Subscribe — ' + dollars(price.unit_amount_cents) + '/year';
                }
                if (price.type === 'one_time') {
                    return 'Pay once — ' + dollars(price.unit_amount_cents) + ' / year';
                }
                return dollars(price.unit_amount_cents);
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
                    const res  = await fetch(ENDPOINTS.products);
                    const json = await res.json();
                    if (!json.products || json.products.length === 0) {
                        tiersEl.innerHTML = '<p>No memberships available right now.</p>';
                        return;
                    }
                    renderTiers(json.products);
                } catch (err) {
                    showError('Failed to load memberships: ' + err.message);
                }
            }

            function renderTiers(products){
                tiersEl.innerHTML = '';
                products.forEach(function(prod){
                    const card = document.createElement('div');
                    card.className = 'lg-join__tier';
                    card.style.border = '1px solid rgba(0,0,0,0.15)';
                    card.style.borderRadius = '8px';
                    card.style.padding = '16px';
                    card.style.margin = '12px 0';
                    card.style.maxWidth = '480px';

                    const title = document.createElement('h3');
                    title.textContent = prod.name;
                    title.style.marginTop = '0';
                    card.appendChild(title);

                    const list = document.createElement('div');
                    list.className = 'lg-join__prices';
                    sortPrices(prod.prices).forEach(function(price){
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'lg-join__buy';
                        btn.textContent = priceLabel(price);
                        btn.style.display = 'block';
                        btn.style.margin = '6px 0';
                        btn.style.padding = '10px 16px';
                        btn.style.minWidth = '260px';
                        btn.style.cursor = 'pointer';
                        btn.addEventListener('click', () => startCheckout(price.stripe_price_id, btn));
                        list.appendChild(btn);
                    });
                    card.appendChild(list);
                    tiersEl.appendChild(card);
                });
            }

            async function startCheckout(priceId, btn){
                showError('');
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }

                if (mountedSession) {
                    try { mountedSession.destroy(); } catch (e) {}
                    mountedSession = null;
                }
                checkoutEl.innerHTML = '';
                btn.disabled = true;
                btn.dataset.origText = btn.textContent;
                btn.textContent = 'Loading…';

                try {
                    const body = {
                        price_id: priceId,
                        email:    email,
                        name:     (nameInput.value || '').trim(),
                    };
                    if (PROMO) body.promo_code = PROMO;

                    const sessRes  = await fetch(ENDPOINTS.checkout, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(body),
                    });
                    const sessData = await sessRes.json();
                    if (!sessData.clientSecret) {
                        showError(sessData.error || 'Could not start checkout.');
                        return;
                    }

                    if (!stripe) {
                        const cfg = await (await fetch(ENDPOINTS.config)).json();
                        if (!cfg.publishableKey) {
                            showError('Stripe not configured.');
                            return;
                        }
                        stripe = Stripe(cfg.publishableKey);
                    }

                    mountedSession = await stripe.initEmbeddedCheckout({ clientSecret: sessData.clientSecret });
                    mountedSession.mount(checkoutEl);
                    checkoutEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (err) {
                    showError('Network error: ' + err.message);
                } finally {
                    btn.disabled    = false;
                    btn.textContent = btn.dataset.origText || btn.textContent;
                }
            }

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

            function renderSuccess(json){
                resultEl.className   = 'lg-redeem-gift__result is-success';
                resultEl.textContent = json.message + ' (expires ' + json.expires_at + ')';
                form.reset();
                pending = null;
            }

            function renderError(msg){
                resultEl.className   = 'lg-redeem-gift__result is-error';
                resultEl.textContent = msg;
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
                resultEl.textContent = 'Redeeming…';
                resultEl.className   = 'lg-redeem-gift__result is-pending';
                submitBt.disabled    = true;

                const payload = {
                    code:  (form.code.value  || '').trim().toUpperCase(),
                    email: (form.email.value || '').trim(),
                    name:  (form.name.value  || '').trim(),
                };

                try {
                    const json = await postRedeem(payload);

                    if (json.ok && json.requires_choice) {
                        json._payload = payload;
                        renderChoice(json);
                    } else if (json.ok) {
                        renderSuccess(json);
                    } else {
                        renderError(json.error || 'Unable to redeem code.');
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
}
