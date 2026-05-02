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
        add_shortcode( 'lg_refund_request',      [ self::class, 'refundRequest'      ] );
        add_shortcode( 'lg_regional_fail',       [ self::class, 'regionalFail'       ] );
        add_shortcode( 'lg_subscription_success',[ self::class, 'subscriptionSuccess'] );
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
            'products' => esc_url_raw( $base . '/v1/products' ),
            'config'   => esc_url_raw( $base . '/v1/config' ),
            'checkout' => esc_url_raw( $base . '/v1/checkout' ),
        ];

        $heading     = esc_html( (string) $atts['heading'] );
        $popularRef  = (string) $atts['popular'];
        $email       = esc_attr( $emailValue );
        $name        = esc_attr( $nameValue );
        $endpointsJs = wp_json_encode( $endpoints );
        $configJs    = wp_json_encode( [ 'popular' => $popularRef ] );

        ob_start();
        ?>
        <div class="lg-gift">
            <header class="lg-gift__hero">
                <h2 class="lg-gift__heading"><?php echo $heading; ?></h2>
                <p class="lg-gift__intro">
                    Buy gift codes to share with anyone. Each code grants a <strong>1-year membership</strong> when redeemed. Codes never expire.
                </p>
                <ul class="lg-gift__perks">
                    <li>✓ Full members-only forums + archive</li>
                    <li>✓ Sponsor benefits and member events</li>
                    <li>✓ Shareable to anyone — they redeem at their convenience</li>
                </ul>
            </header>

            <div class="lg-gift__panel">
                <h3 class="lg-gift__panel-heading">1. Pick a tier</h3>
                <div class="lg-gift__tiers" data-lg-gift-tiers>
                    <p class="lg-gift__loading">Loading tiers…</p>
                </div>

                <h3 class="lg-gift__panel-heading">2. How many codes?</h3>
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

                <h3 class="lg-gift__panel-heading">3. Where should we email the codes?</h3>
                <div class="lg-gift__field">
                    <input type="email" name="email" value="<?php echo $email; ?>" required placeholder="you@example.com">
                    <small>We send all codes to this address. You forward / share them yourself.</small>
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
            <div class="lg-gift__checkout" data-lg-gift-checkout></div>
        </div>

        <style>
            .lg-gift { max-width: 720px; margin: 0 auto; padding: 1.5em 1.2em; box-sizing: border-box; }
            .lg-gift * { box-sizing: border-box; }
            .lg-gift__hero { text-align: center; margin-bottom: 1.6em; }
            .lg-gift__heading { margin: 0 0 .3em; font-size: 1.8em; }
            .lg-gift__intro { margin: 0 0 .9em; opacity: .85; }
            .lg-gift__perks { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: .4em 1.2em; justify-content: center; font-size: 0.95em; opacity: .8; }
            .lg-gift__perks li { white-space: nowrap; }

            .lg-gift__panel { border: 1px solid rgba(0,0,0,0.1); border-radius: 10px; padding: 1.2em 1.4em; background: rgba(255,255,255,0.4); }
            .lg-gift__panel-heading { margin: 1em 0 .55em; font-size: 1.05em; font-weight: 600; }
            .lg-gift__panel-heading:first-child { margin-top: 0; }

            .lg-gift__tiers { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            @media (max-width: 480px) { .lg-gift__tiers { grid-template-columns: 1fr; } }
            .lg-gift__tier { position: relative; cursor: pointer; padding: 1em 1.1em; border: 2px solid rgba(0,0,0,0.1); border-radius: 8px; background: #fff; transition: border-color .15s, box-shadow .15s; }
            .lg-gift__tier:hover { border-color: rgba(0,0,0,0.3); }
            .lg-gift__tier.is-selected { border-color: var(--lg-amber, #ECB351); box-shadow: 0 0 0 3px rgba(236,179,81,0.18); }
            .lg-gift__tier input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
            .lg-gift__tier-name { font-weight: 600; font-size: 1.05em; margin: 0 0 .15em; }
            .lg-gift__tier-price { color: var(--lg-sage, #87986A); font-weight: 600; }
            .lg-gift__tier-tag { font-size: .85em; opacity: .7; margin-top: .35em; }
            .lg-gift__tier-popular { position: absolute; top: -10px; right: 12px; background: var(--lg-amber, #ECB351); color: #1f1d1a; padding: 2px 9px; border-radius: 10px; font-size: .75em; font-weight: 600; letter-spacing: .03em; }

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
        </style>

        <script src="https://js.stripe.com/v3/"></script>
        <script>
        (function(){
            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const CONFIG    = <?php echo $configJs; ?>;
            const tiersEl     = document.querySelector('[data-lg-gift-tiers]');
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
            const qtyInput    = document.querySelector('input[name="quantity"]');
            const emailInput  = document.querySelector('input[name="email"]');

            let products       = [];
            let bulkTiers      = [];
            let selectedRef    = null;
            let stripe         = null;
            let mountedSession = null;

            const dollars = (cents) => '$' + (cents / 100).toFixed(2);
            const dollarsRound = (cents) => '$' + Math.round(cents / 100);
            function showError(msg){ errorEl.textContent = msg || ''; }

            function selectedTier(){
                return products.find(p => p.ref === selectedRef) || null;
            }
            const pickAnnualPrice = (prod) => prod ? (prod.prices.find(p => p.type === 'recurring' && p.interval === 'year') || null) : null;

            function discountPctFor(qty){
                let pct = 0;
                bulkTiers.forEach(t => { if (qty >= t.min_qty && t.discount_pct > pct) pct = t.discount_pct; });
                return pct;
            }
            function nextTier(qty){
                return bulkTiers.filter(t => qty < t.min_qty).sort((a,b) => a.min_qty - b.min_qty)[0] || null;
            }

            function renderTiers(){
                tiersEl.innerHTML = '';
                products.forEach(function(prod, idx){
                    const price = pickAnnualPrice(prod);
                    if (!price) return;
                    const isPopular = (CONFIG.popular && prod.ref === CONFIG.popular);
                    const card = document.createElement('label');
                    card.className = 'lg-gift__tier' + (isPopular ? ' is-popular' : '');
                    card.dataset.ref = prod.ref;
                    if (isPopular) {
                        const tag = document.createElement('span');
                        tag.className = 'lg-gift__tier-popular';
                        tag.textContent = 'Most popular';
                        card.appendChild(tag);
                    }
                    const radio = document.createElement('input');
                    radio.type  = 'radio';
                    radio.name  = 'tier';
                    radio.value = prod.ref;
                    radio.checked = (idx === 0);
                    radio.addEventListener('change', () => selectTier(prod.ref));
                    card.appendChild(radio);
                    const name = document.createElement('div');
                    name.className = 'lg-gift__tier-name';
                    name.textContent = prod.name;
                    card.appendChild(name);
                    const priceEl = document.createElement('div');
                    priceEl.className = 'lg-gift__tier-price';
                    priceEl.textContent = dollars(price.unit_amount_cents) + ' / code';
                    card.appendChild(priceEl);
                    const tag = document.createElement('div');
                    tag.className = 'lg-gift__tier-tag';
                    tag.textContent = '1-year membership when redeemed';
                    card.appendChild(tag);
                    card.addEventListener('click', () => selectTier(prod.ref));
                    tiersEl.appendChild(card);
                });
                // default selection: first tier (or popular if it exists in list)
                const defaultProd = products.find(p => p.ref === CONFIG.popular) || products[0];
                if (defaultProd) selectTier(defaultProd.ref);
            }

            function selectTier(ref){
                selectedRef = ref;
                tiersEl.querySelectorAll('.lg-gift__tier').forEach(el => {
                    const isMe = el.dataset.ref === ref;
                    el.classList.toggle('is-selected', isMe);
                    const r = el.querySelector('input[type="radio"]');
                    if (r) r.checked = isMe;
                });
                recompute();
            }

            function renderPresets(){
                presetsEl.innerHTML = '';
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
                    const tier = bulkTiers.find(t => t.min_qty === qty);
                    btn.innerHTML = qty + (tier ? ' <span class="lg-gift__preset-disc">' + tier.discount_pct + '% off</span>' : '');
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
                const next = nextTier(qty);
                if (!next || qty < 1) { progressEl.hidden = true; return; }
                progressEl.hidden = false;
                // Find the previous milestone (last tier we've passed, or 1).
                const passed = bulkTiers.filter(t => qty >= t.min_qty).sort((a,b) => b.min_qty - a.min_qty)[0];
                const start  = passed ? passed.min_qty : 1;
                const span   = next.min_qty - start;
                const pos    = qty - start;
                const pct    = Math.max(0, Math.min(100, (pos / span) * 100));
                progressFill.style.width = pct.toFixed(1) + '%';
                const need = next.min_qty - qty;
                progressLab.textContent = 'Add ' + need + ' more code' + (need === 1 ? '' : 's') + ' to unlock ' + next.discount_pct + '% off.';
            }

            function recompute(){
                const tier  = selectedTier();
                const price = pickAnnualPrice(tier);
                const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                qtyInput.value = String(qty);
                highlightPreset(qty);
                if (!price) {
                    summarySub.textContent  = '—';
                    summaryTot.textContent  = '—';
                    summaryDiscRow.hidden   = true;
                    savingsEl.hidden        = true;
                    ctaSpan.textContent     = 'Continue to checkout';
                    return;
                }
                const subCents   = price.unit_amount_cents * qty;
                const pct        = discountPctFor(qty);
                const discCents  = Math.round(subCents * pct / 100);
                const totalCents = subCents - discCents;

                summarySub.textContent = qty + ' × ' + dollars(price.unit_amount_cents) + ' = ' + dollars(subCents);
                if (pct > 0) {
                    summaryDiscRow.hidden = false;
                    summaryDisc.textContent = '−' + dollars(discCents) + '  (' + pct + '% off)';
                    savingsEl.hidden = false;
                    savingsEl.textContent = "🎉 You're saving " + dollarsRound(discCents) + ' with bulk pricing.';
                } else {
                    summaryDiscRow.hidden = true;
                    savingsEl.hidden = true;
                }
                summaryTot.textContent = dollars(totalCents);
                ctaSpan.textContent = 'Continue to checkout · ' + qty + ' code' + (qty === 1 ? '' : 's') + ' · ' + dollars(totalCents);
                renderProgress(qty);
            }

            async function loadProducts(){
                showError('');
                try {
                    const res  = await fetch(ENDPOINTS.products);
                    const json = await res.json();
                    products  = json.products || [];
                    bulkTiers = (json.bulk_discount_tiers || []).slice().sort((a,b) => a.min_qty - b.min_qty);
                    renderTiers();
                    renderPresets();
                    recompute();
                } catch (err) {
                    showError('Failed to load tiers: ' + err.message);
                }
            }

            // Quantity stepper buttons
            document.querySelectorAll('[data-lg-qty-step]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const step = parseInt(btn.dataset.lgQtyStep, 10) || 1;
                    qtyInput.value = String(Math.max(1, (parseInt(qtyInput.value, 10) || 1) + step));
                    recompute();
                });
            });
            qtyInput.addEventListener('input', recompute);

            submitBtn.addEventListener('click', async function(){
                showError('');
                const tier  = selectedTier();
                const price = pickAnnualPrice(tier);
                if (!price) { showError('Please pick a tier.'); return; }

                const qty   = Math.max(1, parseInt(qtyInput.value, 10) || 1);
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }

                if (mountedSession) { try { mountedSession.destroy(); } catch (e) {} mountedSession = null; }
                checkoutEl.innerHTML = '';
                submitBtn.disabled    = true;
                const origCta = ctaSpan.textContent;
                ctaSpan.textContent = 'Loading…';

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
                    submitBtn.disabled  = false;
                    ctaSpan.textContent = origCta;
                    recompute();
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
        shortcode_atts( [], (array) $atts, 'lg_manage_subscription' );

        $user = wp_get_current_user();
        if ( $user->ID === 0 ) {
            return '<p><em>Please sign in to manage your subscription.</em></p>';
        }
        $email = (string) $user->user_email;
        if ( $email === '' ) {
            return '';
        }

        $customer = \LGMS\Repos\CustomerRepo::findByEmail( $email );
        if ( $customer === null ) {
            return '<p><em>No membership record found on this account.</em></p>';
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

        $portalEndpoint  = esc_url_raw( rtrim( (string) home_url( '/billing' ), '/' ) . '/v1/portal' );
        $productsUrl     = esc_url_raw( rtrim( (string) home_url( '/billing' ), '/' ) . '/v1/products' );
        $cancelEndpoint  = esc_url_raw( rest_url( 'lg-member-sync/v1/me/cancel-subscription' ) );
        $switchEndpoint  = esc_url_raw( rest_url( 'lg-member-sync/v1/me/switch-plan' ) );
        $nonce           = wp_create_nonce( 'wp_rest' );
        $emailEsc        = esc_attr( $email );

        ob_start();
        ?>
        <div class="lg-manage-sub">
            <?php if ( $subs === [] ) : ?>
                <p>You don't have an active subscription right now.</p>
                <p><a href="<?php echo esc_url( home_url( '/lgjoin/' ) ); ?>">Pick a plan to get started &rarr;</a></p>
            <?php else : ?>
                <?php foreach ( $subs as $sub ) :
                    $subId = (string) $sub['stripe_subscription_id'];
                    $tier  = self::tierLabelForPrice( (string) $sub['stripe_price_id'] );
                    $endsAt = (string) ( $sub['current_period_end'] ?? '' );
                    $cape   = (int) ( $sub['cancel_at_period_end'] ?? 0 ) === 1;
                ?>
                <div class="lg-manage-sub__card" style="border:1px solid #ddd;border-radius:6px;padding:1em 1.2em;margin-bottom:1em;max-width:640px;" data-lg-sub="<?php echo esc_attr( $subId ); ?>">
                    <h4 style="margin:0 0 0.5em;"><?php echo esc_html( $tier ?: 'Membership' ); ?></h4>
                    <p style="margin:0.2em 0;color:#444;">
                        Status: <strong><?php echo esc_html( (string) $sub['status'] ); ?></strong><br>
                        <?php if ( $cape ) : ?>
                            Ends on <strong data-lg-renew-date><?php echo esc_html( self::shortDate( $endsAt ) ); ?></strong> &mdash; will not renew.
                        <?php else : ?>
                            Renews on <strong data-lg-renew-date><?php echo esc_html( self::shortDate( $endsAt ) ); ?></strong>
                        <?php endif; ?>
                    </p>

                    <div class="lg-manage-sub__actions" style="margin-top:1em;">
                        <button type="button" class="lg-manage-sub__btn" data-lg-action="switch" data-lg-sub="<?php echo esc_attr( $subId ); ?>" data-lg-current-price="<?php echo esc_attr( (string) $sub['stripe_price_id'] ); ?>">Change plan</button>
                        <?php if ( ! $cape ) : ?>
                            <button type="button" class="lg-manage-sub__btn" data-lg-action="cancel" data-lg-sub="<?php echo esc_attr( $subId ); ?>" style="margin-left:8px;">Cancel subscription</button>
                        <?php else : ?>
                            <em style="margin-left:8px;color:#666;">Already scheduled for cancellation.</em>
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

            <p style="margin-top:1em;color:#444;">
                Need to update your card or download invoices?
                <a href="#" data-lg-portal>Open the Stripe billing portal &rarr;</a>
                <span data-lg-portal-error style="color:#b00;margin-left:8px;"></span>
            </p>
        </div>

        <script>
        (function(){
            const PORTAL  = '<?php echo esc_js( $portalEndpoint ); ?>';
            const PROD    = '<?php echo esc_js( $productsUrl ); ?>';
            const CANCEL  = '<?php echo esc_js( $cancelEndpoint ); ?>';
            const SWITCH  = '<?php echo esc_js( $switchEndpoint ); ?>';
            const NONCE   = <?php echo wp_json_encode( $nonce ); ?>;
            const EMAIL   = <?php echo wp_json_encode( $email ); ?>;

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
                    const renewDate = card.querySelector('[data-lg-renew-date]')?.textContent || 'your next renewal date';
                    plansEl.innerHTML = rows.map(r => {
                        const dollars = (r.amount / 100).toFixed(2);
                        const label = r.product + ' &mdash; $' + dollars + '/' + r.interval + (r.isCurrent ? ' (current)' : '');
                        const disabled = r.isCurrent ? ' disabled' : '';
                        return '<label style="display:block;padding:0.3em 0;">' +
                            '<input type="radio" name="newprice-' + subId + '" value="' + r.priceId + '"' + disabled + '> ' +
                            label + '</label>';
                    }).join('') +
                    '<fieldset style="margin-top:1em;border:1px solid #eee;padding:0.8em 1em;">' +
                        '<legend>When should the change take effect?</legend>' +
                        '<label style="display:block;margin:0.3em 0;">' +
                            '<input type="radio" name="timing-' + subId + '" value="now" checked> ' +
                            '<strong>Switch now</strong> &mdash; you will be billed the prorated difference today and your access changes immediately.' +
                        '</label>' +
                        '<label style="display:block;margin:0.3em 0;">' +
                            '<input type="radio" name="timing-' + subId + '" value="period_end"> ' +
                            '<strong>Switch on ' + renewDate + '</strong> &mdash; no charge today; the change takes effect at your next renewal.' +
                        '</label>' +
                    '</fieldset>' +
                    '<div style="margin-top:1em;">' +
                        '<button type="button" class="lg-manage-sub__btn" data-lg-action="switch-confirm" data-lg-sub="' + subId + '">Confirm change</button> ' +
                        '<button type="button" class="lg-manage-sub__btn" data-lg-action="switch-back">Never mind</button>' +
                    '</div>';

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
                        const msg = timing === 'now'
                            ? 'Switch to this plan now and be charged the prorated difference today?'
                            : 'Schedule the switch for ' + renewDate + '? You will not be charged today.';
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
            'heading'    => 'Choose your membership',
            'subheading' => '',
            'bullets'    => '',           // pipe-separated, e.g. "Forums|Archive|Sponsor benefits"
            'popular'    => 'looth3',     // product ref to mark "Most popular"
            'taglines'   => '',           // ref:tagline pipe-separated, e.g. "looth2:Members-only forum access|looth3:Everything in LITE plus exclusive content"
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

        // Country override via ?country=XX URL param. Forwarded to /v1/products
        // (drives which tier products appear) and /v1/checkout body (forwarded
        // for completeness — the actual regional verification reads the billing
        // country from the Stripe Checkout form, not this param).
        $countryFromUrl = isset( $_GET['country'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $_GET['country'] ) ) : '';
        if ( strlen( $countryFromUrl ) !== 2 ) {
            $countryFromUrl = '';
        }

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
        $email        = esc_attr( $emailValue );
        $name         = esc_attr( $nameValue );
        $promoEsc     = esc_attr( (string) $promoFromUrl );
        $endpointsJs  = wp_json_encode( $endpoints );
        $configJs     = wp_json_encode( [ 'popular' => $popularRef, 'taglines' => $taglineMap ] );

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

            <div class="lg-join__region-note" data-lg-region-note hidden></div>

            <div class="lg-join__tiers" data-lg-join-tiers>
                <p class="lg-join__loading">Loading plans&hellip;</p>
            </div>

            <div class="lg-join__form" data-lg-join-form hidden>
                <h3 class="lg-join__form-heading" data-lg-form-heading>Almost there</h3>
                <div class="lg-join__form-grid">
                    <div class="lg-join__field">
                        <label>Email <input type="email" name="email" value="<?php echo $email; ?>" required></label>
                    </div>
                    <div class="lg-join__field">
                        <label>Name <input type="text" name="name" value="<?php echo $name; ?>" required></label>
                        <small>Used for your account / community profile.</small>
                    </div>
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

            <div class="lg-join__checkout" data-lg-join-checkout></div>

            <div class="lg-join__error" data-lg-join-error aria-live="polite"></div>
        </div>

        <script src="https://js.stripe.com/v3/"></script>
        <script>
        (function(){
            const ENDPOINTS = <?php echo $endpointsJs; ?>;
            const PROMO     = <?php echo wp_json_encode( $promoFromUrl ); ?>;
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

            const tiersEl    = document.querySelector('[data-lg-join-tiers]');
            const formEl     = document.querySelector('[data-lg-join-form]');
            const formHeadEl = document.querySelector('[data-lg-form-heading]');
            const continueBt = document.querySelector('[data-lg-continue]');
            const backBt     = document.querySelector('[data-lg-back]');
            const checkoutEl = document.querySelector('[data-lg-join-checkout]');
            const errorEl    = document.querySelector('[data-lg-join-error]');
            const emailInput = document.querySelector('input[name="email"]');
            const nameInput  = document.querySelector('input[name="name"]');

            let stripe         = null;
            let mountedSession = null;
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

            function renderTiers(products){
                tiersEl.innerHTML = '';
                products.forEach(function(prod){
                    const card = document.createElement('div');
                    card.className = 'lg-join__tier';
                    if (prod.ref && CONFIG.popular && prod.ref === CONFIG.popular) {
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

                    const list = document.createElement('div');
                    list.className = 'lg-join__tier-prices';
                    const sorted = sortPrices(prod.prices);
                    sorted.forEach(function(price, idx){
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'lg-join__buy';
                        // Highlight the yearly subscription as the primary CTA
                        if (price.type === 'recurring' && price.interval === 'year') {
                            btn.classList.add('is-primary');
                        }
                        btn.textContent = priceLabel(price);
                        btn.addEventListener('click', () => selectPrice(price, prod, btn));
                        list.appendChild(btn);
                    });
                    card.appendChild(list);
                    tiersEl.appendChild(card);
                });
            }

            // Step 1: customer picks a price → highlight selected card, reveal form panel.
            function selectPrice(price, prod, btn){
                showError('');
                pendingPriceId = price.stripe_price_id;
                pendingLabel   = priceLabel(price);

                // Highlight selected card
                document.querySelectorAll('.lg-join__tier').forEach(c => c.classList.remove('is-selected'));
                const card = btn.closest('.lg-join__tier');
                if (card) card.classList.add('is-selected');

                formHeadEl.textContent = 'Continue to ' + prod.name + ' — ' + pendingLabel.replace(/^Subscribe\s*—\s*|^Pay once\s*—\s*/, '');
                formEl.hidden = false;
                // If checkout was already mounted (user clicked again), tear it down.
                if (mountedSession) { try { mountedSession.destroy(); } catch (_) {} mountedSession = null; checkoutEl.innerHTML = ''; }
                formEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Auto-focus email if blank (anon user); otherwise focus continue button.
                if (!emailInput.value.trim()) {
                    emailInput.focus();
                } else {
                    continueBt.focus();
                }
            }

            // Step 2: customer confirms → mount Stripe Checkout.
            async function startCheckout(){
                showError('');
                const email = (emailInput.value || '').trim();
                if (!email) { showError('Email is required.'); emailInput.focus(); return; }
                if (!pendingPriceId) { showError('Pick a plan first.'); return; }

                if (mountedSession) { try { mountedSession.destroy(); } catch (_) {} mountedSession = null; }
                checkoutEl.innerHTML = '';
                continueBt.disabled = true;
                const orig = continueBt.textContent;
                continueBt.textContent = 'Loading…';

                try {
                    const body = {
                        price_id: pendingPriceId,
                        email:    email,
                        name:     (nameInput.value || '').trim(),
                    };
                    const promoInput = document.querySelector('input[name="promo_code"]');
                    const typedPromo = promoInput ? (promoInput.value || '').trim() : '';
                    const finalPromo = typedPromo !== '' ? typedPromo : (PROMO || '');
                    if (finalPromo) body.promo_code = finalPromo;
                    if (DETECTED_COUNTRY) body.country = DETECTED_COUNTRY;

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
                        if (!cfg.publishableKey) { showError('Stripe not configured.'); return; }
                        stripe = Stripe(cfg.publishableKey);
                    }

                    mountedSession = await stripe.initEmbeddedCheckout({ clientSecret: sessData.clientSecret });
                    mountedSession.mount(checkoutEl);
                    checkoutEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } catch (err) {
                    showError('Network error: ' + err.message);
                } finally {
                    continueBt.disabled    = false;
                    continueBt.textContent = orig;
                }
            }

            // Wire step 2 buttons
            continueBt.addEventListener('click', startCheckout);
            backBt.addEventListener('click', function(){
                formEl.hidden = true;
                pendingPriceId = null;
                document.querySelectorAll('.lg-join__tier').forEach(c => c.classList.remove('is-selected'));
                if (mountedSession) { try { mountedSession.destroy(); } catch (_) {} mountedSession = null; checkoutEl.innerHTML = ''; }
                tiersEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
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

            function renderError(msg, portalUrl){
                resultEl.className = 'lg-redeem-gift__result is-error';
                if (portalUrl) {
                    resultEl.innerHTML = msg + ' <a href="' + portalUrl + '" target="_blank">Manage your subscription</a>';
                } else {
                    resultEl.textContent = msg;
                }
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

    public static function refundRequest( $atts = [] ): string
    {
        $atts = shortcode_atts( [
            'heading' => 'Request a refund',
        ], (array) $atts, 'lg_refund_request' );

        $user        = wp_get_current_user();
        $loggedIn    = $user->ID > 0;
        $emailValue  = $loggedIn ? (string) $user->user_email : '';
        $nameValue   = $loggedIn ? trim( (string) ( $user->display_name ?: $user->user_login ) ) : '';
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

    private static function shortDate( string $datetime ): string
    {
        $ts = $datetime ? strtotime( $datetime ) : false;
        return $ts ? gmdate( 'M j, Y', $ts ) : 'unknown date';
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
            .lg-member-nav { margin: 0 0 1.5em; padding: 0.5em 0; border-bottom: 1px solid rgba(0,0,0,.08); display: flex; flex-wrap: wrap; gap: 0.25em 1.25em; align-items: center; }
            .lg-member-nav__link { display: inline-block; padding: 0.35em 0; color: inherit; text-decoration: none; font-size: 0.95em; opacity: 0.7; border-bottom: 2px solid transparent; transition: opacity .15s, border-color .15s; }
            .lg-member-nav__link:hover { opacity: 1; }
            .lg-member-nav__link.is-current { opacity: 1; font-weight: 600; border-bottom-color: currentColor; }
        ';
        $css = preg_replace( '/\s+/', ' ', $css );

        return '<style>' . $css . '</style><nav class="lg-member-nav" aria-label="Membership">' . implode( '', $links ) . '</nav>';
    }
}
