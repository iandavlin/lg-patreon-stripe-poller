<?php
/**
 * Welcome email body. Mirrors the visual language and section structure
 * of /membership-guide/ — dark hero with amber serif headline, sand
 * rounded-square section icons, amber-d uppercase subtitles, and the
 * cream/sand/amber/green palette.
 *
 * Where the page has horizontal sliders (upcoming events, recurring
 * shows, council of elders) the email renders a compact 3-up table
 * with a "see all" CTA anchored to the live-page section. Sliders
 * don't render reliably in email clients; static grids do.
 *
 * Variables in scope:
 *   $name            — display name (string)
 *   $tierLabel       — "Looth LITE" | "Looth PRO" | "Looth Premium Plus"
 *   $loginUrl        — link into the site (activity feed)
 *   $manageUrl       — /manage-subscription/
 *   $homeUrl         — home URL
 *   $guideUrl        — /membership-guide/  (anchor target for "see all")
 *   $loothalongUrl   — Zoom URL for Loothalong (admin-set; empty = not configured)
 *   $mosaicImages    — array of thumbnail URLs (0–6 items)
 *   $upcomingEvents  — list of next ≤3 events (id, title, permalink, thumb, date_pill, day_label, time_label, excerpt)
 *   $recurringShows  — list of ≤3 [{title, thumb_url, archive_url}]
 *   $eldersForEmail  — list of ≤3 [{name, avatar}]
 */
$mosaicImages    = $mosaicImages    ?? [];
$upcomingEvents  = $upcomingEvents  ?? [];
$recurringShows  = $recurringShows  ?? [];
$eldersForEmail  = $eldersForEmail  ?? [];

// Palette — kept inline-style only (Outlook strips most <style> rules).
//   --cream  #FAF6EE   --sand  #EAE5DC   --bg    #e8e2d8
//   --dark   #2B2318   --ink   #5C4E3A
//   --amber  #ECB351   --amber-d #C68A1E
//   --green  #87986A   --green-l #D4E0B8
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to The Looth Group</title>
</head>
<body style="margin:0;padding:40px 16px;background:#e8e2d8;font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#5C4E3A;">

