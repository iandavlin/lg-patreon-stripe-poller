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
}
