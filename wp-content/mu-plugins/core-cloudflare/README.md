# Core Cloudflare

**Version:** 1.0.0
**Plugin type:** WordPress / MU-plugin  
**Purpose:** Cloudflare zone management and cache purge for WordPress.

Core Cloudflare provides a WordPress admin UI for managing Cloudflare credentials, separating **Staging** and **Live** environments, discovering Cloudflare zones, purging cache for one or more domains, and reviewing operational logs.

---

## 1. What the plugin does

Core Cloudflare is designed around four admin screens:

1. **Dashboard** — environment and API status, configured domain count, warnings, and recent activity.
2. **Cache Purge** — purge one site, selected sites, or all domains in the active environment.
3. **Configuration** — configure Staging/Live credentials, domains, API timeout, and log retention.
4. **Logs** — filter, search, paginate, download, or clear plugin logs.

The plugin uses the Cloudflare REST API:

`https://api.cloudflare.com/client/v4`

Cloudflare API tokens are sent as Bearer tokens and are never displayed in full in the WordPress UI.

---

# 2. Admin UI overview

After the plugin is enabled, authorized administrators see:

```text
WordPress Admin
└── Cloudflare
    ├── Dashboard
    ├── Cache Purge
    ├── Configuration
    └── Logs
```

The exact menu location depends on WordPress mode:

- **Single-site:** WordPress Admin → Cloudflare
- **Multisite:** Network Admin → Cloudflare

Access is protected by the configured capability:

- Single-site: `manage_options`
- Multisite: `manage_network`

---

## 3. Dashboard UI

The Dashboard gives a quick health overview.

```text
┌──────────────────────────────────────────────────────────────┐
│ Cloudflare                                                   │
├──────────────┬──────────────┬────────────────────────────────┤
│ Environment  │ API Status   │ Total Configured Domains       │
│ Staging      │ Configured   │ 3                              │
└──────────────┴──────────────┴────────────────────────────────┘

Configuration and Cloudflare access warnings:
  • Staging: Cloudflare API token is active.
  • Staging: Cloudflare zone access is available.

Recent Activity
┌────────────┬───────────────┬─────────────┬───────────────────┐
│ Time       │ Domain        │ HTTP Status │ Note              │
├────────────┼───────────────┼─────────────┼───────────────────┤
│ ...        │ staging.site  │ 200         │ Cache purge OK    │
└────────────┴───────────────┴─────────────┴───────────────────┘
```

The Dashboard also reports credential and Cloudflare access warnings so an administrator can identify configuration problems before attempting a purge.

---

# 4. Configuration UI

Go to:

**Cloudflare → Configuration**

The configuration screen contains:

```text
┌──────────────────────────────────────────────────────────────┐
│ Configuration                                                │
├──────────────────────────────────────────────────────────────┤
│ Environment                                                  │
│ [ Staging ▼ ]                                                │
│                                                              │
│ Cloudflare API Token                                         │
│ 🔒 •••••••••••••1234                                        │
│                                                              │
│ Cloudflare Account ID                                        │
│ 🔒 •••••••••••••5678                                        │
│                                                              │
│ [ Delete Staging Credentials ]                               │
│                                                              │
│ Staging Domains                                              │
│ ┌──────────────────────────────────────────────────────────┐ │
│ │ staging.example.com                                      │ │
│ │ staging2.example.com                                     │ │
│ └──────────────────────────────────────────────────────────┘ │
│                                                              │
│ Live Domains                                                 │
│ ┌──────────────────────────────────────────────────────────┐ │
│ │ www.example.com                                           │ │
│ └──────────────────────────────────────────────────────────┘ │
│                                                              │
│ API Timeout (seconds)       [ 30 ]                            │
│ Log Retention (days)        [ 30 ]                            │
│                                                              │
│                         [ Save Settings ]                     │
└──────────────────────────────────────────────────────────────┘
```

### Environment selector

Choose either:

- **Staging**
- **Live**

Only the selected environment is active for cache purge operations.

The plugin keeps credentials separately for each environment.

