<?php

declare(strict_types=1);

namespace LGMS\Stripe;

use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Repos\GiftCodeRepo;
use LGMS\Repos\ProductRepo;
use LGMS\Repos\SubscriptionRepo;
use LGMS\Wp\AdminAlerts;
use Throwable;

/**
 * Dispatches Stripe events to the right business action. Mirrors the
 * Slim app's deleted WebhookHandler, but reads events from the polling
 * Events API rather than receiving signed webhooks.
 *
 * Phase 2: writes lg_membership state (customers, subscriptions,
 * entitlements). Phase 3 will add WP user provisioning + lg_role_sources
 * + arbiter + wp_capabilities.
 */
final class EventHandler
{
    public function __construct(
        private readonly Client $stripe,
    ) {}

    /** @return string short result line for logging */
    public function handle(object $event): string
    {
        $type   = (string) ( $event->type ?? '' );
        $object = $event->data->object ?? null;

        try {
            return match ( $type ) {
                'checkout.session.completed'    => $this->onCheckoutCompleted( $object ),
                'invoice.paid'                  => $this->onInvoicePaid( $object ),
                'customer.subscription.updated' => $this->onSubscriptionUpdated( $object ),
                'customer.subscription.deleted' => $this->onSubscriptionDeleted( $object ),
                'invoice.payment_failed'        => $this->onPaymentFailed( $object ),
                'charge.refunded'               => $this->onChargeRefunded( $object ),
                'charge.dispute.created'        => $this->onChargeDisputed( $object ),
                default                         => "skip {$type}",
            };
        } catch ( Throwable $e ) {
            return sprintf( 'ERROR %s: %s', $type, $e->getMessage() );
        }
    }

    /* ------------------------------------------------------------------ */

    private function onCheckoutCompleted(?object $session): string
    {
        if ( ( $session->mode ?? '' ) !== 'subscription' ) {
            return 'checkout.session.completed: not subscription mode';
        }

        $stripeCustomerId = (string) ( $session->customer ?? '' );
        $email            = (string) ( $session->customer_details->email ?? $session->customer_email ?? '' );
        $subId            = (string) ( $session->subscription ?? '' );
        $name             = trim( (string) ( $session->customer_details->name ?? '' ) );
        $country          = $session->customer_details->address->country ?? null;

        if ( $stripeCustomerId === '' || $subId === '' || $email === '' ) {
            return 'checkout.session.completed: missing required fields';
        }

        $sub     = $this->stripe->retrieveSubscription( $subId, [ 'items.data.price' ] );
        $priceId = (string) ( $sub->items->data[0]->price->id ?? '' );
        $tier    = $priceId !== '' ? ProductRepo::tierForPrice( $priceId ) : null;
        if ( $tier === null ) {
            return "checkout.session.completed: no tier for {$priceId}";
        }

        $customer = CustomerRepo::findOrCreate( $email, $stripeCustomerId, $name ?: null, $country );
        $row      = SubscriptionRepo::upsert(
            (int) $customer['id'],
            $subId,
            $priceId,
            (string) ( $sub->status ?? '' ),
            (bool) ( $sub->cancel_at_period_end ?? false ),
            $sub->current_period_start ?? null,
            $sub->current_period_end ?? null,
            $sub->canceled_at ?? null,
        );
        EntitlementRepo::grantMembershipFromSubscription( (int) $customer['id'], $tier, (int) $row['id'] );

        return "checkout.session.completed: customer {$customer['id']} → {$tier}";
    }

