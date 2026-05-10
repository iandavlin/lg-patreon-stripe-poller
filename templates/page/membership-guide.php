<?php
/**
 * Membership Guide page template.
 *
 * Variables in scope (set by MembershipGuide::render):
 *   $isMember        bool
 *   $bodyClass       string ('is-member' | 'is-anon')
 *   $previewCards    array of [ thumb_id, kind, title, url ]
 *   $elders          array of [ name, avatar_id, ig_url, bio, archive_url, bio_page_id ]
 *   $loothalongUrl   string
 *   $feedVideoUrl    string
 *   $feedPosterUrl   string
 *   $archiveDemoUrl  string
 *   $forumsDemoUrl   string
 *   $forumsImageUrl  string
 *   $forumCounts     array { topics: int, replies: int }
 *   $screenshots     array of section_slug => [ attachment_id, … ]
 *   $recurringShows  array of [ title, thumb_url, archive_url ]
 */

$pickerSize = 'medium';
// Accepts either an int attachment ID or a URL string — same as MembershipGuide::resolveImage.
$thumbUrl   = function ( $value, string $size = 'medium' ): string {
    return \LGMS\Wp\MembershipGuide::resolveImage( $value, $size );
};

// Extract YouTube video ID from youtu.be/ID or ?v=ID URLs. Returns '' for non-YouTube.
$ytId = function( string $url ): string {
    if ( preg_match( '/youtu\.be\/([a-zA-Z0-9_-]{11})/', $url, $m ) ) return $m[1];
    if ( preg_match( '/[?&]v=([a-zA-Z0-9_-]{11})/', $url, $m ) ) return $m[1];
    return '';
};

