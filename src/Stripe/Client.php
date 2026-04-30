<?php

declare(strict_types=1);

namespace LGMS\Stripe;

use RuntimeException;
use Stripe\StripeClient;

/**
 * Thin wrapper over \Stripe\StripeClient — only the calls this plugin uses.
 * Reads the secret key from wp_options.
 */
final class Client
{
    private readonly StripeClient $sdk;

    public function __construct()
    {
        $key = (string) get_option( 'lgms_stripe_secret_key', '' );
        if ( $key === '' ) {
            throw new RuntimeException( 'LGMS: Stripe secret key not configured. Visit Settings → LG Member Sync.' );
        }
        $this->sdk = new StripeClient( $key );
    }

    /** @return iterable<\Stripe\Event> */
    public function listEvents(array $params = []): iterable
    {
        return $this->sdk->events->all( $params );
    }

    public function retrieveSubscription(string $id, array $expand = []): object
    {
        $params = $expand !== [] ? [ 'expand' => $expand ] : [];
        return $this->sdk->subscriptions->retrieve( $id, $params );
    }

    public function retrieveCheckoutSession(string $id, array $expand = []): object
    {
        $params = $expand !== [] ? [ 'expand' => $expand ] : [];
        return $this->sdk->checkout->sessions->retrieve( $id, $params );
    }

    public function retrieveInvoice(string $id, array $expand = []): object
    {
        $params = $expand !== [] ? [ 'expand' => $expand ] : [];
        return $this->sdk->invoices->retrieve( $id, $params );
    }

    /**
     * List Checkout Sessions backed by a given Payment Intent. Used during
     * gift-charge refund handling to map a refunded charge → originating
     * Checkout Session → gift_codes.
     *
     * @return list<object>
     */
    public function listSessionsByPaymentIntent(string $paymentIntentId): array
    {
        $resp = $this->sdk->checkout->sessions->all( [
            'payment_intent' => $paymentIntentId,
            'limit'          => 5,
        ] );
        return iterator_to_array( $resp->data ?? [] );
    }

    public function cancelSubscription(string $id): object
    {
        return $this->sdk->subscriptions->cancel( $id, [] );
    }

    public function updateSubscription(string $id, array $params): object
    {
        return $this->sdk->subscriptions->update( $id, $params );
    }

    /**
     * Most-recent paid invoice for a subscription, or null if none paid yet.
     */
    public function latestPaidInvoiceForSubscription(string $subscriptionId): ?object
    {
        $resp = $this->sdk->invoices->all( [
            'subscription' => $subscriptionId,
            'status'       => 'paid',
            'limit'        => 1,
        ] );
        $data = $resp->data ?? [];
        return $data !== [] ? $data[0] : null;
    }

    public function createRefund(array $params): object
    {
        return $this->sdk->refunds->create( $params );
    }

    public function createSubscriptionSchedule(array $params): object
    {
        return $this->sdk->subscriptionSchedules->create( $params );
    }

    public function updateSubscriptionSchedule(string $id, array $params): object
    {
        return $this->sdk->subscriptionSchedules->update( $id, $params );
    }
}