    private function onInvoicePaid(?object $invoice): string
    {
        $stripeCustomerId = (string) ( $invoice->customer ?? '' );
        $subId            = (string) ( $invoice->subscription ?? '' );
        if ( $stripeCustomerId === '' || $subId === '' ) {
            return 'invoice.paid: missing fields';
        }

        $customer = CustomerRepo::findByStripeCustomerId( $stripeCustomerId );
        if ( ! $customer ) {
            return "invoice.paid: no customer for {$stripeCustomerId}";
        }

        $sub     = $this->stripe->retrieveSubscription( $subId, [ 'items.data.price' ] );
        $priceId = (string) ( $sub->items->data[0]->price->id ?? '' );
        $tier    = $priceId !== '' ? ProductRepo::tierForPrice( $priceId ) : null;
        if ( $tier === null ) {
            return "invoice.paid: no tier for {$priceId}";
        }

        $row = SubscriptionRepo::upsert(
            (int) $customer['id'],
            $subId,
            $priceId,
            (string) ( $sub->status ?? '' ),
            (bool) ( $sub->cancel_at_period_end ?? false ),
            $sub->current_period_start ?? null,
            $sub->current_period_end ?? null,
            $sub->canceled_at ?? null,
        );
        EntitlementRepo::grantMembershipFromSubscription( (int) $customer['id'], $tier, (int) $row['id'] );

        return "invoice.paid: customer {$customer['id']} → {$tier}";
    }

    /**
     * Handle customer.subscription.updated. Policy mirrors Slim's
     * SubscriptionWebhookHandler so direct webhooks and polled events
     * produce identical state:
     *
     *   active / trialing             → grant / re-grant entitlement
     *   past_due                      → keep entitlement (Stripe retry window)
     *   canceled / incomplete_expired → revoke immediately
     *   anything else                 → no entitlement change
     */
    private function onSubscriptionUpdated(?object $sub): string
    {
        $stripeCustomerId = (string) ( $sub->customer ?? '' );
        $subId            = (string) ( $sub->id ?? '' );
        if ( $stripeCustomerId === '' || $subId === '' ) {
            return 'subscription.updated: missing fields';
        }

        $customer = CustomerRepo::findByStripeCustomerId( $stripeCustomerId );
        if ( ! $customer ) {
            return "subscription.updated: no customer for {$stripeCustomerId}";
        }

        $status  = (string) ( $sub->status ?? '' );
        $priceId = (string) ( $sub->items->data[0]->price->id ?? '' );
        $tier    = $priceId !== '' ? ProductRepo::tierForPrice( $priceId ) : null;

        $row = SubscriptionRepo::upsert(
            (int) $customer['id'],
            $subId,
            $priceId,
            $status,
            (bool) ( $sub->cancel_at_period_end ?? false ),
            $sub->current_period_start ?? null,
            $sub->current_period_end ?? null,
            $sub->canceled_at ?? null,
        );

        if ( $status === 'canceled' || $status === 'incomplete_expired' ) {
            EntitlementRepo::revokeBySource( EntitlementRepo::SOURCE_SUBSCRIPTION, (int) $row['id'] );
            return "subscription.updated: customer {$customer['id']} revoked ({$status})";
        }

        if ( ( $status === 'active' || $status === 'trialing' ) && $tier !== null ) {
            EntitlementRepo::grantMembershipFromSubscription( (int) $customer['id'], $tier, (int) $row['id'] );
            return "subscription.updated: customer {$customer['id']} → {$tier} ({$status})";
        }

        return "subscription.updated: status={$status}, no grant change";
    }

    private function onSubscriptionDeleted(?object $sub): string
    {
        $stripeCustomerId = (string) ( $sub->customer ?? '' );
        $subId            = (string) ( $sub->id ?? '' );
        if ( $stripeCustomerId === '' || $subId === '' ) {
            return 'subscription.deleted: missing fields';
        }

        $customer = CustomerRepo::findByStripeCustomerId( $stripeCustomerId );
        if ( ! $customer ) {
            return "subscription.deleted: no customer for {$stripeCustomerId}";
        }

        $existing = SubscriptionRepo::findByStripeId( $subId );
        if ( $existing ) {
            SubscriptionRepo::upsert(
                (int) $customer['id'],
                $subId,
                (string) $existing['stripe_price_id'],
                'canceled',
                false,
                null,
                null,
                time(),
            );
            EntitlementRepo::revokeBySource( EntitlementRepo::SOURCE_SUBSCRIPTION, (int) $existing['id'] );
        }
        return "subscription.deleted: customer {$customer['id']} revoked";
    }

