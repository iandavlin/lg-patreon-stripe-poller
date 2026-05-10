<?php
/**
 * Elder bio + avatar seeder.
 * Run: wp eval-file seed-elders.php
 * Safe to re-run — only fills in empty fields.
 */

$seed = [
    'Ian Davlin' => [
        'bio'        => 'Ian Davlin is the founder of The Looth Group, a community built for people who do serious guitar work. He created the platform to bring together the most experienced makers in the world and make their knowledge accessible to working builders and repairers everywhere.',
        'ig_url'     => '',
        'avatar_url' => '',
    ],
    'Dan Erlewine' => [
        'bio'        => 'Dan Erlewine is one of the most celebrated figures in guitar repair and restoration, with a career spanning more than 50 years. Author of the <em>Guitar Player Repair Guide</em> and a longtime collaborator with <a href="https://www.stewmac.com">StewMac</a>, Dan has built instruments for Jerry Garcia and Albert King and trained a generation of luthiers through his writing, video work, and in-person teaching.',
        'ig_url'     => 'https://www.instagram.com/danerlewine/',
        'avatar_url' => 'https://www.stewmac.com/contentassets/e127c01d8ce143969b7464f6b53bc510/dan-erlewine.png',
    ],
    'Michael Bashkin' => [
        'bio'        => 'Michael Bashkin began building guitars in 1994 while studying forestry at Colorado State University, bringing a wood biologist\'s understanding of acoustics to his craft. He opened his Fort Collins, Colorado shop in 1998, where he builds a small number of meticulously handcrafted acoustic guitars each year. Bashkin is also the creator and host of the <a href="https://luthieronluthier.libsyn.com/">Luthier on Luthier podcast</a> and is widely regarded as one of the most thoughtful voices in the independent lutherie community.',
        'ig_url'     => 'https://www.instagram.com/bashkin_guitars/',
        'avatar_url' => 'https://images.fretboardjournal.com/wp-content/uploads/2016/01/18140710/bashkin-2.jpg',
    ],
    'James Rodaman' => [
        'bio'        => 'James Roadman is a San Antonio-based luthier and repair specialist known for exceptional instrument work and innovative custom tooling for the lutherie trade. He founded the San Antonio Luthiers Group in 2017, creating a hub for local builders and repairers to share knowledge. Featured on Michael Bashkin\'s <a href="https://luthieronluthier.libsyn.com/">Luthier on Luthier podcast</a>, James is recognized for his machine-shop expertise and dedication to advancing the craft through community.',
        'ig_url'     => 'https://www.instagram.com/jroadman/',
        'avatar_url' => 'https://images.fretboardjournal.com/wp-content/uploads/2022/08/18190616/IMG_1674-768x1024.jpg',
    ],
    'Doug Proper' => [
        'bio'        => 'Doug Proper owns and operates <a href="https://www.guitarspecialist.com">The Guitar Specialist</a>, widely regarded as one of the premier guitar repair and restoration shops in the United States. Originally pursuing a career as a jazz guitarist, Doug turned to repair work during his college years and built a clientele that includes Paul Simon, John Scofield, and John Abercrombie. He is a member of the Guild of American Luthiers and the Association of Stringed Instrument Artisans.',
        'ig_url'     => 'https://www.instagram.com/guitarspecialistinc/',
        'avatar_url' => 'https://www.guitarspecialist.com/images/tgs/newdoug003_200.jpg',
    ],
    'Brock Poling' => [
        'bio'        => 'Brock Poling is a luthier and guitar builder who joined <a href="https://www.stewmac.com">StewMac</a> in the early 2000s, eventually serving as Vice President of Marketing at the company that supplies tools and materials to luthiers worldwide. A customer himself before joining the team, Brock embodies the company\'s ethos that everyone on staff plays, repairs, or builds. He has collaborated on-camera with Dan Erlewine on StewMac\'s instructional content series.',
        'ig_url'     => 'https://www.instagram.com/luthiersworkbench/',
        'avatar_url' => 'https://s3.amazonaws.com/cco-avatars/57b4c40d-aebe-4f26-8bc2-6f0daf133a08.png',
    ],
    'Massimiliano Montorosso' => [
        'bio'        => 'Massimiliano "Max" Montorosso is an Italian luthier based in Abano Terme, Italy, building custom acoustic, electric, and harp guitars under the <a href="https://www.maxmonte.com">Maxmonte Guitars</a> brand. His work blends time-honored luthiery techniques — hide glue, hand-carved braces, hand-planed joints — with a modern sensibility, incorporating fan frets, alternative bridge designs, and unconventional tonewoods. Each guitar is built entirely by hand, resulting in instruments of exceptional tonal character available through select dealers worldwide.',
        'ig_url'     => 'https://www.instagram.com/maxmonte_guitars/',
        'avatar_url' => 'https://www.maxmonte.com/images/team/max-hg.jpg',
    ],
];

$raw = get_option( 'lgms_guide_elders', null );
if ( is_string( $raw ) ) $raw = json_decode( $raw, true ) ?: [];
if ( ! is_array( $raw ) || empty( $raw ) ) {
    // Option doesn't exist yet — build from seed directly.
    $raw = [];
    foreach ( $seed as $name => $s ) {
        $raw[] = [
            'name'        => $name,
            'avatar_id'   => $s['avatar_url'],
            'ig_url'      => $s['ig_url'],
            'bio'         => $s['bio'],
            'archive_url' => '',
            'bio_page_id' => 0,
        ];
    }
} else {
    // Merge into existing rows — only fill empty fields.
    foreach ( $raw as &$elder ) {
        $name = $elder['name'] ?? '';
        if ( ! isset( $seed[ $name ] ) ) continue;
        $s = $seed[ $name ];

        if ( empty( $elder['bio'] ) && ! empty( $s['bio'] ) ) {
            $elder['bio'] = $s['bio'];
        }
        if ( empty( $elder['ig_url'] ) && ! empty( $s['ig_url'] ) ) {
            $elder['ig_url'] = $s['ig_url'];
        }
        $curAvatar = $elder['avatar_id'] ?? 0;
        if ( ( ! $curAvatar || $curAvatar === '0' || $curAvatar === 0 ) && ! empty( $s['avatar_url'] ) ) {
            $elder['avatar_id'] = $s['avatar_url'];
        }
        if ( ! isset( $elder['bio_page_id'] ) ) {
            $elder['bio_page_id'] = 0;
        }
        if ( ! isset( $elder['archive_url'] ) ) {
            $elder['archive_url'] = '';
        }
    }
    unset( $elder );
}

update_option( 'lgms_guide_elders', $raw );

// Create / reconcile bio pages.
LGMS\Wp\MembershipGuide::syncElderPages();

// Re-fetch to show final page IDs.
$final = get_option( 'lgms_guide_elders', [] );
if ( is_string( $final ) ) $final = json_decode( $final, true ) ?: [];

echo "Done. " . count( $final ) . " elders saved.\n\n";
foreach ( $final as $e ) {
    $pid    = $e['bio_page_id'] ?? 0;
    $slug   = 'elder-' . sanitize_title( $e['name'] );
    $avatar = $e['avatar_id'] ?? 0;
    $avi    = is_string( $avatar ) ? '(url) ' . substr( $avatar, 0, 55 ) : "(id) $avatar";
    echo sprintf( " %-28s  page_id=%-5s  slug=%-32s  avatar=%s\n",
        $e['name'], $pid ?: 'none', $slug, $avi );
}
