<?php
/**
 * Welcome email body. Designed to mirror the visual language of
 * /membership-guide/ — dark hero with amber serif headline, sand
 * rounded-square section icons, amber-d uppercase subtitles, and
 * the same cream/sand/amber/green palette.
 *
 * Variables in scope:
 *   $name          — display name (string)
 *   $tierLabel     — "Looth LITE" | "Looth PRO" | "Looth Premium Plus" (string)
 *   $loginUrl      — link into the site (activity feed)
 *   $manageUrl     — /manage-subscription/
 *   $homeUrl       — home URL
 *   $mosaicImages  — array of thumbnail URLs (0–6 items)
 */
$mosaicImages = $mosaicImages ?? [];

// Palette — kept inline-style only because Outlook strips most <style> rules.
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
        You&rsquo;re in. A library, a forum, and a calendar of live shows &mdash; built for people who do this work seriously.
      </p>
      <span style="display:inline-block;padding:5px 14px;background:rgba(236,179,81,0.15);border:1px solid #ECB351;border-radius:20px;font-size:12px;font-weight:700;color:#ECB351;letter-spacing:0.08em;text-transform:uppercase;">
        <?php echo esc_html( $tierLabel ); ?> Member &middot; Membership active
      </span>
    </td>
  </tr>

  <!-- AMBER TOC BAND — mirrors the sticky toc on /membership-guide/ -->
  <tr>
    <td style="background:#ECB351;padding:12px 20px;text-align:center;">
      <a href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>" style="display:inline-block;margin:0 10px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Events</a>
      <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="display:inline-block;margin:0 10px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Archive</a>
      <a href="<?php echo esc_url( home_url( '/activity/' ) ); ?>" style="display:inline-block;margin:0 10px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Feed</a>
      <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="display:inline-block;margin:0 10px;font-size:12px;font-weight:700;color:#2B2318;text-decoration:none;text-transform:uppercase;letter-spacing:0.07em;">Forums</a>
    </td>
  </tr>

  <!-- BODY SECTIONS — each follows the page's section pattern:
       sand rounded-square icon + Georgia h2 + amber-d subtitle + body + green CTA -->
  <tr>
    <td style="padding:36px 40px 8px;">

      <!-- ARCHIVE -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#127916;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">The Archive</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Searchable library &middot; growing weekly</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 10px;">Hundreds of videos, articles, loothprints, and documents &mdash; searchable by topic, format, and author. Dan Erlewine, Doug Proper, Michael Bashkin, Linda Manzer, and many more.</p>
            <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Browse the archive &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- EVENTS -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#128197;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">Live Events</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Workshops, interviews, Q&amp;As</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 10px;">Workshops, interviews, and Q&amp;As running constantly. Miss one? Every session gets recorded and added to the archive.</p>
            <a href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">See what&rsquo;s coming up &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- FORUMS -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#128172;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">The Forums</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Repair &middot; builds &middot; tools &middot; business</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 10px;">Organized by discipline. Post anonymously, flag posts for the weekly email, or submit a question to the Council of Elders.</p>
            <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Go to the forums &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- COUNCIL OF ELDERS -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#129681;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">Council of Elders</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Monthly Q&amp;A &middot; submit anonymously</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 10px;">Once a month, the most experienced people in the trade sit down to answer questions from the community. Submit yours from any forum thread &mdash; just tick the box.</p>
            <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Submit a question &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;"><tr><td height="1" style="background:#EAE5DC;font-size:0;line-height:0;">&nbsp;</td></tr></table>

      <!-- 3D / CNC -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:32px;">
        <tr>
          <td width="62" valign="top" style="padding-top:2px;">
            <div style="width:48px;height:48px;background:#EAE5DC;border-radius:10px;text-align:center;line-height:48px;font-size:22px;">&#128424;&#65039;</div>
          </td>
          <td valign="top">
            <h2 style="font-family:Georgia,'Times New Roman',serif;font-size:22px;font-weight:700;color:#2B2318;margin:0 0 4px;line-height:1.25;">3D Printing &amp; CNC</h2>
            <p style="font-size:11px;font-weight:700;color:#C68A1E;text-transform:uppercase;letter-spacing:0.07em;margin:0 0 10px;">Active build-out &middot; jigs &middot; CAD/CAM</p>
            <p style="font-size:15px;line-height:1.7;color:#5C4E3A;margin:0 0 10px;">We&rsquo;re actively building out this corner of the community. Jigs, fixtures, parametric templates, CAD/CAM workflows &mdash; if you&rsquo;re running a printer or CNC, or just curious, there&rsquo;s a dedicated forum and a growing video series waiting.</p>
            <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.06em;">Join the conversation &rarr;</a>
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

  <!-- CALLOUT (mirrors page's .callout — green-l bg, green left border) -->
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
        or jump straight to the <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="color:#87986A;text-decoration:none;">archive</a>
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