// Render a demo clip or screenshot.
//   YouTube URL  → yt thumbnail poster + iframe in lightbox
//   Image URL    → static screenshot, lightboxes to full size on click (no play button)
//   MP4 URL      → video preload=none + video in lightbox
$demoClip = function( string $url, string $label, string $extraStyle = '' ) use ( $ytId ): void {
    if ( $url === '' ) return;
    $yt       = $ytId( $url );
    $ext      = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
    $isImage  = in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ], true );

    if ( $yt ) {
        $dataAttr = 'data-youtube="' . esc_attr( $yt ) . '"';
    } elseif ( $isImage ) {
        $dataAttr = 'data-image="' . esc_attr( $url ) . '"';
    } else {
        $dataAttr = 'data-video="' . esc_attr( $url ) . '"';
    }

    $styleAttr = $extraStyle ? ' style="' . esc_attr( $extraStyle ) . '"' : '';
    echo '<div class="demo-clip' . ( $isImage ? ' demo-clip--image' : '' ) . '" '
        . $dataAttr . ' data-label="' . esc_attr( $label ) . '"' . $styleAttr . '>';

    if ( $yt ) {
        $thumb = 'https://img.youtube.com/vi/' . $yt . '/hqdefault.jpg';
        echo '<img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $label ) . '" loading="lazy">';
        echo '<div class="play-btn"><div class="play-icon"></div><span class="play-label">Watch demo</span></div>';
    } elseif ( $isImage ) {
        echo '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $label ) . '" loading="lazy">';
        // subtle zoom-in hint instead of play button
        echo '<div class="play-btn" style="opacity:0;"></div>';
    } else {
        echo '<video preload="none" muted playsinline><source src="' . esc_url( $url ) . '" type="video/mp4"></video>';
        echo '<div class="play-btn"><div class="play-icon"></div><span class="play-label">Watch demo</span></div>';
    }
    echo '</div>';
};
?>
<style>
.lgms-mg { --cream:#FAF6EE; --sand:#EAE5DC; --bg:#e8e2d8; --dark:#2B2318; --ink:#5C4E3A; --amber:#ECB351; --amber-d:#C68A1E; --green:#87986A; --green-l:#D4E0B8; }
.lgms-mg * { box-sizing:border-box; }
.lgms-mg { background:var(--bg); padding:40px 16px; font-family:Arial,Helvetica,sans-serif; line-height:1.6; color:var(--ink); }
.lgms-mg .wrap { max-width:940px; margin:0 auto; background:var(--cream); border-radius:8px; overflow:hidden; box-shadow:0 2px 16px rgba(0,0,0,0.10); }
.lgms-mg .hero { background:var(--dark); color:var(--cream); padding:56px 48px 48px; text-align:center; }
.lgms-mg .hero img.logo { width:220px; max-width:60%; height:auto; margin-bottom:28px; }
.lgms-mg .hero h1 { font-family:Georgia,serif; font-size:38px; font-weight:700; margin:0 0 12px; color:var(--amber); line-height:1.15; }
.lgms-mg .hero .lede { font-size:18px; line-height:1.6; color:#d8cfc0; max-width:560px; margin:0 auto; }
.lgms-mg .toc { background:var(--amber); padding:14px 24px; text-align:center; position:sticky; top:32px; z-index:10; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
.lgms-mg .toc a { display:inline-block; margin:4px 12px; color:var(--dark); font-size:13px; font-weight:700; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em; }
.lgms-mg .section { padding:48px 48px 40px; border-bottom:1px solid var(--sand); }
.lgms-mg .section:last-of-type { border-bottom:none; }
.lgms-mg .section h2 { font-family:Georgia,serif; font-size:30px; color:var(--dark); margin:0 0 6px; display:flex; align-items:center; gap:14px; }
.lgms-mg .section h2 .icon { width:48px; height:48px; background:var(--sand); border-radius:10px; display:inline-flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; }
.lgms-mg .section .subtitle { font-size:15px; color:var(--amber-d); font-weight:600; margin:0 0 18px 62px; text-transform:uppercase; letter-spacing:0.04em; }
.lgms-mg .section .body { font-size:16px; color:var(--ink); line-height:1.75; }
.lgms-mg .section .body p { margin:0 0 14px; }
.lgms-mg .section .body strong { color:var(--dark); }
.lgms-mg .steps { counter-reset:step; list-style:none; padding:0; margin:18px 0; }
.lgms-mg .steps li { counter-increment:step; position:relative; padding-left:44px; margin:0 0 12px; line-height:1.6; }
.lgms-mg .steps li::before { content:counter(step); position:absolute; left:0; top:0; width:30px; height:30px; background:var(--amber); color:var(--dark); border-radius:50%; text-align:center; line-height:30px; font-weight:700; font-family:Georgia,serif; }
.lgms-mg .callout { background:var(--green-l); border-left:4px solid var(--green); padding:14px 18px; margin:18px 0; border-radius:0 6px 6px 0; font-size:15px; }
.lgms-mg .cta-link { display:inline-block; margin-top:8px; color:var(--green); font-weight:700; font-size:14px; text-decoration:none; text-transform:uppercase; letter-spacing:0.06em; }
/* Sliders */
.lgms-mg .upcoming, .lgms-mg .gallery, .lgms-mg .public-gallery, .lgms-mg .elders { display:flex; gap:12px; margin:18px 0; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; padding-bottom:10px; }
.lgms-mg .upcoming::-webkit-scrollbar, .lgms-mg .gallery::-webkit-scrollbar, .lgms-mg .public-gallery::-webkit-scrollbar, .lgms-mg .elders::-webkit-scrollbar { height:8px; }
.lgms-mg .upcoming::-webkit-scrollbar-track, .lgms-mg .gallery::-webkit-scrollbar-track, .lgms-mg .public-gallery::-webkit-scrollbar-track, .lgms-mg .elders::-webkit-scrollbar-track { background:var(--sand); border-radius:4px; }
.lgms-mg .upcoming::-webkit-scrollbar-thumb, .lgms-mg .gallery::-webkit-scrollbar-thumb, .lgms-mg .public-gallery::-webkit-scrollbar-thumb, .lgms-mg .elders::-webkit-scrollbar-thumb { background:var(--amber); border-radius:4px; }
.lgms-mg .upcoming > * { scroll-snap-align:start; flex:0 0 260px; }
.lgms-mg .public-gallery > * { scroll-snap-align:start; flex:0 0 220px; }
.lgms-mg .elders > * { scroll-snap-align:start; flex:0 0 150px; }
.lgms-mg .gallery > * { scroll-snap-align:start; flex-shrink:0; }
/* Event cards */
.lgms-mg .ev-card { background:var(--cream); border:1px solid var(--sand); border-radius:8px; overflow:hidden; text-decoration:none; color:inherit; display:block; transition:transform 0.15s,box-shadow 0.15s; }
.lgms-mg .ev-card:hover { transform:translateY(-2px); box-shadow:0 6px 14px rgba(0,0,0,0.08); }
.lgms-mg .ev-thumb { aspect-ratio:16/10; background:var(--sand) center/cover no-repeat; display:flex; align-items:center; justify-content:center; color:#8a7e69; font-size:12px; padding:10px; border-bottom:3px solid var(--amber); position:relative; }
.lgms-mg .ev-date-pill { position:absolute; top:8px; left:8px; background:rgba(43,35,24,0.92); color:var(--amber); font-size:11px; font-weight:700; padding:4px 9px; border-radius:4px; text-transform:uppercase; letter-spacing:0.06em; }
.lgms-mg .ev-body { padding:12px 14px 14px; }
.lgms-mg .ev-when { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:var(--amber-d); font-weight:700; margin-bottom:4px; }
.lgms-mg .ev-title { font-family:Georgia,serif; font-size:15px; color:var(--dark); line-height:1.3; margin:0 0 6px; }
.lgms-mg .ev-meta { font-size:12px; color:var(--ink); }
/* Public-content cards */
.lgms-mg .pg-card { background:var(--cream); border:1px solid var(--sand); border-radius:10px; overflow:hidden; text-decoration:none; color:inherit; display:block; transition:transform 0.15s,box-shadow 0.15s; }
.lgms-mg .pg-card:hover { transform:translateY(-2px); box-shadow:0 8px 18px rgba(0,0,0,0.08); }
.lgms-mg .pg-card .thumb { aspect-ratio:16/10; background:var(--sand) center/cover no-repeat; display:flex; align-items:center; justify-content:center; color:#8a7e69; font-size:12px; }
.lgms-mg .pg-card .meta { padding:10px 14px 14px; }
.lgms-mg .pg-card .kind { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:var(--amber-d); font-weight:700; }
.lgms-mg .pg-card .title { font-family:Georgia,serif; font-size:15px; color:var(--dark); margin:4px 0 0; line-height:1.3; }
/* "Recurring Shows" sub-section + 16:9 carousel */
.lgms-mg .recur-sub { display:flex; align-items:center; gap:12px; margin:36px 0 4px; }
.lgms-mg .recur-sub .subicon { width:40px; height:40px; background:var(--sand); border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.lgms-mg .recur-sub h3 { font-family:Georgia,serif; font-size:22px; color:var(--dark); margin:0; }
.lgms-mg .recur-block { margin:18px 0 28px; padding:18px 0 0; }
.lgms-mg .recur-block-head { display:flex; align-items:center; gap:10px; margin:0 0 8px; }
.lgms-mg .recur-block-head .subicon { width:36px; height:36px; background:var(--sand); border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.lgms-mg .recur-block-head h4 { font-family:Georgia,serif; font-size:18px; color:var(--dark); margin:0; }
.lgms-mg .show-carousel { display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; padding-bottom:10px; margin:14px 0 0; }
.lgms-mg .show-carousel::-webkit-scrollbar { height:8px; }
.lgms-mg .show-carousel::-webkit-scrollbar-track { background:var(--sand); border-radius:4px; }
.lgms-mg .show-carousel::-webkit-scrollbar-thumb { background:var(--amber); border-radius:4px; }
.lgms-mg .show-carousel > * { scroll-snap-align:start; flex:0 0 280px; }
.lgms-mg .show-card { position:relative; display:block; background:var(--cream); border:1px solid var(--sand); border-radius:10px; overflow:hidden; text-decoration:none; color:inherit; transition:transform 0.15s, box-shadow 0.15s; }
.lgms-mg .show-card:hover { transform:translateY(-2px); box-shadow:0 6px 14px rgba(0,0,0,0.08); }
.lgms-mg .show-card .show-thumb { aspect-ratio:16/9; background:var(--sand) center/cover no-repeat; display:flex; align-items:center; justify-content:center; color:#8a7e69; font-size:12px; }
.lgms-mg .show-card .show-meta { padding:10px 14px 14px; }
.lgms-mg .show-card .show-title { font-family:Georgia,serif; font-size:15px; color:var(--dark); margin:0; line-height:1.3; }
.lgms-mg .show-card.empty { background:transparent; border-style:dashed; }

/* Elder cards. Classnames are namespaced (lgms-elder-*) to avoid collisions
   with BuddyBoss's .avatar/.name rules and any global lazy-loader that
   targets .avatar (which was zero-ing out our dimensions). */
.lgms-mg .elder { text-align:center; text-decoration:none; color:inherit; background:var(--cream); border:1px solid var(--sand); border-radius:10px; padding:14px 8px 12px; transition:transform 0.15s; }
.lgms-mg .elder:hover { transform:translateY(-2px); }
.lgms-mg .elder .lgms-elder-pic  { display:block; width:72px; height:72px; min-width:72px; min-height:72px; border-radius:50%; background-color:var(--sand); background-position:center; background-size:cover; background-repeat:no-repeat; margin:0 auto 8px; }
.lgms-mg .elder .lgms-elder-name { font-family:Georgia,serif; font-size:14px; font-weight:700; color:var(--dark); display:block; margin-bottom:4px; }
.lgms-mg .elder .lgms-elder-cta  { font-size:11px; color:var(--green); text-transform:uppercase; letter-spacing:0.06em; font-weight:700; }
/* Screenshot thumbs */
.lgms-mg .gallery .shot { background:var(--sand); border:1px solid #d6cfc1; border-radius:6px; overflow:hidden; height:84px; aspect-ratio:16/10; display:flex; align-items:center; justify-content:center; cursor:zoom-in; transition:transform 0.15s,box-shadow 0.15s; }
.lgms-mg .gallery .shot:hover { transform:translateY(-2px); box-shadow:0 4px 10px rgba(0,0,0,0.10); }
.lgms-mg .gallery .shot img { width:100%; height:100%; object-fit:cover; display:block; }
/* Loothalong gated */
.lgms-mg .gated { background:var(--dark); color:var(--cream); padding:22px; border-radius:8px; text-align:center; margin:18px 0; font-size:15px; }
.lgms-mg .gated.guest-state { background:#3a2f24; }
.lgms-mg .gated a.btn { display:inline-block; margin-top:12px; background:var(--amber); color:var(--dark); font-weight:700; padding:10px 22px; border-radius:6px; text-decoration:none; }
.lgms-mg .gated.guest-state a.btn { background:var(--green); }
/* Demo row — instructions left, clip right */
.lgms-mg .demo-row { display:grid; grid-template-columns:1fr 1fr; gap:0; align-items:stretch; margin:18px 0; border:1px solid var(--sand); border-radius:10px; overflow:hidden; }
.lgms-mg .demo-row-text { display:flex; flex-direction:column; gap:12px; padding:22px 24px; }
.lgms-mg .demo-row-text > *:first-child { margin-top:0; }
.lgms-mg .demo-row .demo-clip { border-radius:0; border:none; }
/* Demo clips */
.lgms-mg .demo-clip { position:relative; background:var(--dark); border-radius:10px; overflow:hidden; cursor:pointer; margin:0; }
.lgms-mg .demo-clip--image { cursor:zoom-in; }
.lgms-mg .demo-clip img, .lgms-mg .demo-clip video { width:100%; height:auto; display:block; pointer-events:none; }
.lgms-mg .demo-clip .play-btn { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; background:rgba(20,15,10,0.38); transition:background 0.18s; }
.lgms-mg .demo-clip:hover .play-btn { background:rgba(20,15,10,0.52); }
.lgms-mg .demo-clip .play-icon { width:44px; height:44px; border-radius:50%; background:rgba(255,255,255,0.92); display:flex; align-items:center; justify-content:center; }
.lgms-mg .demo-clip .play-icon::after { content:''; border-style:solid; border-width:8px 0 8px 14px; border-color:transparent transparent transparent var(--dark); margin-left:3px; }
.lgms-mg .demo-clip .play-label { color:#fff; font-size:12px; font-weight:600; letter-spacing:0.04em; text-shadow:0 1px 4px rgba(0,0,0,0.5); }
@media (max-width:640px) { .lgms-mg .demo-row { grid-template-columns:1fr; } .lgms-mg .demo-row .demo-clip + .demo-row-text, .lgms-mg .demo-row .demo-row-text + .demo-clip { border-top:1px solid var(--sand); } }
/* Video lightbox */
.lgms-mg-lb video { width:100%; height:auto; display:block; border-radius:6px; }
/* Bottom join */
.lgms-mg .join { background:var(--dark); color:var(--cream); padding:56px 48px; text-align:center; }
.lgms-mg .join h3 { font-family:Georgia,serif; color:var(--amber); font-size:28px; margin:0 0 12px; }
.lgms-mg .join p { color:#d8cfc0; max-width:520px; margin:0 auto 24px; font-size:16px; }
.lgms-mg .join .btn { display:inline-block; padding:16px 44px; background:var(--amber); color:var(--dark); font-weight:800; font-size:16px; text-decoration:none; border-radius:7px; }
/* Lightbox */
.lgms-mg-lb { position:fixed; inset:0; background:rgba(20,15,10,0.90); display:none; align-items:center; justify-content:center; z-index:99999; padding:24px; cursor:zoom-out; }
.lgms-mg-lb.open { display:flex; }
.lgms-mg-lb-inner { width:min(1140px,96vw); background:var(--sand); border-radius:10px; padding:24px; cursor:default; }
.lgms-mg-lb-inner img { max-width:100%; max-height:80vh; display:block; border-radius:6px; }
/* Audience gating */
body.lgms-mg-anon   .audience-member { display:none !important; }
body.lgms-mg-member .audience-anon   { display:none !important; }
@media (max-width:600px) { .lgms-mg .wrap { margin:0; border-radius:0; } .lgms-mg .hero, .lgms-mg .section, .lgms-mg .join { padding:36px 22px; } .lgms-mg .hero h1 { font-size:28px; } .lgms-mg .section h2 { font-size:22px; } .lgms-mg .section .subtitle { margin-left:0; } .lgms-mg .toc { padding:10px 12px; overflow-x:auto; white-space:nowrap; } .lgms-mg .toc a { margin:0 8px; font-size:11px; } }
</style>

<script>
// Set body class for audience gating. Runs inline so it's in the DOM before paint.
document.body.classList.add(<?php echo $isMember ? "'lgms-mg-member'" : "'lgms-mg-anon'"; ?>);
</script>

<?php if ( current_user_can( 'manage_options' ) ) : ?>
<style>
.lgms-mg-preview-bar {
    position: fixed;
    top: 32px;
    right: 16px;
    z-index: 99998;
    background: #1a140d;
    color: #FAF6EE;
    padding: 8px 12px;
    border-radius: 8px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
    font-family: Arial, sans-serif;
    font-size: 11px;
    letter-spacing: 0.04em;
}
.lgms-mg-preview-bar strong { color: #ECB351; margin-right: 6px; }
.lgms-mg-preview-bar button {
    background: transparent;
    border: 1px solid #87986A;
    color: #FAF6EE;
    padding: 4px 10px;
    margin-left: 4px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
.lgms-mg-preview-bar button.active { background: #ECB351; color: #2B2318; border-color: #ECB351; font-weight: 700; }
.lgms-mg-preview-bar small { display:block; color:#888; margin-top:4px; font-size:10px; }
</style>
<div class="lgms-mg-preview-bar">
    <strong>ADMIN PREVIEW:</strong>
    <button id="lgms-mg-btn-anon"<?php echo $isMember ? '' : ' class="active"'; ?>>Visitor</button>
    <button id="lgms-mg-btn-member"<?php echo $isMember ? ' class="active"' : ''; ?>>Member</button>
    <small>Toggles client-side. Loothalong URL is gated server-side and won't appear when previewing as Visitor.</small>
</div>
<script>
(function(){
    function setView(mode) {
        document.body.classList.toggle('lgms-mg-anon',   mode === 'anon');
        document.body.classList.toggle('lgms-mg-member', mode === 'member');
        document.getElementById('lgms-mg-btn-anon').classList.toggle('active',   mode === 'anon');
        document.getElementById('lgms-mg-btn-member').classList.toggle('active', mode === 'member');
    }
    document.getElementById('lgms-mg-btn-anon').addEventListener('click',   function(){ setView('anon');   });
    document.getElementById('lgms-mg-btn-member').addEventListener('click', function(){ setView('member'); });
})();
</script>
<?php endif; ?>

<div class="lgms-mg">
<div class="wrap">

  <header class="hero">
    <img class="logo" src="https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png" alt="The Looth Group">
    <h1>How The Looth Group Works</h1>
    <p class="lede">A library, a forum, a calendar of live shows, and a 24/7 lounge — all built for people who do this work seriously. Here's the tour.</p>
  </header>

  <nav class="toc" aria-label="Sections">
    <?php if ( $isMember && $starterHasContent ) : ?><a href="#start-here">Start Here</a><?php endif; ?>
    <a href="#events">Events</a>
    <a href="#archive">Archive</a>
    <a href="#feed">Feed</a>
    <a href="#forums">Forums</a>
    <a href="#looths">Looths</a>
    <a href="#loothalong">Loothalong</a>
  </nav>

  <?php /* ── START HERE (member-only) ───────────────────────────── */ ?>
  <?php
    $starterHasContent = false;
    foreach ( $starterCards as $sc ) {
        if ( ($sc['title'] ?? '') !== '' || ($sc['url'] ?? '') !== '' || (int)($sc['thumb_id'] ?? 0) > 0 || ( is_string($sc['thumb_id'] ?? '') && $sc['thumb_id'] !== '' ) ) {
            $starterHasContent = true;
            break;
        }
    }
  ?>
  <?php if ( $starterHasContent ) : ?>
  <section id="start-here" class="section audience-member">
    <h2><span class="icon">&#128205;</span> Start Here</h2>
    <p class="subtitle">A few things to dig into first</p>
    <div class="body">
      <p>If you're new, these are worth your first hour.</p>
      <div class="public-gallery">
        <?php foreach ( $starterCards as $card ) :
          $u  = (string) ( $card['url'] ?? '' );
          $tu = $thumbUrl( $card['thumb_id'], 'medium' );
          if ( $u === '' && $tu === '' ) continue;
        ?>
          <a class="pg-card" href="<?php echo esc_url( $u ?: '#' ); ?>">
            <div class="thumb"<?php echo $tu ? ' style="background-image:url(' . esc_url( $tu ) . ');"' : ''; ?>>
              <?php echo $tu ? '' : '[ thumb ]'; ?>
            </div>
            <div class="meta">
              <span class="kind"><?php echo esc_html( (string) $card['kind'] ); ?></span>
              <p class="title"><?php echo esc_html( (string) $card['title'] ); ?></p>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php /* ── EVENTS ─────────────────────────────────────────────── */ ?>
  <section id="events" class="section">
    <h2><span class="icon">&#128197;</span> Live Events</h2>
    <p class="subtitle">Recurring shows, workshops, Q&amp;As</p>
    <div class="body">
      <p>The Looth calendar runs <strong>year-round</strong>. Most weeks bring multiple live sessions — workshops, builder interviews, deep-dive Q&amp;As, and the monthly <strong>Council of Elders</strong>.</p>

      <h3 style="margin:24px 0 8px;font-family:Georgia,serif;color:var(--dark);font-size:18px;">Coming up next</h3>
      <?php echo do_shortcode( '[lg_upcoming_events count="6"]' ); ?>


      <ol class="steps">
        <li>Open the <a href="<?php echo esc_url( 'https://loothgroup.com/calendar/' ); ?>">calendar</a> to see what's coming up. RSVP for a reminder.</li>
        <li>Watch the <a href="<?php echo esc_url( home_url( '/looth-group-weekly/' ) ); ?>">weekly email</a> — every upcoming event has an <strong>Add to Calendar</strong> button right inside.</li>
        <li>Click any session to get the Zoom link — it activates 15 minutes before start time.</li>
        <li>Miss it live? Every session is recorded and added to the <a href="#archive">Archive</a> within 24 hours.</li>
      </ol>

      <?php $shots = $screenshots['events'] ?? []; if ( $shots ) : ?>
      <div class="gallery">
        <?php foreach ( $shots as $id ) : $u = $thumbUrl( $id, 'large' ); $t = $thumbUrl( $id, 'medium' ); if ( ! $u ) continue; ?>
        <div class="shot" data-full="<?php echo esc_attr( $u ); ?>"><img src="<?php echo esc_url( $t ); ?>" alt=""></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <a href="<?php echo esc_url( 'https://loothgroup.com/calendar/' ); ?>" class="cta-link">Open the calendar &rarr;</a>

      <?php /* ---- Recurring Shows sub-section ---- */ ?>
      <div class="recur-sub">
        <span class="subicon">&#127916;</span>
        <h3>Recurring Shows</h3>
      </div>

      <?php /* ---- Council of Elders block ---- */ ?>
      <div class="recur-block">
        <div class="recur-block-head">
          <span class="subicon">&#129497;</span>
          <h4>The Council of Elders</h4>
        </div>
        <p style="margin:0 0 12px;">Once a month, our most experienced makers sit down to answer member questions. Submit yours from any forum thread (just tick the box) and they'll work through them live.</p>
        <div class="elders">
        <?php
        $isAdmin = current_user_can( 'manage_options' );
        foreach ( $elders as $i => $e ) :
          // Pull avatar from BB member profile first (matches by display_name);
          // falls back to admin-configured avatar_id if no BB profile match.
          $au      = \LGMS\Wp\MembershipGuide::getElderAvatar( $e, 'thumb' );
          $ig      = (string) ( $e['ig_url'] ?? '' );
          $bioSlug = 'elder-' . sanitize_title( (string) $e['name'] );
          $bioUrl  = home_url( '/' . $bioSlug . '/' );
        ?>
          <a class="elder" href="<?php echo esc_url( $bioUrl ); ?>" target="_blank" rel="noopener">
            <?php if ( $isAdmin ) : ?>
              <button type="button" class="lgms-elder-edit-btn" data-index="<?php echo (int) $i; ?>" title="Edit profile" aria-label="Edit profile">&#9998;</button>
            <?php endif; ?>
            <span class="lgms-elder-pic"<?php echo $au ? ' style="background-image:url(' . esc_url( $au ) . ');"' : ''; ?>></span>
            <span class="lgms-elder-name"><?php echo esc_html( (string) $e['name'] ); ?></span>
            <span class="lgms-elder-cta">View bio</span>
          </a>
        <?php endforeach; ?>
        </div>
      </div><?php /* end .recur-block (Council of Elders) */ ?>

      <?php /* ---- Other recurring shows: 16:9 carousel ---- */ ?>
      <?php if ( ! empty( $recurringShows ) || $isAdmin ) : ?>
      <div class="recur-block">
        <div class="recur-block-head">
          <span class="subicon">&#127909;</span>
          <h4>Other Recurring Shows</h4>
        </div>
        <div class="show-carousel">
          <?php foreach ( $recurringShows as $i => $s ) :
              $sUrl   = (string) ( $s['archive_url'] ?? '' );
              $sThumb = (string) ( $s['thumb_url']   ?? '' );
              $sTitle = (string) ( $s['title']       ?? '' );
          ?>
          <a class="show-card" href="<?php echo esc_url( $sUrl ?: '#' ); ?>">
            <?php if ( $isAdmin ) : ?>
              <button type="button" class="lgms-show-edit-btn" data-index="<?php echo (int) $i; ?>" title="Edit show" aria-label="Edit show">&#9998;</button>
            <?php endif; ?>
            <div class="show-thumb"<?php echo $sThumb ? ' style="background-image:url(' . esc_url( $sThumb ) . ');"' : ''; ?>>
              <?php echo $sThumb ? '' : '[ thumb ]'; ?>
            </div>
            <div class="show-meta">
              <p class="show-title"><?php echo esc_html( $sTitle ?: 'Untitled show' ); ?></p>
            </div>
          </a>
          <?php endforeach; ?>

          <?php if ( $isAdmin ) : ?>
          <div class="show-card empty">
            <button type="button" class="lgms-show-add-card lgms-show-edit-btn" data-index="-1" style="position:static;width:100%;height:100%;border-radius:10px;background:transparent;color:#888;border:none;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;cursor:pointer;">+ Add show</button>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php
        \LGMS\Wp\MembershipGuide::renderEditModal( $elders );
        \LGMS\Wp\MembershipGuide::renderShowEditModal( $recurringShows );
      ?>
    </div>
  </section>

  <?php /* ── ARCHIVE ────────────────────────────────────────────── */ ?>
  <section id="archive" class="section">
    <h2><span class="icon">&#127916;</span> The Archive</h2>
    <p class="subtitle">Hundreds of videos, articles, loothprints, and documents</p>
    <div class="body">
      <p>Every recording, document, and Loothprint we've ever made — searchable by topic, format, and author. Dan Erlewine, Doug Proper, Michael Bashkin, and dozens more.</p>
      <p><strong>Every live event ends up here.</strong> Workshops, builder interviews, Council Q&amp;As — they're all recorded and added to the Archive within 24 hours of airing, so nothing's ever truly missed.</p>

      <?php /* Anon-only mini preview embedded in Archive — sales funnel */ ?>
      <div class="audience-anon" style="background:var(--cream);border:1px dashed var(--amber);border-radius:10px;padding:16px 18px 6px;margin:18px 0;">
        <p style="margin:0 0 4px;font-family:Georgia,serif;color:var(--dark);font-size:17px;font-weight:700;">A taste of what's inside</p>
        <p style="margin:0 0 12px;font-size:13px;color:#888;">A handful of articles &amp; shows we've made public — no sign-in required.</p>
        <div class="public-gallery">
          <?php foreach ( $previewCards as $card ) :
            $u  = (string) ( $card['url'] ?? '' );
            $tu = $thumbUrl( $card['thumb_id'], 'medium' );
            if ( $u === '' && $tu === '' ) continue;
          ?>
            <a class="pg-card" href="<?php echo esc_url( $u ?: '#' ); ?>">
              <div class="thumb"<?php echo $tu ? ' style="background-image:url(' . esc_url( $tu ) . ');"' : ''; ?>>
                <?php echo $tu ? '' : '[ thumb ]'; ?>
              </div>
              <div class="meta">
                <span class="kind"><?php echo esc_html( (string) $card['kind'] ); ?></span>
                <p class="title"><?php echo esc_html( (string) $card['title'] ); ?></p>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>


      <?php if ( $archiveDemoUrl !== '' ) : ?><div class="demo-row"><?php endif; ?>
      <div class="demo-row-text">
        <h3 style="margin:0 0 8px;font-family:Georgia,serif;color:var(--dark);font-size:18px;">&#128269; Search &amp; filter</h3>
        <ul style="margin:0;padding-left:18px;line-height:1.8;font-size:15px;">
          <li>Type into the search bar to find anything by keyword, title, or author name.</li>
          <li>Layer filters on top — Author, Topic, Tag, and Format all stack together.</li>
          <li>Hit <strong>&times;</strong> on any filter chip to drop it and broaden the results.</li>
        </ul>
      </div><?php /* end demo-row-text */ ?>
      <?php if ( $archiveDemoUrl !== '' ) : ?>
        <?php $demoClip( $archiveDemoUrl, 'How to use the Archive' ); ?>
      </div><?php /* end demo-row */ ?>
      <?php endif; ?>

      <?php $shots = $screenshots['archive'] ?? []; if ( $shots ) : ?>
      <div class="gallery">
        <?php foreach ( $shots as $id ) : $u = $thumbUrl( $id, 'large' ); $t = $thumbUrl( $id, 'medium' ); if ( ! $u ) continue; ?>
        <div class="shot" data-full="<?php echo esc_attr( $u ); ?>"><img src="<?php echo esc_url( $t ); ?>" alt=""></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <a href="<?php echo esc_url( 'https://loothgroup.com/archive/' ); ?>" class="cta-link">Browse the archive &rarr;</a>
    </div>
  </section>

  <?php /* ── FEED ───────────────────────────────────────────────── */ ?>
  <section id="feed" class="section">
    <h2><span class="icon">&#128240;</span> The Feed</h2>
    <p class="subtitle">What's new, all in one stream</p>
    <div class="body">
      <p>The Feed is your home base after sign-in. New archive uploads, fresh forum threads, event reminders, and activity from the people you follow — all in chronological order.</p>
      <?php $demoClip( $feedVideoUrl, 'The Feed', 'max-width:55%;' ); ?>
      <a href="<?php echo esc_url( 'https://loothgroup.com/activity/' ); ?>" class="cta-link">Open your feed &rarr;</a>
    </div>
  </section>

  <?php /* ── FORUMS ─────────────────────────────────────────────── */ ?>
  <section id="forums" class="section">
    <h2><span class="icon">&#127963;</span> The Forums</h2>
    <p class="subtitle">Discipline-specific conversation, anonymous if you want</p>
    <div class="body">

      <?php /* Row 1: screenshot left, description + count right */ ?>
      <?php if ( $forumsImageUrl !== '' ) : ?>
      <div class="demo-row" style="margin-bottom:28px;">
        <?php $demoClip( $forumsImageUrl, 'The Forums' ); ?>
        <div class="demo-row-text" style="justify-content:center;">
          <p>Organized by discipline — <strong>Repair, Builds, Tools, Business, Marketplace</strong>, and more. Post under your own name or anonymously — only moderators see who you are.</p>
          <?php if ( $forumCounts['topics'] > 0 ) : ?>
          <p style="font-size:22px;font-family:Georgia,serif;color:var(--dark);margin:0;"><strong><?php echo number_format( $forumCounts['topics'] ); ?></strong> <span style="font-size:15px;font-family:Arial,sans-serif;font-weight:400;color:var(--ink);">threads</span> &nbsp; <strong><?php echo number_format( $forumCounts['replies'] ); ?></strong> <span style="font-size:15px;font-family:Arial,sans-serif;font-weight:400;color:var(--ink);">replies</span> <span style="font-size:13px;color:#999;">— and counting</span></p>
          <?php endif; ?>
        </div>
      </div>
      <?php else : ?>
      <p>The forums are organized by discipline — <strong>Repair, Builds, Tools, Business, Marketplace</strong>, and more. Post under your own name, or check the "post anonymously" box and only the moderators will see who you are.</p>
      <?php if ( $forumCounts['topics'] > 0 ) : ?>
      <p><strong><?php echo number_format( $forumCounts['topics'] ); ?> threads</strong> and <strong><?php echo number_format( $forumCounts['replies'] ); ?> replies</strong> — and counting.</p>
      <?php endif; ?>
      <?php endif; ?>

      <?php /* Row 2: steps left, how-to video right */ ?>
      <?php if ( $forumsDemoUrl !== '' ) : ?><div class="demo-row"><?php endif; ?>
      <div class="demo-row-text">
        <ol class="steps">
          <li>Pick the right sub-forum for your topic.</li>
          <li>Write your post — attach photos, drawings, or files.</li>
          <li>Tick <strong>Post anonymously</strong> if you'd rather not put your name on it — only moderators can see who you are. (Please no treatises on ancient aliens.)</li>
          <li>Tick <strong>Submit to the Council of Elders</strong> if you want senior makers to weigh in at the next monthly Q&amp;A.</li>
          <li>Tick <strong>Flag for the <a href="<?php echo esc_url( home_url( '/looth-group-weekly/' ) ); ?>">weekly email</a></strong> if you think your thread deserves wider eyes.</li>
        </ol>
      </div>
      <?php if ( $forumsDemoUrl !== '' ) : ?>
        <?php $demoClip( $forumsDemoUrl, 'How to use the Forums' ); ?>
      </div><?php /* end demo-row */ ?>
      <?php endif; ?>

      <?php $shots = $screenshots['forums'] ?? []; if ( $shots ) : ?>
      <div class="gallery">
        <?php foreach ( $shots as $id ) : $u = $thumbUrl( $id, 'large' ); $t = $thumbUrl( $id, 'medium' ); if ( ! $u ) continue; ?>
        <div class="shot" data-full="<?php echo esc_attr( $u ); ?>"><img src="<?php echo esc_url( $t ); ?>" alt=""></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <a href="<?php echo esc_url( 'https://loothgroup.com/forums/' ); ?>" class="cta-link">Go to the forums &rarr;</a>
    </div>
  </section>

  <?php /* ── LOOTHS ─────────────────────────────────────────────── */ ?>
  <section id="looths" class="section">
    <h2><span class="icon">&#128101;</span> Looths — Connections &amp; Messages</h2>
    <p class="subtitle">Find your people, and talk to them privately</p>
    <div class="body">
      <p>"Looths" is how we describe the network — the people you follow, who follow you, and the private messages between you.</p>
      <div class="pictograms">
        <div class="pictogram"><span class="ico">&#128269;</span><strong>Find</strong>via the directory</div>
        <div class="pictogram"><span class="ico">&#129309;</span><strong>Connect</strong>send / accept requests</div>
        <div class="pictogram"><span class="ico">&#128172;</span><strong>DM</strong>private threads w/ photos</div>
      </div>
      <?php $shots = $screenshots['looths'] ?? []; if ( $shots ) : ?>
      <div class="gallery">
        <?php foreach ( $shots as $id ) : $u = $thumbUrl( $id, 'large' ); $t = $thumbUrl( $id, 'medium' ); if ( ! $u ) continue; ?>
        <div class="shot" data-full="<?php echo esc_attr( $u ); ?>"><img src="<?php echo esc_url( $t ); ?>" alt=""></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <a href="<?php echo esc_url( 'https://loothgroup.com/members/' ); ?>" class="cta-link">Browse the directory &rarr;</a>
    </div>
  </section>

  <?php /* ── LOOTHALONG ─────────────────────────────────────────── */ ?>
  <section id="loothalong" class="section">
    <h2><span class="icon">&#127911;</span> Loothalong — 24/7 Open Channel</h2>
    <p class="subtitle">A Zoom room that's always open</p>
    <div class="body">
      <p>Loothalong is a <strong>24-hour-a-day Zoom room</strong> for working alongside other Looth members. Drop in while you're at the bench, leave a tab open in the background, ask the room a quick question.</p>
      <div class="callout"><strong>How to use it:</strong> Open the link, mute your mic if you're not talking, and get to work.</div>

      <?php if ( $isMember ) : ?>
        <?php if ( $loothalongUrl !== '' ) : ?>
          <div class="gated">
            Loothalong runs 24/7 on Zoom.<br>
            <a class="btn" href="<?php echo esc_url( $loothalongUrl ); ?>" target="_blank" rel="noopener">Join the room &rarr;</a>
          </div>
        <?php else : ?>
          <div class="gated"><em>Zoom URL not yet configured. Set it in Settings → Membership Guide.</em></div>
        <?php endif; ?>
      <?php else : ?>
        <div class="gated guest-state">
          Loothalong is a member benefit. Join to get the link.<br>
          <a class="btn" href="<?php echo esc_url( 'https://loothgroup.com/lgjoin/' ); ?>">See the plans &rarr;</a>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <?php /* ── BOTTOM JOIN (anon only) ────────────────────────────── */ ?>
  <section class="join audience-anon">
    <h3>Not a member yet?</h3>
    <p>The Archive, Forums, Looths, and Loothalong are all member-only. Live events stream to the public, but recordings and back-catalog need a membership.</p>
    <a class="btn" href="<?php echo esc_url( 'https://loothgroup.com/lgjoin/' ); ?>">See the plans &rarr;</a>
  </section>

</div>

<div class="lgms-mg-lb" id="lgms-mg-lb"><div class="lgms-mg-lb-inner" id="lgms-mg-lb-inner"></div></div>
</div>

<script>
(function(){
  var lb    = document.getElementById('lgms-mg-lb');
  var inner = document.getElementById('lgms-mg-lb-inner');

  function openLb(html) {
    inner.innerHTML = html;
    lb.classList.add('open');
  }
  function closeLb() {
    // Pause any playing video before destroying
    var v = inner.querySelector('video');
    if (v) { v.pause(); }
    inner.innerHTML = '';
    lb.classList.remove('open');
  }

  // Screenshot thumbs
  document.querySelectorAll('.lgms-mg .gallery .shot').forEach(function(el){
    el.addEventListener('click', function(){
      var full = el.getAttribute('data-full');
      openLb(full ? '<img src="' + full + '" alt="">' : '');
    });
  });

  // Demo clips — YouTube iframe or MP4 video
  document.querySelectorAll('.lgms-mg .demo-clip').forEach(function(el){
    el.addEventListener('click', function(){
      var label    = el.getAttribute('data-label') || '';
      var labelHtml = label ? '<p style="margin:10px 0 0;font-size:13px;color:var(--ink);font-weight:600;">' + label + '</p>' : '';
      var ytId    = el.getAttribute('data-youtube');
      var imgSrc  = el.getAttribute('data-image');
      var vidSrc  = el.getAttribute('data-video');
      if ( ytId ) {
        openLb('<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:6px;">' +
               '<iframe src="https://www.youtube.com/embed/' + ytId + '?autoplay=1&rel=0&modestbranding=1" ' +
               'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" ' +
               'allow="autoplay; fullscreen" allowfullscreen></iframe></div>' + labelHtml);
      } else if ( imgSrc ) {
        openLb('<img src="' + imgSrc + '" alt="" style="width:100%;height:auto;display:block;border-radius:6px;">' + labelHtml);
      } else {
        openLb('<video src="' + vidSrc + '" controls autoplay playsinline style="max-height:80vh;width:100%;border-radius:6px;"></video>' + labelHtml);
      }
    });
  });

  lb.addEventListener('click', function(e){ if (e.target === lb) closeLb(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeLb(); });
})();
</script>
