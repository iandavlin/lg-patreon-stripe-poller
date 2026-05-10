<?php
/**
 * Admin "View As" floating button.
 *
 * Drops a small unobtrusive pill at the bottom-right of every front-end page
 * so an admin can return to admin view in one click.
 *
 * Detection order (first match wins):
 *
 *   1. BuddyBoss "View As" / Member Switching — bp_current_member_switched()
 *      returns the original admin WP_User if you've switched into a member.
 *      Click → BP_Core_Members_Switching::switch_back_url() (action=switch_to_olduser).
 *
 *   2. User Switching plugin — current_user_switched() does the same thing.
 *      Click → user_switching::switch_back_url().
 *
 *   3. Plain admin browsing the front-end — manage_options capability.
 *      Click → /wp-admin/.
 *
 * Hides on /wp-admin, login screens, customizer, AJAX. No DB writes, no deps.
 *
 * Install: paste into Code Snippets ("Run snippet everywhere" or front-end),
 * or drop into wp-content/mu-plugins/ as a standalone .php file.
 */

add_action( 'wp_footer', function () {

    // Skip on admin / login / customizer / AJAX.
    if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
        return;
    }
    if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
        return;
    }

    $href  = '';
    $label = '';
    $title = '';

    // The page we're currently viewing — passed as redirect_to so switchback
    // returns the admin to this exact URL instead of bouncing to their profile.
    $currentUrl = ( is_ssl() ? 'https://' : 'http://' )
        . ( $_SERVER['HTTP_HOST'] ?? (string) parse_url( home_url(), PHP_URL_HOST ) )
        . ( $_SERVER['REQUEST_URI'] ?? '/' );

    // (1) BuddyBoss View As / Member Switching.
    if ( $href === '' && function_exists( 'bp_current_member_switched' ) ) {
        $old = bp_current_member_switched();
        if ( $old instanceof WP_User
             && class_exists( 'BP_Core_Members_Switching' )
             && method_exists( 'BP_Core_Members_Switching', 'switch_back_url' ) ) {
            $url = BP_Core_Members_Switching::switch_back_url( $old );
            if ( $url ) {
                // Override BB's default redirect (their profile) so we stay put.
                $url   = add_query_arg( 'redirect_to', urlencode( $currentUrl ), remove_query_arg( 'redirect_to', $url ) );
                $href  = $url;
                $label = '↩ Back to ' . $old->display_name;
                $title = 'Exit View As — return to ' . $old->user_login . ' (stay on this page)';
            }
        }
    }

    // (2) User Switching plugin.
    if ( $href === '' && function_exists( 'current_user_switched' )
         && class_exists( 'user_switching' )
         && method_exists( 'user_switching', 'switch_back_url' ) ) {
        $old = current_user_switched();
        if ( $old instanceof WP_User ) {
            $url = user_switching::switch_back_url( $old );
            if ( $url ) {
                $url   = add_query_arg( 'redirect_to', urlencode( $currentUrl ), remove_query_arg( 'redirect_to', $url ) );
                $href  = $url;
                $label = '↩ Back to ' . $old->display_name;
                $title = 'Switch back to ' . $old->user_login . ' (stay on this page)';
            }
        }
    }

    // (3) Plain admin → link to wp-admin.
    if ( $href === '' && current_user_can( 'manage_options' ) ) {
        $href  = admin_url();
        $label = 'Admin →';
        $title = 'Open WP admin';
    }

    if ( $href === '' ) {
        return;
    }
    ?>
    <style>
        #lgms-view-as-pill {
            position: fixed;
            bottom: 14px;
            right: 14px;
            z-index: 99990;
            background: rgba(43, 35, 24, 0.88);
            color: #ECB351;
            font: 600 12px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 999px;
            border: 1px solid rgba(236, 179, 81, 0.4);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25);
            opacity: 0.55;
            transition: opacity 0.15s, transform 0.15s, background 0.15s;
            -webkit-backdrop-filter: blur(4px);
                    backdrop-filter: blur(4px);
        }
        #lgms-view-as-pill:hover {
            opacity: 1;
            transform: translateY(-1px);
            background: #2B2318;
            color: #FAF6EE;
        }
        #lgms-view-as-pill .dot {
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #87986A;
            margin-right: 6px;
            vertical-align: 1px;
        }
        @media (max-width: 600px) {
            #lgms-view-as-pill { bottom: 10px; right: 10px; padding: 7px 12px; font-size: 11px; }
        }
        body.lgms-hide-view-as-pill #lgms-view-as-pill { display: none; }
    </style>
    <a id="lgms-view-as-pill"
       href="<?php echo esc_url( $href ); ?>"
       title="<?php echo esc_attr( $title ); ?>">
       <span class="dot"></span><?php echo esc_html( $label ); ?>
    </a>
    <script>
    (function(){
        var p = document.getElementById('lgms-view-as-pill');
        if (!p) return;
        // Middle-click = dismiss for this tab session.
        p.addEventListener('auxclick', function(e){
            if (e.button === 1) {
                e.preventDefault();
                document.body.classList.add('lgms-hide-view-as-pill');
                try { sessionStorage.setItem('lgms_hide_view_as', '1'); } catch(_){}
            }
        });
        try {
            if (sessionStorage.getItem('lgms_hide_view_as') === '1') {
                document.body.classList.add('lgms-hide-view-as-pill');
            }
        } catch(_){}
    })();
    </script>
    <?php
}, 9999 );