### Staging and Live domain separation

Domains are stored separately:

```text
Staging
  staging.example.com
  test.example.com

Live
  www.example.com
  example.com
```

The selected environment wins if a domain is moved between environment lists. A domain selected for Live is removed from the Staging list, and vice versa.

The plugin also validates environment/domain assignments before saving.

### Credentials

Each environment can have its own:

- Cloudflare API Token
- Cloudflare Account ID

Existing credentials are masked in the UI.

For example:

```text
API Token:
••••••••••••••••1234

Account ID:
••••••••••••5678
```

The full API token is not round-tripped into the page.

### Replacing a token

When a new API token is entered:

1. The submitted token is verified directly with Cloudflare.
2. The token is only saved after successful verification.
3. The cached zone map is cleared when the token changes.
4. Cloudflare zones are refreshed using the new token.
5. Credential/access warnings are recalculated.

This prevents an old invalid-token warning or old zone map from being reused after credentials are replaced.

### Deleting credentials

The **Delete Staging Credentials** / **Delete Live Credentials** button removes the credentials for the selected environment and clears the cached zone map.

---

# 5. Cloudflare API Token permissions

Create a Cloudflare API Token with the permissions required by your deployment.

At minimum, Core Cloudflare needs token verification and zone access to discover zones.

For cache purge functionality, the token must also have:

- **Zone Read**
- **Cache Purge**

The exact Cloudflare token scope should be limited to the required zones/accounts rather than granting unnecessary permissions.

### Recommended approach

Create separate tokens for Staging and Live when the two environments use different Cloudflare access scopes.

Example:

```text
Staging token
  └── Access only to staging/test zones
  └── Zone Read
  └── Cache Purge

Live token
  └── Access only to production zones
  └── Zone Read
  └── Cache Purge
```

This reduces the risk of accidentally purging the wrong environment.

---

# 6. Cache Purge UI

Go to:

**Cloudflare → Cache Purge**

The screen lists the domains configured for the currently selected environment.

```text
┌──────────────────────────────────────────────────────────────┐
│ Cache Purge                                      3 domains   │
│ Clear Cloudflare cache for the domains in the active         │
│ environment.                                                 │
│                                                              │
│ [ Purge All Domains ] [ Purge Selected ]     2 selected      │
├──────────────────────────────────────────────────────────────┤
│ Configured domains                         [ Select all ]     │
├────┬──────────────────────────────┬───────────────────────────┤
│ □  │ staging.example.com          │ [ Purge This Site ]       │
│ □  │ staging2.example.com         │ [ Purge This Site ]       │
│ □  │ test.example.com             │ [ Purge This Site ]       │
└────┴──────────────────────────────┴───────────────────────────┘
```

### Available actions

**Purge This Site**

Purges one domain.

**Purge Selected**

Purges only the checked domains.

**Purge All Domains**

Purges every domain configured for the active environment.

### Purge workflow

For each domain, the plugin:

1. Normalizes and validates the domain.
2. Confirms the domain belongs to the active environment.
3. Finds the corresponding Cloudflare zone.
4. Sends the Cloudflare purge request.
5. Records the result in the log.
6. Continues processing other domains if one domain fails.

A single domain failure does not stop the entire batch.

### Important safety behavior

The plugin does not silently purge an unresolved domain.

If the domain cannot be mapped to a Cloudflare zone accessible by the active token, the purge is skipped and a clear error is returned.

---

# 7. Zone discovery

Core Cloudflare maintains a cached map of Cloudflare zones.

When zones are refreshed, the plugin calls:

```text
GET /zones
```

It supports pagination and stores:

```text
domain
zone_id
status
updated_at
```

A domain can match its exact zone or a parent zone.

Example:

```text
Configured domain:
www.example.com

Cloudflare zone:
example.com

Result:
www.example.com → example.com → zone_id
```

If the active token cannot access the required zone, the plugin reports that instead of using an unrelated cached zone.

A new token automatically clears the previous zone map before refreshing zones.

---

# 8. Logs UI

