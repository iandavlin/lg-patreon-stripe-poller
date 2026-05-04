<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Repos\EntitlementRepo;
use LGMS\Stripe\Client as StripeClient;
use LGMS\Stripe\EventHandler as StripeEventHandler;
use LGMS\Stripe\Poller as StripePoller;
use Throwable;

/**
 * Cron entrypoint. Runs hourly via WP cron (driven by OS cron on prod).
 * Also callable on demand via REST /run-now.
 *
 * Two passes:
 *   1. Pull new Stripe events → update lg_membership state.
 *   2. Sync lg_membership → WP (provisioning + role arbitration).
 *
 * Pass 2 also runs synchronously from Slim's /v1/return via the
 * /sync-customer REST endpoint, so on-checkout provisioning is instant.
 */
final class Tick
{
    public static function run(): void
    {
        $log = LGMS_PLUGIN_DIR . 'tick.log';
        @file_put_contents( $log, sprintf( "[%s] tick start\n", gmdate( 'c' ) ), FILE_APPEND );

        // Pass 1: Stripe poll
        try {
            $client  = new StripeClient();
            $handler = new StripeEventHandler( $client );
            $poller  = new StripePoller( $client, $handler );
            $result  = $poller->poll();
            @file_put_contents( $log, sprintf(
                "[%s] stripe poll: status=%s processed=%d cursor=%s\n",
                gmdate( 'c' ),
                $result['status'],
                $result['processed'],
                $result['cursor'] ?? '(none)',
            ), FILE_APPEND );
            foreach ( $result['log'] as $entry ) {
                @file_put_contents( $log, "  {$entry}\n", FILE_APPEND );
            }
        } catch ( Throwable $e ) {
            @file_put_contents( $log, sprintf(
                "[%s] stripe poll FAILED: %s\n",
                gmdate( 'c' ),
                $e->getMessage(),
            ), FILE_APPEND );
        }

        // Pass 1.5: expiry sweep — revoke elapsed gift-code entitlements
        try {
            $expired = EntitlementRepo::sweepExpiredGiftEntitlements();
            if ( $expired !== [] ) {
                @file_put_contents( $log, sprintf(
                    "[%s] expiry sweep: revoked gift entitlements for customer_ids=%s\n",
                    gmdate( 'c' ),
                    implode( ',', $expired ),
                ), FILE_APPEND );
            }
        } catch ( Throwable $e ) {
            @file_put_contents( $log, sprintf(
                "[%s] expiry sweep FAILED: %s\n",
                gmdate( 'c' ),
                $e->getMessage(),
            ), FILE_APPEND );
        }

        // Pass 1.7: reconcile orphaned Stripe Checkout sessions.
        // Catches cases where the customer paid but their browser never
        // hit /v1/return (modal close, browser crash, network drop).
        // Slim is authoritative — we just trigger the sweep.
        //
        // CF bot-challenge bypass: Cloudflare intercepts wp_remote_post
        // when we call our own host from PHP-cURL (the request looks like
        // a server-side bot). Pin resolution to 127.0.0.1 to hit origin
        // nginx directly — same trick Slim's WpSync uses in reverse.
        try {
            $base   = (string) get_option( 'lgms_billing_base_url', home_url( '/billing' ) );
            $secret = (string) get_option( 'lgms_shared_secret', '' );
            if ( $secret === '' ) {
                @file_put_contents( $log, sprintf(
                    "[%s] reconcile-pending SKIPPED: no shared secret configured
",
                    gmdate( 'c' )
                ), FILE_APPEND );
            } else {
                $url   = rtrim( $base, '/' ) . '/v1/reconcile-pending';
                $parts = parse_url( $url );
                $host  = $parts['host'] ?? '';
                $ch    = curl_init( $url );
                curl_setopt_array( $ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'X-LGMS-Token: ' . $secret,
                    ],
                    CURLOPT_POSTFIELDS     => '{}',
                ] );
                if ( $host !== '' ) {
                    $scheme = $parts['scheme'] ?? 'https';
                    $port   = $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 );
                    curl_setopt( $ch, CURLOPT_RESOLVE, [ "{$host}:{$port}:127.0.0.1" ] );
                }
                $body = (string) curl_exec( $ch );
                $err  = curl_error( $ch );
                $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
                curl_close( $ch );
                if ( $err !== '' ) {
                    @file_put_contents( $log, sprintf(
                        "[%s] reconcile-pending FAILED: %s
",
                        gmdate( 'c' ),
                        $err
                    ), FILE_APPEND );
                } else {
                    @file_put_contents( $log, sprintf(
                        "[%s] reconcile-pending: HTTP %d %s
",
                        gmdate( 'c' ),
                        $code,
                        substr( $body, 0, 400 )
                    ), FILE_APPEND );
                }
            }
        } catch ( Throwable $e ) {
            @file_put_contents( $log, sprintf(
                "[%s] reconcile-pending threw: %s
",
                gmdate( 'c' ),
                $e->getMessage()
            ), FILE_APPEND );
        }

        // Pass 2: sync sweep
        try {
            $results = Sync::all();
            $ok      = 0;
            $errs    = 0;
            foreach ( $results as $cid => $r ) {
                if ( ! empty( $r['ok'] ) ) {
                    $ok++;
                } else {
                    $errs++;
                    @file_put_contents( $log, sprintf(
                        "  sync customer %d: %s\n",
                        $cid,
                        $r['message'] ?? 'unknown error',
                    ), FILE_APPEND );
                }
            }
            @file_put_contents( $log, sprintf(
                "[%s] sync sweep: ok=%d errors=%d\n",
                gmdate( 'c' ),
                $ok,
                $errs,
            ), FILE_APPEND );
        } catch ( Throwable $e ) {
            @file_put_contents( $log, sprintf(
                "[%s] sync sweep FAILED: %s\n",
                gmdate( 'c' ),
                $e->getMessage(),
            ), FILE_APPEND );
        }
    }
}
