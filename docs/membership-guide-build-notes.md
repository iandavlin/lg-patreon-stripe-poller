# Membership Guide — Build Notes

Source-of-truth reference for `/membership-guide/`. Read this first if you're picking up this feature in a future chat.

---

## What this page is

A **single public page** that doubles as:

1. A **handbook** for logged-in members (how to use Events, Archive, Feed, Forums, Looths, Loothalong).
2. A **sales funnel / SEO landing page** for anonymous visitors (a small public-content gallery + bottom Join CTA).

Audience-gating is automatic: blocks tagged `audience-anon` show only to logged-out visitors; blocks tagged `audience-member` show only to logged-in users. The same page serves both — no separate URLs.

---

## File map

| File | Purpose |
|---|---|
| `src/Wp/MembershipGuide.php` | Shortcode `[lg_membership_guide]` + admin dashboard + notes download |
| `src/Wp/UpcomingEvents.php` | Shortcode `[lg_upcoming_events count="4"]` (queries `event` CPT) |
| `templates/page/membership-guide.php` | The long HTML; reads `lgms_guide_*` options |
| `src/Wp/Pages.php` | Registry entry: page slug `membership-guide`, `public: true`, no nav |
| `docs/membership-guide-build-notes.md` | This file (also served by the Download Notes button) |

---

## Sections (in render order)

1. **Hero** — logo, h1, lede.
2. **TOC strip** — sticky amber bar, anchors to all sections.
3. **Preview** *(audience-anon)* — 4-card public-content gallery for the funnel.
4. **Live Events** — dynamic upcoming events (CPT-driven), pictograms, steps, screenshots, **Council of Elders** sub-block with IG links.
5. **Archive** — pictograms, **search & filter stacking demo** (chip visual), screenshots.
6. **Feed** — autoplay-muted MP4 of feed scrolling.
7. **Forums** — pictograms, numbered steps, screenshots (note: forms are 3:4 / "tall" thumbs).
8. **Looths** — pictograms, screenshots.
9. **Loothalong** — gated. Members see the Zoom URL; non-members see "Join to get the link."
10. **Bottom Join CTA** *(audience-anon)* — for funnel conversion.

---

## Audience gating

**Body class** is set server-side from `is_user_logged_in()`:

```php
$body_class = is_user_logged_in() ? 'is-member' : 'is-anon';
```

**Block classes** drive visibility via CSS:

```css
body.is-anon   .audience-member { display: none !important; }
body.is-member .audience-anon   { display: none !important; }
```

| Block | Anon | Member |
|---|---|---|
| Preview gallery | ✅ | ❌ |
| Loothalong Zoom URL | ❌ | ✅ |
| Loothalong "Join to unlock" | ✅ | ❌ |
| Bottom Join CTA | ✅ | ❌ |
| Everything else | ✅ | ✅ |

---

## Dynamic content (admin-managed via wp_options)

All option keys are prefixed `lgms_guide_`. The admin dashboard at **Settings → Membership Guide** writes to these. Defaults render sensibly when options are empty.

| Option key | Type | Used by |
|---|---|---|
| `lgms_guide_preview_cards` | JSON array of `{ thumb_id, kind, title, url }` (4 items) | Preview slider |
| `lgms_guide_elders` | JSON array of `{ avatar_id, name, ig_url }` | Council slider |
| `lgms_guide_loothalong_url` | string (Zoom URL) | Loothalong member-state link |
| `lgms_guide_feed_video_url` | string (MP4 URL) | Feed scroll video |
| `lgms_guide_feed_poster_id` | int (attachment ID) | Feed video poster image |
| `lgms_guide_screenshots` | JSON map of `section_slug => [attachment_id, …]` | Screenshot sliders per section |

Image fields use WP media library uploader; we store the **attachment ID**, not the URL, so the renderer can pick the right size.

---

## Live Events block (CPT integration)

Shortcode: `[lg_upcoming_events count="4"]`.

**CPT:** `event` (registered by the theme/another plugin — not by this plugin).

**Meta keys used:**
- `events_start_date_and_time_` — string, format `YYYYMMDD` (e.g. `20260329`).
- `time_of_event` — string, format `HH:MM:SS` (24h).
- `event_tier_` — array of price IDs (used for tier-gating, not for this guide).

**Query:**

