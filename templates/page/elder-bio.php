<?php
/**
 * Elder bio page template.
 *
 * Variables in scope (set by MembershipGuide::renderElderBio):
 *   $elder  array { name, avatar_id, bio, ig_url, archive_url, speciality, bio_page_id }
 */
$avatarUrl  = \LGMS\Wp\MembershipGuide::getElderAvatar( $elder, 'full' );

// Locate this elder's index in the option so the edit modal can target it.
$elderIndex = -1;
foreach ( \LGMS\Wp\MembershipGuide::getElders() as $i => $row ) {
    if ( ( $row['name'] ?? '' ) === ( $elder['name'] ?? '' ) ) { $elderIndex = $i; break; }
}
$links      = \LGMS\Wp\MembershipGuide::getElderLinks( $elder );
$name       = (string) ( $elder['name'] ?? '' );
$bio        = (string) ( $elder['bio'] ?? '' );
$speciality = trim( (string) ( $elder['speciality'] ?? '' ) );
if ( $speciality === '' ) $speciality = 'Master maker & senior mentor';
$igUrl      = $links['instagram']   ?? '';
$fbUrl      = $links['facebook']    ?? '';
$twUrl      = $links['twitter']     ?? '';
$ytUrl      = $links['youtube']     ?? '';
$webUrl     = $links['website']     ?? '';
$archiveUrl = $links['archive_url'] ?? '';
$profileUrl = $links['profile_url'] ?? '';

