<?php
/**
 * Welcome email body.
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
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to The Looth Group</title>
</head>
<body style="margin:0;padding:40px 16px;background:#e8e2d8;font-family:Arial,Helvetica,sans-serif;">

<table role="presentation" cellpadding="0" cellspacing="0" width="100%">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#FAF6EE;border-radius:8px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,0.10);">

  <!-- LOGO / DARK HEADER -->
  <tr>
    <td style="padding:28px 40px 20px;text-align:center;background:#2B2318;">
      <img src="https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png"
           alt="The Looth Group" width="200" style="display:inline-block;max-width:200px;height:auto;">
    </td>
  </tr>

  <!-- AMBER HERO BAND -->
  <tr>
    <td style="background:#ECB351;padding:10px 24px;">
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
        <tr>
          <td><span style="font-size:13px;font-weight:700;color:#2B2318;text-transform:uppercase;letter-spacing:1.5px;">New Member Welcome</span></td>
          <td align="right"><span style="font-size:13px;color:#5C4E3A;">Your membership is active</span></td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- WELCOME HEADER -->
  <tr>
    <td style="padding:32px 40px 24px;text-align:center;border-bottom:2px solid #ECB351;">
      <h1 style="margin:0 0 10px;font-family:Georgia,'Times New Roman',serif;font-size:28px;font-weight:700;color:#2B2318;line-height:1.2;">Welcome, <?php echo esc_html( $name ); ?>.</h1>
      <span style="display:inline-block;padding:4px 14px;background:#FAF6EE;border:1px solid #ECB351;border-radius:20px;font-size:13px;font-weight:700;color:#C68A1E;letter-spacing:0.06em;text-transform:uppercase;"><?php echo esc_html( $tierLabel ); ?> Member</span>
      <p style="margin:16px auto 0;font-size:17px;line-height:1.7;color:#5C4E3A;max-width:460px;">
        You're in. A library, a community, and a calendar built for people who do this work seriously — all yours now.
      </p>
    </td>
  </tr>

  <!-- BODY SECTIONS -->
  <tr>
    <td style="padding:28px 40px 8px;">

      <!-- ARCHIVE -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="52" valign="top">
            <div style="width:44px;height:44px;background:#EAE5DC;border-radius:9px;text-align:center;line-height:44px;font-size:20px;">&#127916;</div>
          </td>
          <td style="padding-left:16px;" valign="top">
            <span style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:#2B2318;display:block;margin-bottom:4px;">The Archive</span>
            <p style="font-size:15px;line-height:1.6;color:#5C4E3A;margin:0 0 8px;">Hundreds of videos, articles, loothprints, and documents — searchable by topic, format, and author. Dan Erlewine, Doug Proper, Michael Bashkin, Linda Manzer, and many more.</p>
            <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.05em;">Browse the archive &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
          <td width="70%" height="1" style="background:#D4E0B8;font-size:0;line-height:0;">&nbsp;</td>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
        </tr>
      </table>

      <!-- FORUMS -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="52" valign="top">
            <div style="width:44px;height:44px;background:#EAE5DC;border-radius:9px;text-align:center;line-height:44px;font-size:20px;">&#128172;</div>
          </td>
          <td style="padding-left:16px;" valign="top">
            <span style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:#2B2318;display:block;margin-bottom:4px;">The Forums</span>
            <p style="font-size:15px;line-height:1.6;color:#5C4E3A;margin:0 0 8px;">Repair, builds, tools, business, marketplace — organized by discipline. Post anonymously, flag posts for the weekly email, or submit a question to the Council of Elders.</p>
            <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.05em;">Go to the forums &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
          <td width="70%" height="1" style="background:#D4E0B8;font-size:0;line-height:0;">&nbsp;</td>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
        </tr>
      </table>

      <!-- EVENTS -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="52" valign="top">
            <div style="width:44px;height:44px;background:#EAE5DC;border-radius:9px;text-align:center;line-height:44px;font-size:20px;">&#128197;</div>
          </td>
          <td style="padding-left:16px;" valign="top">
            <span style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:#2B2318;display:block;margin-bottom:4px;">Live Events</span>
            <p style="font-size:15px;line-height:1.6;color:#5C4E3A;margin:0 0 8px;">Workshops, interviews, and Q&amp;As running constantly. Miss one? Every session gets recorded and added to the archive.</p>
            <a href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.05em;">See what&rsquo;s coming up &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
          <td width="70%" height="1" style="background:#D4E0B8;font-size:0;line-height:0;">&nbsp;</td>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
        </tr>
      </table>

      <!-- COUNCIL OF ELDERS -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="52" valign="top">
            <div style="width:44px;height:44px;background:#EAE5DC;border-radius:9px;text-align:center;line-height:44px;font-size:20px;">&#129681;</div>
          </td>
          <td style="padding-left:16px;" valign="top">
            <span style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:#2B2318;display:block;margin-bottom:4px;">Council of Elders</span>
            <p style="font-size:15px;line-height:1.6;color:#5C4E3A;margin:0 0 8px;">Monthly Q&amp;A with the most experienced people in the trade. Submit questions anonymously — just check the box when posting in the forum.</p>
            <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.05em;">Submit a question &rarr;</a>
          </td>
        </tr>
      </table>

      <!-- DIVIDER -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:20px;">
        <tr>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
          <td width="70%" height="1" style="background:#D4E0B8;font-size:0;line-height:0;">&nbsp;</td>
          <td width="15%" style="font-size:0;line-height:0;">&nbsp;</td>
        </tr>
      </table>

      <!-- 3D / CNC -->
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin-bottom:28px;">
        <tr>
          <td width="52" valign="top">
            <div style="width:44px;height:44px;background:#EAE5DC;border-radius:9px;text-align:center;line-height:44px;font-size:20px;">&#128424;&#65039;</div>
          </td>
          <td style="padding-left:16px;" valign="top">
            <span style="font-family:Georgia,'Times New Roman',serif;font-size:18px;font-weight:700;color:#2B2318;display:block;margin-bottom:4px;">3D Printing &amp; CNC</span>
            <p style="font-size:15px;line-height:1.6;color:#5C4E3A;margin:0 0 8px;">We're actively building out this corner of the community. Jigs, fixtures, parametric templates, CAD/CAM workflows — if you're already running a printer or a CNC, or just curious, there's a dedicated forum and a growing video series waiting for you.</p>
            <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="font-size:13px;font-weight:700;color:#87986A;text-decoration:none;text-transform:uppercase;letter-spacing:0.05em;">Join the conversation &rarr;</a>
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

  <!-- SIGN-OFF / CTA -->
  <tr>
    <td style="padding:8px 40px 32px;text-align:center;border-top:1px solid #EAE5DC;">
      <p style="font-size:16px;color:#5C4E3A;font-style:italic;line-height:1.6;margin:20px 0 24px;">
        Welcome to the guild. There&rsquo;s a lot here &mdash; dig in whenever you&rsquo;re ready.
      </p>
      <a href="<?php echo esc_url( $loginUrl ); ?>"
         style="display:inline-block;padding:16px 44px;background:#ECB351;color:#2B2318;font-weight:800;font-size:16px;text-decoration:none;border-radius:7px;letter-spacing:0.02em;">
        Head to the feed &rarr;
      </a>
      <p style="margin:14px 0 0;font-size:13px;color:#aaa;">
        or go straight to the <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="color:#87986A;text-decoration:none;">archive</a>
      </p>
    </td>
  </tr>

  <!-- DARK FOOTER -->
  <tr>
    <td style="background:#2B2318;padding:24px 40px;text-align:center;">
      <p style="font-family:Georgia,'Times New Roman',serif;color:#ECB351;font-size:14px;letter-spacing:3px;text-transform:uppercase;margin:0 0 12px;">The Looth Group</p>
      <p style="margin:0 0 12px;">
        <a href="<?php echo esc_url( home_url( '/archive/' ) ); ?>" style="color:#87986A;font-size:14px;text-decoration:none;margin:0 8px;">Archive</a>
        <a href="<?php echo esc_url( home_url( '/forums/' ) ); ?>" style="color:#87986A;font-size:14px;text-decoration:none;margin:0 8px;">Forums</a>
        <a href="<?php echo esc_url( home_url( '/calendar/' ) ); ?>" style="color:#87986A;font-size:14px;text-decoration:none;margin:0 8px;">Events</a>
        <a href="<?php echo esc_url( $manageUrl ); ?>" style="color:#87986A;font-size:14px;text-decoration:none;margin:0 8px;">Manage subscription</a>
      </p>
      <p style="font-size:12px;color:#5C4E3A;margin:0 0 8px;line-height:1.6;">
        Questions? Reply to this email and a human will get back to you.<br>
        loothgroup.com
      </p>
      <p style="font-size:11px;color:#4a3d30;margin:0;line-height:1.6;">
        You&rsquo;ve been subscribed to Loothgroup Weekly &mdash; curated content, events, and forum highlights every week.
        <a href="<?php echo esc_url( $manageUrl ); ?>" style="color:#87986A;text-decoration:underline;">Manage email preferences</a>
      </p>
    </td>
  </tr>

</table>
</td></tr>
</table>

</body>
</html>