<table role="presentation" cellpadding="0" cellspacing="0" width="100%">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#FAF6EE;border-radius:8px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.10);">

  <!-- HERO (dark) — logo + amber serif H1 + lede + tier pill -->
  <tr>
    <td style="background:#2B2318;padding:48px 40px 40px;text-align:center;">
      <img src="https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png"
           alt="The Looth Group" width="200"
           style="display:block;margin:0 auto 24px;max-width:60%;height:auto;">
      <h1 style="margin:0 0 12px;font-family:Georgia,'Times New Roman',serif;font-size:32px;line-height:1.15;font-weight:700;color:#ECB351;">
        Welcome, <?php echo esc_html( $name ); ?>.
      </h1>
      <p style="margin:0 auto 18px;max-width:480px;font-size:17px;line-height:1.6;color:#d8cfc0;">
        A library, a forum, a calendar of live shows, and a 24/7 lounge &mdash; all built for people who do this work seriously. Here&rsquo;s the tour.
      </p>
      <span style="display:inline-block;padding:5px 14px;background:rgba(236,179,81,0.15);border:1px solid #ECB351;border-radius:20px;font-size:12px;font-weight:700;color:#ECB351;letter-spacing:0.08em;text-transform:uppercase;">
        <?php echo esc_html( $tierLabel ); ?> Member &middot; Membership active
      </span>
    </td>
  </tr>

  <!-- AMBER TOC BAND — mirrors the sticky toc on /membership-guide/ -->
  <tr>
    <td style="background:#ECB351;padding:12px 20px;text-align:center;">
      <a href="<?php echo esc_url( $guideUrl . '#events' ); ?>" style="display:inline-block;margin:0 7px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Events</a>
      <a href="<?php echo esc_url( $guideUrl . '#archive' ); ?>" style="display:inline-block;margin:0 7px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Archive</a>
      <a href="<?php echo esc_url( $guideUrl . '#feed' ); ?>" style="display:inline-block;margin:0 7px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Feed</a>
      <a href="<?php echo esc_url( $guideUrl . '#forums' ); ?>" style="display:inline-block;margin:0 7px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Forums</a>
      <a href="<?php echo esc_url( $guideUrl . '#looths' ); ?>" style="display:inline-block;margin:0 7px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Looths</a>
      <a href="<?php echo esc_url( $guideUrl . '#loothalong' ); ?>" style="display:inline-block;margin:0 7px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Loothalong</a>
    </td>
  </tr>

  <!-- ============================================================= -->
  <!-- SECTIONS                                                      -->
  <!-- ============================================================= -->
  <tr>
    <td style="padding:36px 40px 8px;">

      <!-- ── EVENTS ────────────────────────────────────────────── -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#128197;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">Live Events</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Recurring shows &middot; workshops &middot; Q&amp;As</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 12px;">The Looth calendar runs <strong>year-round</strong>. Most weeks bring multiple live sessions &mdash; workshops, builder interviews, deep-dive Q&amp;As, and the monthly <strong>Council of Elders</strong>. Miss one? Every session gets recorded and added to the Archive within 24 hours.</p>
          </td>
        </tr>
      </table>

      <?php if ( $upcomingEvents ) : ?>
      <p style="font-size:12px;font-weight:700;color:#5C4E3A;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px 62px;">Coming up next</p>
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 14px;">
        <tr>
          <?php
          $pad   = $upcomingEvents;
          while ( count( $pad ) < 3 ) { $pad[] = null; }
          $pad = array_slice( $pad, 0, 3 );
          foreach ( $pad as $i => $ev ) :
              $cellPadL = $i === 0 ? '0' : '4px';
              $cellPadR = $i === 2 ? '0' : '4px';
          ?>
          <td width="33%" valign="top" style="padding:0 <?php echo $cellPadR; ?> 0 <?php echo $cellPadL; ?>;">
            <?php if ( $ev ) : ?>
              <a href="<?php echo esc_url( $ev['permalink'] ); ?>" style="display:block;text-decoration:none;color:inherit;background:#FAF6EE;border:1px solid #EAE5DC;border-radius:8px;overflow:hidden;">
                <div style="height:90px;background:<?php echo $ev['thumb'] ? 'url(' . esc_url( $ev['thumb'] ) . ') center/cover no-repeat' : '#EAE5DC'; ?>;border-bottom:3px solid #ECB351;"></div>
                <div style="padding:8px 10px 10px;">
                  <div style="font-size:10px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:3px;"><?php echo esc_html( trim( $ev['day_label'] . ( $ev['time_label'] ? ' · ' . $ev['time_label'] : '' ), ' ·' ) ); ?></div>
                  <div style="font-family:Georgia,'Times New Roman',serif;font-size:13px;color:#2B2318;line-height:1.3;"><?php echo esc_html( $ev['title'] ); ?></div>
                </div>
              </a>
            <?php else : ?>
              <div style="height:148px;background:#FAF6EE;border:1px dashed #EAE5DC;border-radius:8px;"></div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      </table>
      <p style="margin:0 0 10px 62px;font-size:12px;color:#888;">
        <a href="<?php echo esc_url( $guideUrl . '#events' ); ?>" style="color:#87986A;text-decoration:none;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">See all upcoming events &rarr;</a>
      </p>
      <?php endif; ?>

      <?php if ( $recurringShows ) : ?>
      <p style="font-size:12px;font-weight:700;color:#5C4E3A;text-transform:uppercase;letter-spacing:0.07em;margin:14px 0 10px 62px;">Recurring shows</p>
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 14px;">
        <tr>
          <?php
          $pad = $recurringShows;
          while ( count( $pad ) < 3 ) { $pad[] = null; }
          $pad = array_slice( $pad, 0, 3 );
          foreach ( $pad as $i => $sh ) :
              $cellPadL = $i === 0 ? '0' : '4px';
              $cellPadR = $i === 2 ? '0' : '4px';
          ?>
          <td width="33%" valign="top" style="padding:0 <?php echo $cellPadR; ?> 0 <?php echo $cellPadL; ?>;">
            <?php if ( $sh ) :
                $shHref = (string) ( $sh['archive_url'] ?? '' );
                $shThumb = (string) ( $sh['thumb_url'] ?? '' );
            ?>
              <a href="<?php echo esc_url( $shHref ?: $guideUrl . '#events' ); ?>" style="display:block;text-decoration:none;color:inherit;background:#FAF6EE;border:1px solid #EAE5DC;border-radius:8px;overflow:hidden;">
                <div style="height:80px;background:<?php echo $shThumb ? 'url(' . esc_url( $shThumb ) . ') center/cover no-repeat' : '#EAE5DC'; ?>;"></div>
                <div style="padding:8px 10px 10px;font-family:Georgia,'Times New Roman',serif;font-size:13px;color:#2B2318;line-height:1.3;"><?php echo esc_html( (string) $sh['title'] ); ?></div>
              </a>
            <?php else : ?>
              <div style="height:128px;background:#FAF6EE;border:1px dashed #EAE5DC;border-radius:8px;"></div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      </table>
      <?php endif; ?>

      <p style="margin:0 0 0 62px;">
        <a href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Open the calendar &rarr;</a>
      </p>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:28px 0;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- ── ARCHIVE ───────────────────────────────────────────── -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#127916;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">The Archive</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Hundreds of videos, articles, loothprints, and documents</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 10px;">Every recording, document, and Loothprint we&rsquo;ve ever made &mdash; searchable by topic, format, and author. Dan Erlewine, Doug Proper, Michael Bashkin, and dozens more.</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 12px;"><strong>Every live event ends up here.</strong> Workshops, builder interviews, Council Q&amp;As &mdash; all recorded and added within 24 hours of airing, so nothing&rsquo;s ever truly missed.</p>
            <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Browse the archive &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:28px 0;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- ── FEED ──────────────────────────────────────────────── -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#128240;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">The Feed</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">What&rsquo;s new, all in one stream</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 12px;">Your home base after sign-in. New archive uploads, fresh forum threads, event reminders, and activity from the people you follow &mdash; all in chronological order.</p>
            <a href="<?php echo esc_url( home_url( '/activity/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Open your feed &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:28px 0;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- ── FORUMS ────────────────────────────────────────────── -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#127963;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">The Forums</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Discipline-specific conversation, anonymous if you want</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 10px;">Organized by discipline &mdash; <strong>Repair, Builds, Tools, Business, Marketplace</strong>, and more. Post under your own name, or check the &ldquo;post anonymously&rdquo; box and only the moderators see who you are.</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 12px;">Tick <strong>Submit to the Council of Elders</strong> on any post if you want senior makers to weigh in at the next monthly Q&amp;A.</p>
            <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Go to the forums &rarr;</a>
          </td>
        </tr>
      </table>

      <?php if ( $eldersForEmail ) : ?>
      <p style="font-size:12px;font-weight:700;color:#5C4E3A;text-transform:uppercase;letter-spacing:0.07em;margin:18px 0 10px 62px;">Council of Elders</p>
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 10px;">
        <tr>
          <?php
          $pad = $eldersForEmail;
          while ( count( $pad ) < 3 ) { $pad[] = null; }
          $pad = array_slice( $pad, 0, 3 );
          foreach ( $pad as $i => $el ) :
              $cellPadL = $i === 0 ? '0' : '4px';
              $cellPadR = $i === 2 ? '0' : '4px';
          ?>
          <td width="33%" valign="top" align="center" style="padding:0 <?php echo $cellPadR; ?> 0 <?php echo $cellPadL; ?>;">
            <?php if ( $el ) : ?>
              <div style="width:64px;height:64px;border-radius:50%;background:<?php echo ! empty( $el['avatar'] ) ? 'url(' . esc_url( $el['avatar'] ) . ') center/cover no-repeat' : '#EAE5DC'; ?>;border:2px solid #ECB351;margin:0 auto 6px;"></div>
              <div style="font-family:Georgia,'Times New Roman',serif;font-size:13px;color:#2B2318;line-height:1.3;"><?php echo esc_html( (string) $el['name'] ); ?></div>
            <?php else : ?>
              <div style="width:64px;height:64px;border-radius:50%;background:#FAF6EE;border:1px dashed #EAE5DC;margin:0 auto 6px;"></div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
      </table>
      <p style="margin:0 0 0 62px;">
        <a href="<?php echo esc_url( $guideUrl . '#forums' ); ?>" style="font-size:12px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.05em;">Meet the full Council &rarr;</a>
      </p>
      <?php endif; ?>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:28px 0;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- ── LOOTHS ────────────────────────────────────────────── -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#128101;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">Looths &mdash; Connections &amp; Messages</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Find your people, and talk to them privately</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 12px;">&ldquo;Looths&rdquo; is how we describe the network &mdash; the people you follow, who follow you, and the private messages between you.</p>
          </td>
        </tr>
      </table>

      <!-- 3-up pictogram row matching .pictograms on the page -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 14px;">
        <tr>
          <td width="33%" valign="top" align="center" style="padding:0 4px 0 0;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:50%;line-height:48px;font-size:22px;margin:0 auto 6px;">&#128269;</div>
            <div style="font-family:Georgia,'Times New Roman',serif;font-size:14px;color:#2B2318;font-weight:700;">Find</div>
            <div style="font-size:12px;color:#5C4E3A;">via the directory</div>
          </td>
          <td width="33%" valign="top" align="center" style="padding:0 4px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:50%;line-height:48px;font-size:22px;margin:0 auto 6px;">&#129309;</div>
            <div style="font-family:Georgia,'Times New Roman',serif;font-size:14px;color:#2B2318;font-weight:700;">Connect</div>
            <div style="font-size:12px;color:#5C4E3A;">send / accept requests</div>
          </td>
          <td width="34%" valign="top" align="center" style="padding:0 0 0 4px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:50%;line-height:48px;font-size:22px;margin:0 auto 6px;">&#128172;</div>
            <div style="font-family:Georgia,'Times New Roman',serif;font-size:14px;color:#2B2318;font-weight:700;">DM</div>
            <div style="font-size:12px;color:#5C4E3A;">private threads w/ photos</div>
          </td>
        </tr>
      </table>
      <p style="margin:0 0 0 62px;">
        <a href="<?php echo esc_url( home_url( '/members/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Browse the directory &rarr;</a>
      </p>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:28px 0;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- ── LOOTHALONG ────────────────────────────────────────── -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:14px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#127911;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">Loothalong &mdash; 24/7 Open Channel</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">A Zoom room that&rsquo;s always open</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 12px;">Loothalong is a <strong>24-hour-a-day Zoom room</strong> for working alongside other Looth members. Drop in while you&rsquo;re at the bench, leave a tab open in the background, ask the room a quick question. Open the link, mute your mic if you&rsquo;re not talking, and get to work.</p>

            <?php if ( $loothalongUrl !== '' ) : ?>
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 4px;background:#D4E0B8;border-radius:0 6px 6px 0;">
                <tr>
                  <td width="4" style="background:#87986A;font-size:0;line-height:0;">&nbsp;</td>
                  <td style="padding:14px 18px;">
                    <p style="margin:0 0 8px;font-size:14px;color:#2B2318;line-height:1.5;">Loothalong runs 24/7 on Zoom. As a member, you have the link &mdash;</p>
                    <a href="<?php echo esc_url( $loothalongUrl ); ?>" style="display:inline-block;padding:9px 18px;background:#87986A;color:#FAF6EE;font-weight:700;font-size:13px;text-decoration:none;border-radius:5px;letter-spacing:0.04em;text-transform:uppercase;">Join the room &rarr;</a>
                  </td>
                </tr>
              </table>
            <?php else : ?>
              <p style="font-size:13px;color:#888;font-style:italic;margin:0;">Zoom URL coming &mdash; the team&rsquo;s configuring it now.</p>
            <?php endif; ?>
          </td>
        </tr>
      </table>

    </td>
  </tr>

