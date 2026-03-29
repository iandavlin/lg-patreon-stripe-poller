# LG Patreon Onboard

One-time Patreon OAuth onboarding plugin for The Looth Group. Creates WordPress accounts with correct roles based on Patreon membership tier.

## What It Does

1. Member clicks "Connect Your Patreon Account" button on a page (shortcode)
2. Gets redirected to Patreon to authorize
3. Plugin verifies they're an active patron of your campaign
4. Checks their tier → maps to WordPress role (looth1–looth4)
5. Creates their WordPress account
6. Sends them a password-setup email
7. Stores Patreon User ID as the identity anchor (NOT email)

## What It Handles

- **Happy path**: New user, active patron, valid tier → account created, role set, email sent
- **Already onboarded**: Patreon ID already in system → updates role, shows "you're all set"
- **Not a patron**: No active pledge → shows error with link to your Patreon membership page
- **Email collision**: Patreon email matches existing WP user without Patreon link → flags for manual review, emails you
- **Different Patreon linked**: Existing WP user already linked to a DIFFERENT Patreon ID → flags for manual review

## Security

- **CSRF protection**: Random `state` parameter stored as transient, verified on callback
- **Identity anchor**: Patreon User ID (immutable), NOT email address — prevents the hijack issue from the old Codebard plugin
- **No early cookies**: Auth cookies are never set during the OAuth flow — WP handles login separately
- **No Patreon passwords**: Plugin never sees or touches Patreon credentials

## Setup

### 1. Register OAuth Client on Patreon

Go to: https://www.patreon.com/portal/registration/register-clients

- **App Name**: The Looth Group
- **Description**: Account activation for loothgroup.com
- **Redirect URIs**: `https://loothgroup.com/patreon-callback/`
- Note your **Client ID** and **Client Secret**

### 2. Get Your Campaign ID

Using your Creator Access Token (shown on the same clients page), run:

```bash
curl -H "Authorization: Bearer YOUR_CREATOR_TOKEN" \
  "https://www.patreon.com/api/oauth2/v2/campaigns?fields%5Bcampaign%5D=creation_name" \
  -H "User-Agent: LoothGroup-Onboard/1.0"
```

The `id` field in the response is your campaign ID.

### 3. Get Your Tier IDs

```bash
curl -H "Authorization: Bearer YOUR_CREATOR_TOKEN" \
  "https://www.patreon.com/api/oauth2/v2/campaigns/YOUR_CAMPAIGN_ID?include=tiers&fields%5Btier%5D=title,amount_cents" \
  -H "User-Agent: LoothGroup-Onboard/1.0"
```

This returns your tiers with their IDs and titles. Note each tier ID.

### 4. Install Plugin

Upload the `lg-patreon-onboard` folder to `wp-content/plugins/` and activate.

Or via WP-CLI:
```bash
# Copy to plugins directory
cp -r lg-patreon-onboard /path/to/wp-content/plugins/

# Activate
wp plugin activate lg-patreon-onboard
```

### 5. Configure Settings

Go to **Settings → Patreon Onboard** in WP admin.

- **Client ID**: From step 1
- **Client Secret**: From step 1
- **Redirect URI**: `https://loothgroup.com/patreon-callback/`
- **Campaign ID**: From step 2
- **Patreon Membership Link**: `https://www.patreon.com/cw/theloothgroup/membership`
- **Contact Email**: `ian.davlin@gmail.com`

Add your tier mappings:
| Patreon Tier ID | WordPress Role |
|-----------------|---------------|
| (your looth lite tier ID) | looth1 |
| (your looth pro tier ID) | looth2 |
| etc. | etc. |

### 6. Flush Rewrite Rules

The plugin does this on activation, but if `/patreon-callback/` gives a 404:

```bash
wp rewrite flush
```

### 7. Create the Onboarding Page

Create a WordPress page (e.g., `/activate/`) and add the shortcode:

```
[lg_patreon_onboard]
```

This renders the styled "Connect Your Patreon Account" button.

### 8. Update Your Patreon Welcome Message

Link new patrons to your activation page, e.g.:

> Welcome to The Looth Group! To activate your full account on loothgroup.com — with forums, loothprints, the member directory, and more — visit: https://loothgroup.com/activate/

## Shortcode

```
[lg_patreon_onboard]
```

- Shows "Connect Your Patreon Account" button for logged-out users and logged-in users without a Patreon link
- Shows "Already connected" message for users who are linked
- Styled with Looth Group brand colors (Gold #ECB351, Mint #87986A, Coral #FE6A4F, Dark #1A1E12)

## User Meta Stored

| Meta Key | Value |
|----------|-------|
| `lgpo_patreon_user_id` | Patreon's immutable user ID (the identity anchor) |
| `lgpo_patreon_email` | Email at time of onboarding |
| `lgpo_patreon_tier_id` | Tier ID at time of onboarding |
| `lgpo_onboarded_at` | Timestamp of account creation |

## Admin Features

- **Settings page**: Settings → Patreon Onboard
- **Pending Review Queue**: Shows email collisions, with "Link Accounts" and "Dismiss" buttons
- **Email notifications**: You get an email when a collision occurs

## Future-Proofing for Stripe

The role mapping is a standalone function. When you add Stripe:

1. Stripe webhook handler calls the same role-mapping logic
2. Login system unchanged (it's just WordPress auth)
3. Patreon onboard page remains as legacy bridge for existing Patreon members
4. New members go through Stripe checkout → account created on loothgroup.com directly

## CSV Reconciliation

This plugin handles **onboarding only**. Your existing CSV cron handles:
- Role updates for tier changes between logins
- Deactivation/downgrade for cancelled patrons
- Periodic reconciliation

The two systems complement each other: OAuth for first-time activation, CSV for ongoing sync.