Go to:

**Cloudflare → Logs**

The Logs screen supports:

- Start date
- End date
- Domain
- Status
- Search
- Pagination
- Clear Logs

Example:

```text
┌────────────┬────────────┬───────────────┬──────────┐
│ Start      │ End        │ Domain        │ Status   │
│ [date]     │ [date]     │ [example.com] │ [All ▼]  │
└────────────┴────────────┴───────────────┴──────────┘

[ Search... ] [ Filter ] [ Clear Logs ]

┌────────────┬───────────────┬────────────┬─────────────────────┐
│ Timestamp  │ Domain        │ HTTP       │ Note                │
├────────────┼───────────────┼────────────┼─────────────────────┤
│ ...        │ example.com   │ 200        │ Purge successful    │
│ ...        │ test.com      │ 403        │ Permission denied   │
└────────────┴───────────────┴────────────┴─────────────────────┘
```

Logs are stored as daily JSON Lines files:

```text
core-cloudflare/logs/
├── core-cloudflare-YYYY-MM-DD.log
├── index.php
└── .htaccess
```

The log directory is protected against direct web access using both `index.php` and `.htaccess` where supported.

Logs can also be downloaded from the admin UI.

---

# 9. Log retention

The Configuration screen includes:

**Log Retention (days)**

Use this to control how long operational logs are retained.

Keep the value appropriate for your site's troubleshooting and compliance requirements.

---

# 10. Error handling

Core Cloudflare is designed to show environment-specific errors instead of generic AJAX errors.

Examples:

### Missing token

```text
Staging: Cloudflare API token is empty.
Enter a valid Cloudflare API Token.
```

### Invalid token

```text
Live: Cloudflare API token is invalid, expired, disabled,
or not authorized. Replace it with an active token.
```

### Zone access problem

```text
Live: Cloudflare token is missing Zone Read permission
or the configured Account ID is not accessible.
Purge is blocked until zone access is fixed.
```

### Domain not found

```text
The configured domain does not match any Cloudflare zone
accessible by the current API token.
```

### Network timeout

```text
Cloudflare API request timed out.
The domain was not changed; you can retry the purge.
```

### Rate limit

```text
Cloudflare rate limit reached.
No changes were made. Please wait a moment and retry.
```

---

# 11. Token replacement and stale warning prevention

A common operational problem is:

```text
Old invalid token
      ↓
Warning displayed
      ↓
Delete old token
      ↓
Enter new valid token
      ↓
Old warning remains
```

Core Cloudflare avoids this flow by verifying the newly submitted token before saving it.

The replacement sequence is:

```text
New token entered
      │
      ▼
Verify new token with Cloudflare
      │
      ├── Invalid ──► Do not save
      │
      ▼
Save new token
      │
      ▼
Clear old zone map
      │
      ▼
Refresh zones
      │
      ▼
Re-check credentials
      │
      ▼
Display current status
```

This ensures that a successful credential replacement is reflected in the UI immediately.

---

# 12. Domain input rules

Domains may be entered as:

```text
example.com
www.example.com
https://www.example.com
```

The plugin normalizes domains to hostnames for internal matching.

It removes protocol, path, and port information where appropriate.

### Recommended format

Use one domain per line:

```text
staging.example.com
staging2.example.com
```

or:

```text
https://www.example.com
https://example.com
```

Commas and semicolons are also accepted as separators.

Avoid concatenating domains accidentally:

```text
❌ example.comtest.example.com
```

This is intentionally validated instead of silently repaired.

---

# 13. Installation

## Standard WordPress / MU-plugin installation

The supplied package contains:

```text
core-cloudflare.php
core-cloudflare/
├── assets/
├── includes/
└── logs/
```

For a WordPress MU-plugin installation, copy the package contents so that the bootstrap file is available under:

```text
wp-content/mu-plugins/core-cloudflare.php
```

and the supporting directory is:

```text
wp-content/mu-plugins/core-cloudflare/
```

Final structure:

```text
wp-content/
└── mu-plugins/
    ├── core-cloudflare.php
    └── core-cloudflare/
        ├── assets/
        ├── includes/
        └── logs/
```

The plugin is automatically loaded by WordPress as an MU-plugin.

---

# 14. First-time setup

After installation:

### Step 1 — Open Configuration

Go to:

**Cloudflare → Configuration**

### Step 2 — Select environment

Choose:

```text
Staging
```

or:

```text
Live
```

### Step 3 — Enter Cloudflare credentials

Enter:

- API Token
- Account ID, if used by your configuration

### Step 4 — Add domains

Add the domains belonging to that environment.

### Step 5 — Save

Click:

**Save Settings**

The plugin verifies a newly entered token before storing it.

### Step 6 — Review zone access

Return to:

**Dashboard**

Confirm that:

- API status is configured.
- Token status is active.
- Zone access is available.
- The required zones are visible.

### Step 7 — Purge

Open:

**Cache Purge**

Select a domain and use:

**Purge This Site**

---

# 15. Recommended Staging/Live setup

A safe production setup looks like:

```text
                    Core Cloudflare
                          │
              ┌───────────┴───────────┐
              │                       │
          STAGING                    LIVE
              │                       │
      staging token             live token
              │                       │
      staging zones              live zones
              │                       │
      staging domains            live domains
```

Example:

```text
STAGING
  Token: staging-specific token
  Domains:
    staging.example.com
    test.example.com

LIVE
  Token: production-specific token
  Domains:
    example.com
    www.example.com
```

Use separate credentials where possible.

---

# 16. Multisite behavior

The plugin detects WordPress multisite automatically.

The management capability becomes:

```php
manage_network
```

instead of:

```php
manage_options
```

The plugin also uses network-level settings/options for its configuration and cached operational data.

Therefore, in multisite environments, administrators should configure Core Cloudflare from the Network Admin area.

---

# 17. Security

Core Cloudflare includes several security controls:

- WordPress capability checks.
- AJAX nonce verification.
- Credential masking in the admin UI.
- API token verification before replacement.
- No full token round-trip into page HTML.
- Bearer authentication for Cloudflare API requests.
- Protected log directory.
- Sanitization and normalization of domain input.
- Environment validation.
- Zone validation before purge.
- Cached zone map cleared when credentials change.
- Errors are written to operational logs for troubleshooting.

Do not place Cloudflare API tokens in public source control, screenshots, support tickets, or client-facing documentation.

---

# 18. Troubleshooting

## "API token is invalid"

Check:

1. The token is active in Cloudflare.
2. The token has the required permissions.
3. The token belongs to the intended Cloudflare account.
4. The correct environment is selected.
5. The token was not accidentally copied with extra text.

The UI accepts a pasted `Bearer <token>` value and normalizes it before verification.

---

## "Zone access is unavailable"

Check:

1. Zone Read permission.
2. Token zone scope.
3. Cloudflare Account ID.
4. Whether the requested domain is actually a zone accessible by the token.

Then save the credentials again so the plugin can refresh the zone map.

---

## "Domain does not match any Cloudflare zone"

Confirm that the domain exists as a zone in the Cloudflare account accessible by the active environment's token.

For example:

```text
Configured:
www.example.com

Expected Cloudflare zone:
example.com
```

The plugin supports matching a hostname to its parent zone.

---

## "Purge permission denied"

The token must include:

```text
Cache Purge
```

Verify the token's permission and zone scope in Cloudflare.

---

## "Old token warning still appears"

Use this sequence:

1. Select the correct environment.
2. Delete the environment credentials if required.
3. Enter the new token.
4. Save Settings.
5. Confirm the token is verified successfully.
6. Check Dashboard status.

A newly submitted token is verified before it is persisted.

---

## "AJAX error"

For settings validation, validation errors are returned as normal JSON responses so the UI can display the exact field/environment message.

If an unexpected server error occurs:

1. Check the WordPress PHP error log.
2. Check **Cloudflare → Logs**.
3. Confirm the active environment.
4. Confirm the token and zone permissions.
5. Retry after correcting the reported configuration.