// SVG icon set — simple, single-color (currentColor), 20x20 base.
$icons = [
    'instagram' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>',
    'facebook'  => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M22 12.07C22 6.51 17.52 2 12 2S2 6.51 2 12.07c0 5.02 3.66 9.18 8.44 9.93v-7.02H7.9v-2.91h2.54V9.86c0-2.51 1.49-3.9 3.78-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.45 2.91h-2.33V22c4.78-.75 8.43-4.91 8.43-9.93z"/></svg>',
    'youtube'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.5 12 3.5 12 3.5s-7.5 0-9.4.6A3 3 0 0 0 .5 6.2C0 8.1 0 12 0 12s0 3.9.5 5.8a3 3 0 0 0 2.1 2.1c1.9.6 9.4.6 9.4.6s7.5 0 9.4-.6a3 3 0 0 0 2.1-2.1c.5-1.9.5-5.8.5-5.8s0-3.9-.5-5.8zM9.6 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg>',
    'website'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
    'twitter'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
    'reddit'    => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M22 12.14a2.14 2.14 0 0 0-3.62-1.55 10.5 10.5 0 0 0-5.71-1.81l1-4.59 3.18.71a1.54 1.54 0 1 0 .15-.92l-3.55-.79a.46.46 0 0 0-.55.35l-1.1 5.21A10.66 10.66 0 0 0 5.62 10.59 2.14 2.14 0 1 0 3 13.93a4.21 4.21 0 0 0-.05.65c0 3.31 3.85 6 8.6 6s8.6-2.69 8.6-6a4.21 4.21 0 0 0-.05-.65A2.14 2.14 0 0 0 22 12.14zM7 13.7a1.43 1.43 0 1 1 1.43 1.43A1.43 1.43 0 0 1 7 13.7zm8.46 4.07a4.91 4.91 0 0 1-3.46 1.07 4.91 4.91 0 0 1-3.46-1.07.36.36 0 1 1 .51-.51 4.21 4.21 0 0 0 2.95.86 4.21 4.21 0 0 0 2.95-.86.36.36 0 0 1 .51.51zm-.32-2.64a1.43 1.43 0 1 1 1.43-1.43 1.43 1.43 0 0 1-1.43 1.43z"/></svg>',
    'profile'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'archive'   => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
];
?>
<style>
.lgms-eb { --cream:#FAF6EE; --sand:#EAE5DC; --bg:#e8e2d8; --dark:#2B2318; --ink:#5C4E3A; --amber:#ECB351; --amber-d:#C68A1E; --green:#87986A; --green-l:#D4E0B8; }
.lgms-eb * { box-sizing:border-box; }
.lgms-eb { background:var(--bg); padding:40px 16px 64px; font-family:Arial,Helvetica,sans-serif; line-height:1.6; color:var(--ink); }
.lgms-eb .wrap { max-width:760px; margin:0 auto; background:var(--cream); border-radius:8px; overflow:hidden; box-shadow:0 2px 16px rgba(0,0,0,0.10); }

/* Hero */
.lgms-eb .hero { background:var(--dark); padding:48px 48px 36px; display:flex; align-items:center; gap:36px; position:relative; }
.lgms-eb .hero .avatar { width:120px; height:120px; border-radius:50%; background:var(--sand) center/cover no-repeat; flex-shrink:0; border:3px solid var(--amber); }
.lgms-eb .hero .hero-text { flex:1; min-width:0; }
.lgms-eb .hero .overline { font-size:11px; text-transform:uppercase; letter-spacing:0.12em; color:var(--amber); font-weight:700; margin:0 0 8px; }
.lgms-eb .hero h1 { font-family:Georgia,serif; font-size:32px; font-weight:700; color:var(--cream); margin:0 0 6px; line-height:1.15; }
.lgms-eb .hero .speciality { font-size:14px; color:#b8a98c; font-style:italic; margin:0; }

/* Hero social icons */
.lgms-eb .hero-socials { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
.lgms-eb .hero-socials a { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:50%; background:rgba(236,179,81,0.10); color:var(--amber); text-decoration:none; transition:background 0.15s, color 0.15s, transform 0.15s; }
.lgms-eb .hero-socials a:hover { background:var(--amber); color:var(--dark); transform:translateY(-1px); }
.lgms-eb .hero-socials a svg { display:block; }

/* Body */
.lgms-eb .body { padding:36px 48px 40px; }
.lgms-eb .body .bio-text { font-size:16px; line-height:1.8; color:var(--ink); }
.lgms-eb .body .bio-text p { margin:0 0 16px; }

/* Body action row */
.lgms-eb .actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:28px; padding-top:20px; border-top:1px solid var(--sand); }
.lgms-eb .actions a { display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border-radius:6px; font-size:13px; font-weight:700; text-decoration:none; text-transform:uppercase; letter-spacing:0.04em; transition:opacity 0.15s; }
.lgms-eb .actions a:hover { opacity:0.85; }
.lgms-eb .actions .btn-archive { background:var(--amber); color:var(--dark); }
.lgms-eb .actions .btn-profile { background:var(--dark); color:var(--cream); }
.lgms-eb .actions .btn-back    { background:var(--sand); color:var(--dark); border:1px solid #d0c8ba; margin-left:auto; }
.lgms-eb .actions svg { flex-shrink:0; }

.lgms-eb .bio-placeholder { color:#999; font-style:italic; font-size:15px; }

@media (max-width:600px) {
    .lgms-eb .hero { flex-direction:column; text-align:center; padding:36px 22px 28px; gap:18px; }
    .lgms-eb .hero .avatar { width:96px; height:96px; }
    .lgms-eb .hero h1 { font-size:26px; }
    .lgms-eb .hero-socials { justify-content:center; }
    .lgms-eb .body { padding:28px 22px 32px; }
    .lgms-eb .actions { justify-content:center; }
    .lgms-eb .actions .btn-back { margin-left:0; }
}
</style>

<div class="lgms-eb">
<div class="wrap">

  <header class="hero">
    <span class="avatar"<?php echo $avatarUrl ? ' style="background-image:url(' . esc_url( $avatarUrl ) . ');"' : ''; ?>></span>
    <div class="hero-text">
      <p class="overline">Council of Elders &mdash; The Looth Group</p>
      <h1><?php echo esc_html( $name ); ?></h1>
      <p class="speciality"><?php echo esc_html( $speciality ); ?></p>

      <?php
      // Social row in the hero — only renders icons that have URLs.
      $socials = [
          'website'   => [ $webUrl,     'Website' ],
          'instagram' => [ $igUrl,      'Instagram' ],
          'facebook'  => [ $fbUrl,      'Facebook' ],
          'youtube'   => [ $ytUrl,      'YouTube' ],
          'twitter'   => [ $twUrl,      'X / Twitter' ],
          'profile'   => [ $profileUrl, 'Looth profile' ],
      ];
      $hasAny = false;
      foreach ( $socials as $cfg ) if ( $cfg[0] !== '' ) { $hasAny = true; break; }
      if ( $hasAny ) : ?>
      <div class="hero-socials">
        <?php foreach ( $socials as $key => $cfg ) :
            [ $url, $label ] = $cfg;
            if ( $url === '' ) continue; ?>
          <a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
            <?php echo $icons[ $key ]; // safe, hand-built static SVGs ?>
          </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <?php if ( current_user_can( 'manage_options' ) && $elderIndex >= 0 ) : ?>
      <button type="button" class="lgms-elder-edit-btn" data-index="<?php echo (int) $elderIndex; ?>" title="Edit profile" aria-label="Edit profile" style="top:14px;right:14px;width:34px;height:34px;font-size:16px;">&#9998;</button>
    <?php endif; ?>
  </header>

  <div class="body">
    <?php if ( $bio !== '' ) : ?>
      <div class="bio-text">
        <?php
        if ( strip_tags( $bio ) === $bio ) {
            echo '<p>' . nl2br( esc_html( $bio ) ) . '</p>';
        } else {
            echo wp_kses_post( $bio );
        }
        ?>
      </div>
    <?php else : ?>
      <p class="bio-placeholder">Bio coming soon.</p>
    <?php endif; ?>

    <div class="actions">
      <?php if ( $archiveUrl !== '' ) : ?>
        <a href="<?php echo esc_url( $archiveUrl ); ?>" class="btn-archive">
          <?php echo $icons['archive']; ?> Browse their archive
        </a>
      <?php endif; ?>

      <a href="<?php echo esc_url( home_url( '/membership-guide/' ) ); ?>" class="btn-back">
        &larr; Member Guide
      </a>
    </div>
  </div>

</div>
</div>

<?php
// Inline edit modal (renders nothing for non-admins).
\LGMS\Wp\MembershipGuide::renderEditModal( \LGMS\Wp\MembershipGuide::getElders() );
?>
