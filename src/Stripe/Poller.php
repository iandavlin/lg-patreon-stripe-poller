<?php

declare(strict_types=1);

namespace LGMS\Stripe;

use LGMS\Db;
use Throwable;

/**
 * Cursor-based Stripe events poller.
 *
 * On first run (no cursor in lg_event_cursor): fetches the most recent
 * event and stores its ID as the starting point — does not back-process
 * historical events. This avoids replaying everything that's already been
 * provisioned by Slim's /v1/return.
 *
 * On subsequent runs: lists events newer than the cursor (using
 * ending_before=cursor), processes oldest-first, advances the cursor.
 */
final class Poller
{
    private const SOURCE = 'stripe';
    private const PAGE_LIMIT = 100;

    public function __construct(
        private readonly Client       $stripe,
        private readonly EventHandler $handler,
    ) {}

    /**
     * @return array{processed:int, cursor:?string, status:string, log:string[]}
     */
    public function poll(): array
    {
        $log    = [];
        $cursor = $this->loadCursor();

        // First run: just record the latest event ID and stop.
        if ( $cursor === null ) {
            try {
                $list  = $this->stripe->listEvents( [ 'limit' => 1 ] );
                $first = $list->data[0] ?? null;
                $newCursor = $first ? (string) $first->id : null;
                $this->saveCursor( $newCursor, 'first_run', null );
                $log[] = $newCursor
                    ? "first_run: cursor set to {$newCursor}"
                    : 'first_run: no events yet';
                return [ 'processed' => 0, 'cursor' => $newCursor, 'status' => 'first_run', 'log' => $log ];
            } catch ( Throwable $e ) {
                $this->saveCursor( null, 'error', $e->getMessage() );
                throw $e;
            }
        }

        // Subsequent runs.
        try {
            $list = $this->stripe->listEvents( [
                'limit'         => self::PAGE_LIMIT,
                'ending_before' => $cursor,
            ] );
        } catch ( Throwable $e ) {
            $this->saveCursor( $cursor, 'error', $e->getMessage() );
            throw $e;
        }

        // Stripe returns newest-first. Reverse so we process chronologically.
        $events    = $list->data ?? [];
        $events    = array_reverse( $events );
        $processed = 0;
        $newCursor = $cursor;

        foreach ( $events as $event ) {
            $log[]     = sprintf( '%s %s', $event->id, $this->handler->handle( $event ) );
            $newCursor = (string) $event->id;
            $processed++;
        }

        $hasMore = (bool) ( $list->has_more ?? false );
        $status  = $hasMore ? 'partial' : 'ok';

        $this->saveCursor( $newCursor, $status, null );

        return [ 'processed' => $processed, 'cursor' => $newCursor, 'status' => $status, 'log' => $log ];
    }

    private function loadCursor(): ?string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT cursor_id FROM lg_event_cursor WHERE source = ? LIMIT 1'
        );
        $stmt->execute( [ self::SOURCE ] );
        $val = $stmt->fetchColumn();
        return ( $val !== false && $val !== null && $val !== '' ) ? (string) $val : null;
    }

    private function saveCursor(?string $cursor, string $status, ?string $error): void
    {
        Db::pdo()->prepare(
            'INSERT INTO lg_event_cursor (source, cursor_id, last_polled, last_status, last_error)
             VALUES (?, ?, NOW(), ?, ?)
             ON DUPLICATE KEY UPDATE
                 cursor_id   = VALUES(cursor_id),
                 last_polled = VALUES(last_polled),
                 last_status = VALUES(last_status),
                 last_error  = VALUES(last_error)'
        )->execute( [ self::SOURCE, $cursor, $status, $error ] );
    }
}