    private function onPaymentFailed(?object $invoice): string
    {
        $cid      = (string) ( $invoice->customer ?? '' );
        $customer = $cid !== '' ? CustomerRepo::findByStripeCustomerId( $cid ) : null;

        if ( $customer ) {
            $email    = (string) $customer['email'];
            $name     = trim( (string) ( $customer['name'] ?? '' ) );
            $greeting = $name !== '' ? "Hi {$name}," : 'Hi,';
            $siteName = (string) get_bloginfo( 'name' );
            $updateUrl = home_url( '/manage-subscription/' );

            $subject = "Action needed: payment failed for your {$siteName} membership";
            $body    = "{$greeting}\n\nWe weren't able to process your payment for your {$siteName} membership. Your access is still active while we retry, but please update your payment method to avoid any interruption:\n\n{$updateUrl}\n\nIf you need help, just reply to this email.\n\n— The {$siteName} Team";

            wp_mail( $email, $subject, $body );
        }

        return "invoice.payment_failed: customer {$cid} — email sent, past_due retains access";
    }

    /**
     * Handle Stripe charge.refunded for both subscription and gift purchases.
     *
     * Flow:
     *   1. If the charge has an invoice → subscription path: revoke the
     *      subscription's entitlement (existing behavior).
     *   2. Else (one-time/gift path): look up the originating Checkout
     *      Session via the payment intent, then void unredeemed gift codes
     *      from that session. Already-redeemed codes are logged for admin
     *      review — recipient access is NOT auto-revoked because that would
     *      penalize a third party (the redeemer) for the purchaser's refund.
     */
    private function onChargeRefunded(?object $charge): string
    {
        $stripeCustomerId = (string) ( $charge->customer ?? '' );
        if ( $stripeCustomerId === '' ) {
            return 'charge.refunded: no customer';
        }
        $customer = CustomerRepo::findByStripeCustomerId( $stripeCustomerId );
        if ( ! $customer ) {
            return "charge.refunded: no customer for {$stripeCustomerId}";
        }

        // Subscription path
        $invoiceId = (string) ( $charge->invoice ?? '' );
        if ( $invoiceId !== '' ) {
            try {
                $invoice = $this->stripe->retrieveInvoice( $invoiceId );
                $subId   = (string) ( $invoice->subscription ?? '' );
                if ( $subId !== '' ) {
                    $existing = SubscriptionRepo::findByStripeId( $subId );
                    if ( $existing ) {
                        EntitlementRepo::revokeBySource(
                            EntitlementRepo::SOURCE_SUBSCRIPTION,
                            (int) $existing['id'],
                        );
                        return "charge.refunded: revoked sub {$subId} for customer {$customer['id']}";
                    }
                }
            } catch ( Throwable $e ) {
                return "charge.refunded: failed invoice lookup — {$e->getMessage()}";
            }
        }

        // Gift / one-time path
        $paymentIntentId = (string) ( $charge->payment_intent ?? '' );
        if ( $paymentIntentId === '' ) {
            return "charge.refunded: customer {$customer['id']} (no PI; manual review)";
        }

        try {
            $sessions = $this->stripe->listSessionsByPaymentIntent( $paymentIntentId );
        } catch ( Throwable $e ) {
            return "charge.refunded: failed session lookup — {$e->getMessage()}";
        }
        if ( $sessions === [] ) {
            return "charge.refunded: customer {$customer['id']} (no session for PI {$paymentIntentId}; manual review)";
        }

        $sessionId = (string) ( $sessions[0]->id ?? '' );
        if ( $sessionId === '' ) {
            return "charge.refunded: customer {$customer['id']} (session id missing; manual review)";
        }

        $result = GiftCodeRepo::voidByStripeSessionId( $sessionId );
        $vCount = count( $result['voided'] );
        $rCount = count( $result['already_redeemed'] );

        if ( $rCount > 0 ) {
            error_log( sprintf(
                'LGMS REFUND-REVIEW: gift codes already redeemed for charge=%s session=%s ids=[%s] — admin must decide on revocation.',
                (string) ( $charge->id ?? '' ),
                $sessionId,
                implode( ',', $result['already_redeemed'] ),
            ) );
        }

        return "charge.refunded: voided {$vCount} unredeemed gift code(s)" . ( $rCount > 0 ? ", flagged {$rCount} redeemed for admin review" : '' );
    }

