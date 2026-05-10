<?php

declare(strict_types=1);

namespace LGMS\Wp;

/**
 * [lg_upcoming_events count="4"] — renders a horizontal slider of the next
 * N events from the `event` CPT, ordered by start date ascending.
 *
 * Read events meta keys:
 *   events_start_date_and_time_  string YYYYMMDD
 *   time_of_event                string HH:MM:SS (24h)
 *
 * Used inside the membership guide's Live Events section.
 */
final class UpcomingEvents
{
    public static function register(): void
    {
        add_shortcode( 'lg_upcoming_events', [ self::class, 'render' ] );
    }

    public static function render( $atts = [] ): string
    {
        $atts = shortcode_atts( [ 'count' => 4 ], (array) $atts, 'lg_upcoming_events' );
        $count = max( 1, min( 12, (int) $atts['count'] ) );

        $rows       = self::nextN( $count );
        $isFallback = $rows && ( $rows[0]['is_fallback'] ?? false );

        if ( ! $rows ) {
            return '<p style="color:#888;font-size:14px;">More events coming soon &mdash; the full schedule lives in the <a href="https://loothgroup.com/calendar/">calendar</a>.</p>';
        }

        ob_start();
        if ( $isFallback ) {
            echo '<p style="color:#888;font-size:13px;margin:0 0 8px;"><em>Recent shows &mdash; recordings live in the Archive.</em></p>';
        }
        echo '<div class="upcoming">';
        foreach ( $rows as $r ) :
            $thumb     = (string) $r['thumb'];
            $title     = (string) $r['title'];
            $permalink = (string) $r['permalink'];
            $datePill  = (string) $r['date_pill'];
            $dayLabel  = (string) $r['day_label'];
            $timeLabel = (string) $r['time_label'];
            $excerpt   = (string) $r['excerpt'];
            ?>
            <a class="ev-card" href="<?php echo esc_url( $permalink ); ?>">
                <div class="ev-thumb"<?php echo $thumb ? ' style="background-image:url(' . esc_url( $thumb ) . ');"' : ''; ?>>
                    <?php if ( $datePill !== '' ) : ?>
                        <span class="ev-date-pill"><?php echo esc_html( $datePill ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! $thumb ) : ?>
                        <span style="color:#8a7e69;font-size:13px;">&#128197;</span>
                    <?php endif; ?>
                </div>
                <div class="ev-body">
                    <?php if ( $dayLabel !== '' ) : ?>
                        <div class="ev-when"><?php echo esc_html( trim( $dayLabel . ( $timeLabel ? ' · ' . $timeLabel : '' ), ' ·' ) ); ?></div>
                    <?php endif; ?>
                    <p class="ev-title"><?php echo esc_html( $title ); ?></p>
                    <?php if ( $excerpt !== '' ) : ?>
                        <div class="ev-meta"><?php echo esc_html( $excerpt ); ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php
        endforeach;
        echo '</div>';

        return (string) ob_get_clean();
    }

    /**
     * Data-only accessor — used by the welcome email template (and anywhere
     * else that needs the events list without the slider HTML). Returns the
     * next N upcoming events, falling back to recent past events so callers
     * never get an empty list when there are events at all.
     *
     * Each row:
     *   id, title, permalink, thumb, date_pill, day_label, time_label,
     *   excerpt, is_fallback
     *
     * @return list<array<string,mixed>>
     */
    public static function nextN( int $count ): array
    {
        $count = max( 1, min( 12, $count ) );
        $today = gmdate( 'Ymd' );

        $q          = self::queryEvents( $count, $today, '>=', 'ASC' );
        $isFallback = false;
        if ( ! $q->have_posts() ) {
            $q = self::queryEvents( $count, $today, '<', 'DESC' );
            $isFallback = true;
        }
        if ( ! $q->have_posts() ) {
            wp_reset_postdata();
            return [];
        }

        $out = [];
        while ( $q->have_posts() ) {
            $q->the_post();
            $id  = (int) get_the_ID();
            $ymd = (string) get_post_meta( $id, 'events_start_date_and_time_', true );
            $hms = (string) get_post_meta( $id, 'time_of_event', true );
            [ $datePill, $dayLabel, $timeLabel ] = self::formatWhen( $ymd, $hms );

            $excerpt = trim( wp_strip_all_tags( (string) get_the_excerpt() ) );
            if ( $excerpt !== '' ) {
                $excerpt = mb_substr( $excerpt, 0, 60 );
                if ( mb_strlen( (string) get_the_excerpt() ) > 60 ) {
                    $excerpt .= '…';
                }
            }

            $out[] = [
                'id'          => $id,
                'title'       => (string) get_the_title(),
                'permalink'   => (string) get_permalink(),
                'thumb'       => (string) get_the_post_thumbnail_url( $id, 'medium' ),
                'date_pill'   => $datePill,
                'day_label'   => $dayLabel,
                'time_label'  => $timeLabel,
                'excerpt'     => $excerpt,
                'is_fallback' => $isFallback,
            ];
        }
        wp_reset_postdata();
        return $out;
    }

    private static function queryEvents( int $count, string $dateAnchor, string $compare, string $order ): \WP_Query
    {
        return new \WP_Query( [
            'post_type'      => 'event',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'meta_key'       => 'events_start_date_and_time_',
            'orderby'        => 'meta_value',
            'order'          => $order,
            'meta_query'     => [[
                'key'     => 'events_start_date_and_time_',
                'value'   => $dateAnchor,
                'compare' => $compare,
                'type'    => 'NUMERIC',
            ]],
            'no_found_rows'  => true,
        ] );
    }

    /**
     * Convert YYYYMMDD + HH:MM:SS into [ "Mar 27", "Fri", "3:00 PM ET" ].
     * Times are stored without TZ; we render with a static "ET" label until
     * the events team confirms timezone handling.
     */
    private static function formatWhen( string $ymd, string $hms ): array
    {
        if ( ! preg_match( '/^\d{8}$/', $ymd ) ) {
            return [ '', '', '' ];
        }
        $year  = (int) substr( $ymd, 0, 4 );
        $month = (int) substr( $ymd, 4, 2 );
        $day   = (int) substr( $ymd, 6, 2 );

        $ts = mktime( 12, 0, 0, $month, $day, $year );
        if ( $ts === false ) {
            return [ '', '', '' ];
        }
        $datePill = gmdate( 'M j', $ts );
        $dayLabel = gmdate( 'D', $ts );

        $timeLabel = '';
        if ( preg_match( '/^(\d{2}):(\d{2})/', $hms, $m ) ) {
            $h = (int) $m[1];
            $mn = (int) $m[2];
            $ampm = $h >= 12 ? 'PM' : 'AM';
            $h12  = $h % 12 === 0 ? 12 : $h % 12;
            $timeLabel = sprintf( '%d:%02d %s ET', $h12, $mn, $ampm );
        }
        return [ $datePill, $dayLabel, $timeLabel ];
    }
}