```php
new WP_Query([
    'post_type'      => 'event',
    'post_status'    => 'publish',
    'posts_per_page' => $count,
    'meta_key'       => 'events_start_date_and_time_',
    'orderby'        => 'meta_value',
    'order'          => 'ASC',
    'meta_query'     => [[
        'key'     => 'events_start_date_and_time_',
        'value'   => gmdate('Ymd'),
        'compare' => '>=',
        'type'    => 'NUMERIC',
    ]],
]);
```

**Rendering:** featured image as thumbnail (via `get_the_post_thumbnail_url($id, 'medium')`); date pill in top-left; day + time + title + meta below. If no featured image, falls back to gray placeholder + section icon.

**Timezone caveat:** `time_of_event` is stored as a naked string with no TZ. Render as ET in the front-end labels until we confirm site timezone handling.

---

## Loothalong gating

Server-side render skips the Zoom URL when `! is_user_logged_in()`. The URL is **never** emitted for non-members — not just CSS-hidden — so guests can't view-source it.

```php
if ( is_user_logged_in() ) {
    $url = get_option( 'lgms_guide_loothalong_url', '' );
    // render member-state card with $url
} else {
    // render guest-state card with /lgjoin/ CTA
}
```

---

## Council of Elders

Static list (names hardcoded — they don't change often). IG URLs come from `lgms_guide_elders` option. Names in default order:

1. Ian Davlin
2. Dan Erlewine
3. Michael Bashkin
4. James Rodaman
5. Doug Proper
6. Brock Poling
7. Massimiliano Montorosso

Each card has avatar + name + IG link. New elders go in via the admin dashboard.

---

## Sliders

Four horizontal sliders on the page: Upcoming events, Public preview (anon-only), Screenshot galleries (one per feature section), Elders.

CSS pattern:

```css
.slider {
  display: flex;
  gap: …;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  -webkit-overflow-scrolling: touch;
}
.slider > * { flex: 0 0 <fixed-width>; scroll-snap-align: start; }
```

Each slider gets a custom amber scrollbar via `::-webkit-scrollbar`. Cards have fixed widths so they don't compress to wrap.

---

## Lightbox

Click any `.shot` thumbnail → full-screen modal on dark backdrop. Click outside / press Escape to close. Implementation is a single fixed `.lightbox` overlay + ~20 lines of JS. Works for both placeholder text and real `<img>` thumbnails.

---

## Brand tokens

Defined in `:root`:

| Token | Hex | Use |
|---|---|---|
| `--cream` | `#FAF6EE` | Page background |
| `--sand` | `#EAE5DC` | Card / placeholder background |
| `--bg` | `#e8e2d8` | Outer body wash |
| `--dark` | `#2B2318` | Headings, hero, footer |
| `--ink` | `#5C4E3A` | Body text |
| `--amber` | `#ECB351` | Primary accent, sticky TOC, CTAs |
| `--amber-d` | `#C68A1E` | Subtitle text, dark accents |
| `--green` | `#87986A` | Inline link color |
| `--green-l` | `#D4E0B8` | Callout backgrounds |

Headings use Georgia serif. Body uses Arial/Helvetica.

---

## Editing this page in the future

**Layout / copy changes** → edit `templates/page/membership-guide.php`. Use the mockup at `welcome-email/membership-guide-mockup.html` as the visual reference if it still exists in the repo.

**Adding a new feature section** → copy an existing `<section>`, give it an `id`, add an entry to the TOC strip, append the new section ID to the `lgms_guide_screenshots` admin repeater list.

**Changing the public-preview cards** → admin dashboard (no code change).

**Changing the elders list** → admin dashboard (no code change).

**Re-seeding the WP page** → run `wp eval 'LGMS\\Wp\\Pages::ensureAll();'` on the server. Won't clobber manual edits because the registry only creates pages if they don't exist.

---

## Known follow-ups

- [ ] Real screenshots (currently placeholder gray boxes labeled with what they should show).
- [ ] Real public-preview cards (4 articles/shows TBD).
- [ ] Real Elder photos + IG handles (TBD).
- [ ] Loothalong Zoom URL (TBD).
- [ ] Feed scroll MP4 (TBD).
- [ ] Confirm `time_of_event` timezone with the events team.
- [ ] Optional: arrow buttons on sliders for desktop discoverability.
- [ ] Optional: filter `[lg_upcoming_events]` by event-type taxonomy.

---

## Last updated

This file is the canonical record. Update it when the structure changes — future chats will read it first.