---

# 19. Cloudflare API endpoints used

The plugin uses the Cloudflare v4 API base:

```text
https://api.cloudflare.com/client/v4
```

Relevant operations include:

```text
GET  /user/tokens/verify
GET  /zones
POST /zones/{zone_id}/purge_cache
```

The plugin uses the WordPress HTTP API for outbound requests.

---

# 20. Developer integration

The code is organized into focused services.

```text
core-cloudflare/includes/
├── class-admin-ui.php
├── class-cache-purge.php
├── class-cloudflare-api.php
├── class-config.php
├── class-encryptor.php
├── class-loader.php
├── class-logger.php
├── class-settings.php
├── class-zone-manager.php
└── helpers.php
```

### Main responsibilities

| Class | Responsibility |
|---|---|
| `Admin_UI` | WordPress admin pages and AJAX actions |
| `Cache_Purge` | Purge orchestration and per-domain results |
| `Cloudflare_API` | Cloudflare HTTP transport and API response handling |
| `Config` | Environment configuration and persistence |
| `Encryptor` | Credential protection |
| `Loader` | Service/bootstrap loading |
| `Logger` | Daily JSONL operational logs |
| `Settings` | Admin-facing configuration data |
| `Zone_Manager` | Zone discovery, mapping, and validation |
| `helpers.php` | Small stateless helpers |

---

# 21. Useful hooks

The plugin exposes extension points including:

```php
core_cloudflare_capability
core_cloudflare_log_entry
core_cloudflare_before_purge
core_cloudflare_after_purge
```

These can be used by custom integrations without modifying the plugin's core classes.

---

# 22. Operational workflow

The intended production workflow is:

```text
Configure environment
        │
        ▼
Verify Cloudflare token
        │
        ▼
Check Zone Read access
        │
        ▼
Refresh / map zones
        │
        ▼
Validate configured domains
        │
        ▼
Purge selected domain(s)
        │
        ▼
Record result
        │
        ▼
Review Logs if required
```

---

# 23. File-by-file code map

This section explains **which file does what** so developers can quickly find the correct place to make a change.

## File responsibilities

| File | What it does |
|---|---|
| `core-cloudflare.php` | Main WordPress/MU-plugin bootstrap; loads the plugin, registers core services/hooks, and starts the application. |
| `core-cloudflare/assets/css/admin.css` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/assets/js/admin.js` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-admin-ui.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-cache-purge.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-cloudflare-api.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-config.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-encryptor.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-loader.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-logger.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-settings.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/class-zone-manager.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/includes/helpers.php` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/logs/.htaccess` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/logs/core-cloudflare-2026-08-12.log` | Supporting package file; see its implementation for the specific role. |
| `core-cloudflare/logs/index.php` | Supporting package file; see its implementation for the specific role. |

## How the files work together

```text
core-cloudflare.php
        │
        ▼
class-loader.php
        │
        ├── class-config.php
        ├── class-settings.php
        ├── class-cloudflare-api.php ──► Cloudflare REST API
        ├── class-zone-manager.php ────► Zone discovery / mapping
        ├── class-cache-purge.php ──────► Cache purge
        ├── class-logger.php ───────────► Logs
        └── class-admin-ui.php ─────────► WordPress Admin UI
                    │
          ┌─────────┼─────────┐
          ▼         ▼         ▼
      Dashboard  Config   Cache Purge
                              │
                              ▼
                            Logs
```

### Saving credentials

```text
Admin UI
  → Settings
  → Config
  → Cloudflare API
  → Token verification
  → Save credentials
  → Clear old zone cache
  → Refresh zones
  → Logger
```

### Purging a domain

```text
Admin UI
  → Cache Purge
  → Zone Manager
  → Cloudflare API
  → purge_cache
  → Logger
  → UI result
```

---

# 24. Where to make common changes