    /**
     * Handle charge.dispute.created (chargeback).
     *
     * Policy: flag the customer for manual admin review, send an alert email,
     * post a dismissible notice in the WP admin dashboard. Access is NOT
     * automatically revoked — the admin decides after reviewing the dispute.
     *
     * Gift path: void unredeemed codes immediately (purchase fraudulent);
     * already-redeemed codes are flagged for admin review but recipient
     * access is not touched (the recipient is a third party).
     */
    private function onChargeDisputed( ?object $charge ): string
    {
        $chargeId         = (string) ( $charge->id ?? '' );
        $stripeCustomerId = (string) ( $charge->customer ?? '' );
        $disputeId        = (string) ( $charge->dispute ?? $chargeId );
        $amountCents      = (int) ( $charge->amount ?? 0 );
        $currency         = strtoupper( (string) ( $charge->currency ?? 'usd' ) );

        $customer = $stripeCustomerId !== '' ? CustomerRepo::findByStripeCustomerId( $stripeCustomerId ) : null;

        // Store a persistent WP admin notice so the banner shows until dismissed.
        $key      = (string) get_option( 'lgms_stripe_secret_key', '' );
        $mode     = strpos( $key, 'sk_test_' ) === 0 ? '/test' : '';
        $alerts   = (array) get_option( 'lgms_dispute_alerts', [] );
        $alerts[ $disputeId ] = [
            'dispute_id'     => $disputeId,
            'charge_id'      => $chargeId,
            'customer_id'    => $customer ? (int) $customer['id'] : null,
            'customer_email' => $customer ? (string) $customer['email'] : $stripeCustomerId,
            'amount'         => $amountCents,
            'currency'       => $currency,
            'created_at'     => gmdate( 'Y-m-d H:i:s' ),
            'stripe_url'     => 'https://dashboard.stripe.com' . $mode . '/disputes/' . rawurlencode( $disputeId ),
        ];
        update_option( 'lgms_dispute_alerts', $alerts, false );

        // Admin email alert.
        AdminAlerts::sendDisputeAlert( $chargeId, $disputeId, $customer, $amountCents, $currency );

        // Gift / one-time path: void unredeemed codes, flag redeemed ones.
        $giftNote = '';
        $invoiceId = (string) ( $charge->invoice ?? '' );
        if ( $invoiceId === '' ) {
            $paymentIntentId = (string) ( $charge->payment_intent ?? '' );
            if ( $paymentIntentId !== '' ) {
                try {
                    $sessions = $this->stripe->listSessionsByPaymentIntent( $paymentIntentId );
                    if ( $sessions !== [] ) {
                        $sessionId = (string) ( $sessions[0]->id ?? '' );
                        if ( $sessionId !== '' ) {
                            $result = GiftCodeRepo::voidByStripeSessionId( $sessionId );
                            $vCount = count( $result['voided'] );
                            $rCount = count( $result['already_redeemed'] );
                            if ( $rCount > 0 ) {
                                error_log( sprintf(
                                    'LGMS DISPUTE-REVIEW: redeemed gift codes on disputed charge=%s session=%s ids=[%s] — admin must decide on revocation.',
                                    $chargeId, $sessionId, implode( ',', $result['already_redeemed'] )
                                ) );
                            }
                            $giftNote = "; voided {$vCount} unredeemed gift code(s)" . ( $rCount > 0 ? ", flagged {$rCount} redeemed for review" : '' );
                        }
                    }
                } catch ( Throwable $e ) {
                    $giftNote = '; gift code lookup failed: ' . $e->getMessage();
                }
            }
        }

        $who = $customer ? "customer {$customer['id']} ({$customer['email']})" : "unknown ({$stripeCustomerId})";
        return "charge.dispute.created: flagged {$who} for manual review{$giftNote}";
    }
}
