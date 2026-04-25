<?php

declare(strict_types=1);

namespace LGMS\Stripe;

use LGMS\Repos\CustomerRepo;
use LGMS\Repos\EntitlementRepo;
use LGMS\Repos\ProductRepo;
use LGMS\Repos\SubscriptionRepo;
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

        if ( $status === 'active' && $tier !== null ) {
            EntitlementRepo::grantMembershipFromSubscription( (int) $customer['id'], $tier, (int) $row['id'] );
            return "subscription.updated: customer {$customer['id']} → {$tier}";
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
        $cid = (string) ( $invoice->customer ?? '' );
        return "invoice.payment_failed: customer {$cid} (no role change — past_due retains access)";
    }

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

        // If we can find the subscription via the invoice on the charge, revoke that entitlement.
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
                return "charge.refunded: failed lookup — {$e->getMessage()}";
            }
        }
        return "charge.refunded: customer {$customer['id']} (no sub linkage; manual review)";
    }
}