| Change | Main file(s) |
|---|---|
| Dashboard layout/text | `includes/class-admin-ui.php` |
| Configuration form | `includes/class-admin-ui.php`, `includes/class-settings.php` |
| Saving settings | `includes/class-settings.php`, `includes/class-config.php` |
| Staging/Live logic | `includes/class-config.php` |
| Cloudflare API endpoints | `includes/class-cloudflare-api.php` |
| API authentication/errors | `includes/class-cloudflare-api.php` |
| Zone discovery/matching | `includes/class-zone-manager.php` |
| Cache purge behavior | `includes/class-cache-purge.php` |
| Logs and retention | `includes/class-logger.php` |
| Credential protection | `includes/class-encryptor.php` |
| AJAX/UI interactions | `assets/js/admin.js` |
| Admin design/CSS | `assets/css/admin.css` |
| Domain validation/helpers | `includes/helpers.php` |
| Plugin startup/loading | `core-cloudflare.php`, `includes/class-loader.php` |
| Log directory protection | `logs/.htaccess`, `logs/index.php` |

---

# 25. Package structure

```text
├── core-cloudflare.php
├── core-cloudflare/assets/css/admin.css
├── core-cloudflare/assets/js/admin.js
├── core-cloudflare/includes/class-admin-ui.php
├── core-cloudflare/includes/class-cache-purge.php
├── core-cloudflare/includes/class-cloudflare-api.php
├── core-cloudflare/includes/class-config.php
├── core-cloudflare/includes/class-encryptor.php
├── core-cloudflare/includes/class-loader.php
├── core-cloudflare/includes/class-logger.php
├── core-cloudflare/includes/class-settings.php
├── core-cloudflare/includes/class-zone-manager.php
├── core-cloudflare/includes/helpers.php
├── core-cloudflare/logs/.htaccess
├── core-cloudflare/logs/core-cloudflare-2026-08-12.log
├── core-cloudflare/logs/index.php
```

---

# 26. Development guidance

Keep Cloudflare HTTP/API logic inside `class-cloudflare-api.php`.

Keep Staging/Live configuration logic inside `class-config.php`.

Keep WordPress settings registration and persistence inside `class-settings.php`.

Keep zone discovery and domain-to-zone matching inside `class-zone-manager.php`.

Keep purge orchestration inside `class-cache-purge.php`.

Keep logging inside `class-logger.php`.

Keep UI markup/actions inside `class-admin-ui.php`.

Keep browser-side interaction inside `assets/js/admin.js`.

Keep visual changes inside `assets/css/admin.css`.

This separation makes the plugin easier to maintain and reduces the chance that a change to one feature breaks another feature.

---

# 27. Version history

## 1.0.0 — Initial plugin release

**1.0.0** is the initial release of Core Cloudflare.

The initial plugin introduced the core Cloudflare management functionality, including:

- WordPress admin integration
- Staging and Live environment configuration
- Cloudflare API token configuration
- Cloudflare zone discovery
- Domain/environment separation
- Cache purge functionality
- Dashboard status information
- Configuration management
- Operational logging
- Basic security and capability checks

This version is the baseline release from which later fixes and improvements are tracked.

## 1.0.0 — Current version

Version **1.0.0** contains improvements and fixes around:

- Cloudflare credential validation
- Staging/Live environment handling
- Zone cache refresh after credential changes
- More accurate configuration warnings
- Improved error handling
- Better domain/zone validation
- UI and operational reliability improvements

**Current plugin version:**

1.0.0

## Support checklist

Before reporting a Cloudflare purge problem, collect:

- WordPress version
- PHP version
- Single-site or multisite
- Active environment: Staging or Live
- Domain being purged
- Cloudflare token status
- Zone Read status
- Cache Purge permission
- Account ID configuration status
- Relevant Cloudflare log entry
- Relevant WordPress/PHP error-log entry

**Never include the full Cloudflare API token in a support request.**

---

## Summary

Core Cloudflare provides a controlled WordPress interface for Cloudflare cache management with explicit **Staging vs Live separation**, credential verification, zone discovery, per-domain validation, resilient batch purging, masked credentials, and searchable operational logs.
