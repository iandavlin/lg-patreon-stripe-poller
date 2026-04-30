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
        add_shortcode( 'lg_gift',                [ self::class, 'gift'               ] );
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
        ], (array) $atts, 'lg_gift' );

        $user       = wp_get_current_user();
        $isLoggedIn = $user->ID > 0;
        $emailValue = $isLoggedIn ? (string) $user->user_email : '';
        $nameValue  = $isLoggedIn ? trim( (string) ( $user->display_name ?: $user->user_login ) ) : '';

        $base      = rtrim( (string) home_url( '/billing' ), '/' );
        $endpoints = [
            'products' => esc_url_raw( $base . '/v1/products' ),
            'config'   => esc_url_raw( $base . '/v1/config' ),
            'checkout' => esc_url_raw( $base . '/v1/checkout' ),
        ];

        $heading     = esc_html( (string) $atts['heading'] );
        $email       = esc_attr( $emailValue );
        $name        = esc_attr( $nameValue );
        $endpointsJs = wp_json_encode( $endpoints );

        ob_start();
        ?>
        <div class="lg-gift">
            <h2 class="lg-gift__heading"><?php echo $heading; ?></h2>
            <p class="lg-gift__intro">
                Buy gift codes to share with anyone. Each code grants a 1-year membership
                when redeemed. Bulk discounts kick in at 10, 20, and 50 seats.
            </p>

            <div class="lg-gift__form">
                <fieldset class="lg-gift__tiers" data-lg-gift-tiers>
                    <legend>Tier</legend>
                    <p class="lg-gift__loading">Loading tiers…</p>
                </fieldset>

                <div class="lg-gift__field">
                    <label>Quantity (seats)
                        <input type="number" name="quantity" value="1" min="1" step="1" required style="width:120px;">
                    </label>
                </div>

                <div class="lg-gift__field">
                    <label>Your email
                        <input type="email" name="email" value="<?php echo $email; ?>" required>
                    </label>
                    <small style="opacity:.7;">Codes will be sent here.</small>
                </div>
            </div>

            <div class="lg-gift__summary" data-lg-gift-summary
                style="margin:16px 0;padding:12px 16px;border:1px solid rgba(0,0,0,0.15);border-radius:8px;max-width:480px;font-family:monospace;">
                <div class="lg-gift__summary-line" data-lg-gift-line-sub>—</div>
                <div class="lg-gift__summary-line" data-lg-gift-line-disc>—</div>
                <hr style="border:none;border-top:1px solid rgba(0,0,0,0.1);margin:8px 0;">
                <div class="lg-gift__summary-line" data-lg-gift-line-total style="font-weight:bold;">—</div>
            </div>

            <p class="lg-gift__no-expiry" style="opacity:.7;font-size:0.9em;">
                ⓘ Codes never expire — your recipient(s) can redeem whenever they're ready.
            </p>

            <button type="button" class="lg-gift__submit" data-lg-gift-submit
                style="padding:10px 20px;cursor:pointer;">
                Continue to checkout
            </button>

            <div class="lg-gift__error" data-lg-gift-error style="color:#b00;margin-top:12px;" aria-live="polite"></div>

            <div class="lg-gift__checkout" data-lg-gift-checkout style="margin-top:24px;"></div>
        </div>

        <script src="https://js.stripe.com/v3/"></script>
        <script>
        (function(){
            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const tiersEl    = document.querySelector('[data-lg-gift-tiers]');
            const summarySub  = document.querySelector('[data-lg-gift-line-sub]');
            const summaryDisc = document.querySelector('[data-lg-gift-line-disc]');
            const summaryTot  = document.querySelector('[data-lg-gift-line-total]');
            const submitBtn   = document.querySelector('[data-lg-gift-submit]');
            const errorEl     = document.querySelector('[data-lg-gift-error]');
            const checkoutEl  = document.querySelector('[data-lg-gift-checkout]');
            const qtyInput    = document.querySelector('input[name="quantity"]');
            const emailInput  = document.querySelector('input[name="email"]');

            let products       = [];
            let bulkTiers      = [];
            let stripe         = null;
            let mountedSession = null;

            function dollars(cents){
                return '$' + (cents / 100).toFixed(2);
            }

            function showError(msg){ errorEl.textContent = msg || ''; }

            function selectedTier(){
                const radio = tiersEl.querySelector('input[name="tier"]:checked');
                if (!radio) return null;
                const productId = radio.value;
                return products.find(p => p.stripe_product_id === productId) || null;
            }

            function pickAnnualPrice(prod){
                if (!prod) return null;
                return prod.prices.find(p => p.type === 'recurring' && p.interval === 'year') || null;
            }

            function discountPctFor(qty){
                let pct = 0;
                bulkTiers.forEach(t => { if (qty >= t.min_qty && t.discount_pct > pct) pct = t.discount_pct; });
                return pct;
            }

            function nextTierHint(qty){
                const upcoming = bulkTiers.filter(t => qty < t.min_qty).sort((a,b) => a.min_qty - b.min_qty)[0];
                if (!upcoming) return '';
                const need = upcoming.min_qty - qty;
                return ' (need ' + need + ' more for ' + upcoming.discount_pct + '% off)';
            }

            function recompute(){
                const tier  = selectedTier();
                const price = pickAnnualPrice(tier);
                const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                if (!price) {
                    summarySub.textContent  = '—';
                    summaryDisc.textContent = '—';
                    summaryTot.textContent  = '—';
                    return;
                }
                const subCents   = price.unit_amount_cents * qty;
                const pct        = discountPctFor(qty);
                const discCents  = Math.round(subCents * pct / 100);
                const totalCents = subCents - discCents;

                summarySub.textContent  = qty + ' × ' + dollars(price.unit_amount_cents) + ' = ' + dollars(subCents);
                summaryDisc.textContent = 'Bulk discount: ' + (pct > 0 ? '-' + dollars(discCents) + ' (' + pct + '% off)' : '$0.00' + nextTierHint(qty));
                summaryTot.textContent  = 'Total: ' + dollars(totalCents);
            }

            async function loadProducts(){
                showError('');
                try {
                    const res  = await fetch(ENDPOINTS.products);
                    const json = await res.json();
                    products  = json.products || [];
                    bulkTiers = json.bulk_discount_tiers || [];
                    renderTiers();
                    recompute();
                } catch (err) {
                    showError('Failed to load tiers: ' + err.message);
                }
            }

            function renderTiers(){
                tiersEl.innerHTML = '';
                const legend = document.createElement('legend');
                legend.textContent = 'Tier';
                tiersEl.appendChild(legend);

                products.forEach(function(prod, idx){
                    const price = pickAnnualPrice(prod);
                    if (!price) return;

                    const id = 'lg-gift-tier-' + prod.stripe_product_id;
                    const wrap = document.createElement('label');
                    wrap.className = 'lg-gift__tier';
                    wrap.style.display = 'block';
                    wrap.style.margin  = '6px 0';

                    const radio = document.createElement('input');
                    radio.type    = 'radio';
                    radio.name    = 'tier';
                    radio.value   = prod.stripe_product_id;
                    radio.id      = id;
                    radio.checked = idx === 0;
                    radio.addEventListener('change', recompute);

                    wrap.appendChild(radio);
                    wrap.appendChild(document.createTextNode(' ' + prod.name + ' — ' + dollars(price.unit_amount_cents) + '/year'));
                    tiersEl.appendChild(wrap);
                });
            }

            qtyInput.addEventListener('input', recompute);

            submitBtn.addEventListener('click', async function(){
                showError('');
                const tier  = selectedTier();
                const price = pickAnnualPrice(tier);
                if (!price) { showError('Please pick a tier.'); return; }

                const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }

                if (mountedSession) {
                    try { mountedSession.destroy(); } catch (e) {}
                    mountedSession = null;
                }
                checkoutEl.innerHTML = '';
                submitBtn.disabled    = true;
                const orig = submitBtn.textContent;
                submitBtn.textContent = 'Loading…';

                try {
                    const sessRes = await fetch(ENDPOINTS.checkout, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            price_id: price.stripe_price_id,
                            quantity: qty,
                            email:    email,
                            gift:     true,
                        }),
                    });
                    const sessData = await sessRes.json();
                    if (!sessData.clientSecret) {
                        showError(sessData.error || 'Could not start checkout.');
                        return;
                    }

                    if (!stripe) {
                        const cfg = await (await fetch(ENDPOINTS.config)).json();
                        if (!cfg.publishableKey) { showError('Stripe not configured.'); return; }
                        stripe = Stripe(cfg.publishableKey);
                    }

                    mountedSession = await stripe.initEmbeddedCheckout({ clientSecret: sessData.clientSecret });
                    mountedSession.mount(checkoutEl);
                    checkoutEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (err) {
                    showError('Network error: ' + err.message);
                } finally {
                    submitBtn.disabled    = false;
                    submitBtn.textContent = orig;
                }
            });

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
        $atts = shortcode_atts( [
            'label' => 'Manage your subscription',
        ], (array) $atts, 'lg_manage_subscription' );

        $user = wp_get_current_user();
        if ( $user->ID === 0 ) {
            return '';
        }
        $email = (string) $user->user_email;
        if ( $email === '' ) {
            return '';
        }

        $endpoint = esc_url_raw( rtrim( (string) home_url( '/billing' ), '/' ) . '/v1/portal' );
        $label    = esc_html( (string) $atts['label'] );
        $emailEsc = esc_attr( $email );

        ob_start();
        ?>
        <div class="lg-manage-sub">
            <button type="button" class="lg-manage-sub__btn" data-lg-manage-sub data-email="<?php echo $emailEsc; ?>"><?php echo $label; ?></button>
            <span class="lg-manage-sub__error" data-lg-manage-sub-error style="color:#b00;margin-left:12px;"></span>
        </div>
        <script>
        (function(){
            const btn = document.querySelector('[data-lg-manage-sub]');
            const err = document.querySelector('[data-lg-manage-sub-error]');
            if (!btn) return;
            btn.addEventListener('click', async function(){
                err.textContent = '';
                btn.disabled = true;
                const orig = btn.textContent;
                btn.textContent = 'Loading…';
                try {
                    const res = await fetch('<?php echo esc_js( $endpoint ); ?>', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ email: btn.dataset.email }),
                    });
                    const data = await res.json();
                    if (data.url) {
                        window.location.href = data.url;
                        return;
                    }
                    err.textContent = data.error || 'Could not open portal.';
                } catch (e) {
                    err.textContent = 'Network error: ' + e.message;
                } finally {
                    btn.disabled = false;
                    btn.textContent = orig;
                }
            });
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
            'heading' => 'Choose your membership',
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
                    <label>Name <input type="text" name="name" value="<?php echo $name; ?>" required></label>
                    <small style="opacity:.7;">Used for your account / community profile.</small>
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

        // Pre-fill the code from ?code=... in the URL (links from gift email).
        $codeFromUrl = isset( $_GET['code'] ) ? (string) $_GET['code'] : '';
        $codeFromUrl = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $codeFromUrl ) );
        if ( strlen( $codeFromUrl ) > 12 ) {
            $codeFromUrl = substr( $codeFromUrl, 0, 12 );
        }

        $heading  = esc_html( (string) $atts['heading'] );
        $email    = esc_attr( $emailValue );
        $name     = esc_attr( $nameValue );
        $codeAttr = esc_attr( $codeFromUrl );
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
                        value="<?php echo $codeAttr; ?>"
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

    private static function renderActiveSubBlock( array $sub ): string
    {
        $tier   = esc_html( (string) ( $sub['tier'] ?? 'membership' ) );
        $price  = esc_html( $sub['price_label'] );
        $status = esc_html( $sub['status'] );
        $end    = $sub['current_period_end'] !== null ? esc_html( substr( $sub['current_period_end'], 0, 10 ) ) : '';

        ob_start();
        ?>
        <div class="lg-join lg-join--existing-sub" style="border:1px solid rgba(0,0,0,0.15);border-radius:8px;padding:20px;max-width:560px;">
            <h3 style="margin-top:0;">You're already a member</h3>
            <p>Active <strong><?php echo $tier; ?></strong> subscription &middot; <?php echo $price; ?> &middot; status <?php echo $status; ?><?php echo $end !== '' ? ' &middot; renews ' . $end : ''; ?></p>
            <p style="margin-bottom:0;">
                <?php echo do_shortcode( '[lg_manage_subscription label="Manage your subscription"]' ); ?>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