<?php if ( count( $mosaicImages ) >= 2 ) :
    $imgs = array_values( $mosaicImages );
    while ( count( $imgs ) < 6 ) { $imgs[] = ''; }
    $rows = [ array_slice( $imgs, 0, 3 ), array_slice( $imgs, 3, 3 ) ];
?>
  <!-- THUMBNAIL MOSAIC -->
  <tr>
    <td style="background:#2B2318;padding:8px;">
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
        <?php foreach ( $rows as $row ) : ?>
        <tr>
          <?php foreach ( $row as $idx => $url ) : ?>
          <td width="<?php echo $idx === 2 ? '34' : '33'; ?>%" style="padding:3px;">
            <?php if ( $url ) : ?>
              <img src="<?php echo esc_url( $url ); ?>"
                   width="184" style="display:block;width:100%;height:115px;object-fit:cover;border-radius:5px;" alt="">
            <?php else : ?>
              <div style="width:100%;height:115px;background:#3a2f24;border-radius:5px;"></div>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </table>
    </td>
  </tr>
<?php endif; ?>

  <!-- WEEKLY DIGEST CALLOUT -->
  <tr>
    <td style="padding:32px 40px 0;">
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#D4E0B8;border-radius:0 6px 6px 0;">
        <tr>
          <td width="4" style="background:#87986A;font-size:0;line-height:0;">&nbsp;</td>
          <td style="padding:14px 18px;">
            <p style="margin:0;font-size:15px;line-height:1.6;color:#2B2318;">
              <strong>Heads up:</strong> you&rsquo;re subscribed to the weekly digest by default &mdash; curated content, events, and forum highlights. <a href="<?php echo esc_url( $manageUrl ); ?>" style="color:#5C4E3A;text-decoration:underline;">Manage preferences</a> any time.
            </p>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- SIGN-OFF / CTA -->
  <tr>
    <td style="padding:24px 40px 36px;text-align:center;">
      <p style="margin:0 0 22px;font-family:Georgia,'Times New Roman',serif;font-size:17px;color:#5C4E3A;font-style:italic;line-height:1.6;">
        Welcome to the guild. There&rsquo;s a lot here &mdash; dig in whenever you&rsquo;re ready.
      </p>
      <a href="<?php echo esc_url( $loginUrl ); ?>"
         style="display:inline-block;padding:16px 44px;background:#ECB351;color:#2B2318;font-weight:800;font-size:16px;text-decoration:none;border-radius:7px;letter-spacing:0.02em;">
        Head to the feed &rarr;
      </a>
      <p style="margin:14px 0 0;font-size:13px;color:#aaa;">
        or read <a href="<?php echo esc_url( $guideUrl ); ?>" style="color:#87986A;text-decoration:none;">the full tour</a>
      </p>
    </td>
  </tr>

  <!-- DARK FOOTER -->
  <tr>
    <td style="background:#2B2318;padding:28px 40px;text-align:center;">
      <p style="font-family:Georgia,'Times New Roman',serif;color:#ECB351;font-size:14px;letter-spacing:3px;text-transform:uppercase;margin:0 0 14px;">The Looth Group</p>
      <p style="margin:0 0 14px;">
        <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="color:#87986A;font-size:13px;text-decoration:none;margin:0 8px;">Archive</a>
        <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="color:#87986A;font-size:13px;text-decoration:none;margin:0 8px;">Forums</a>
        <a href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>" style="color:#87986A;font-size:13px;text-decoration:none;margin:0 8px;">Events</a>
        <a href="<?php echo esc_url( $manageUrl ); ?>" style="color:#87986A;font-size:13px;text-decoration:none;margin:0 8px;">Manage</a>
      </p>
      <p style="margin:0;font-size:12px;color:#5C4E3A;line-height:1.6;">
        Questions? Reply to this email and a human will get back to you.<br>
        <a href="<?php echo esc_url( $homeUrl ); ?>" style="color:#5C4E3A;text-decoration:none;">loothgroup.com</a>
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>

</body>
</html>
