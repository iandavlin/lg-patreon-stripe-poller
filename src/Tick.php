<?php

declare(strict_types=1);

namespace LGMS;

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
