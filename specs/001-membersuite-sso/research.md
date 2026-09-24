# Research: MemberSuite SSO and Member Access

**Feature**: `001-membersuite-sso`  
**Date**: 2026-09-21

## 1. MemberSuite Outside SSO (Option 1) flow

**Decision**: Implement MemberSuite REST Outside SSO (Option 1) end-to-end: credential check via `loginUser`, SSO handshake via `JWTSSO`, identity resolution via `bearerTokenSSO` + `whoami`, then WordPress session establishment. Do not store or validate MemberSuite passwords in WordPress.

**Rationale**: Constitution Principle II and FR-001 require Outside SSO only. Official GrowthZone REST docs (March 2024) define Option 1 as the third-party login-page model that matches an embedded shortcode form.

**Flow (server-mediated)**:

1. Visitor submits email/password on the shortcode form (POST to plugin handler with nonce).
2. Plugin `POST https://rest.membersuite.com/platform/v2/loginUser/{tenantId}` with JSON `{ "email", "password" }` via `wp_remote_post` (HTTPS, explicit timeout).
3. On success, response includes `idToken`, `accessToken`, `refreshToken`.
4. Plugin `POST https://rest.membersuite.com/platform/v2/JWTSSO/{tenantId}` (form-urlencoded) with those tokens, `nextUrl` = plugin SSO callback URL (includes return-target state), `IsSignUp=false`, `IsForgotPassword=false`.
5. Capture `tokenGUID` from the `Location` redirect URL (tokenGUID valid ~5 minutes). Prefer extracting server-side so the visitor need not complete an intermediate MemberSuite browser hop when only WordPress session is required; if MemberSuite requires an MRP browser session for the tenant, redirect the browser to the Location URL and land on `nextUrl`.
6. On callback: `GET /platform/v2/bearerTokenSSO?tokenGUID=…&partitionKey={tenantId}` → SSO `idToken` (~1 hour).
7. `GET /platform/v2/whoami` with `Authorization: Bearer {idToken}` → `userId`, `ownerId`, `email`, `firstName`, `lastName`, `membershipId`, `receivesMemberBenefits`, etc.
8. Optionally enrich profile via `GET /crm/v1/individuals/{tenantId}?msql=…` using `ownerId`.
9. Provision/link WordPress user by stable `ownerId` (Individual ID), sync profile + benefits flag, assign role, set auth cookie, redirect to remembered return URL.

**Alternatives considered**:

- **loginUser + whoami only (skip JWTSSO)**: Simpler, but diverges from documented Option 1 and may miss intended SSO session semantics. Rejected for v1.
- **Reverse SSO (Option 2)**: Out of scope per constitution.
- **Legacy SOAP Concierge SDK**: Older keypair/session model; constitution requires REST Outside SSO.

## 2. Stable identity linking

**Decision**: Link WordPress users by MemberSuite Individual `ownerId` (GUID from `whoami`), stored in prefixed user meta. Never use email alone as the link key. Lookup order: existing user with matching `ownerId` meta → reuse; else create new user. If email collides with an unlinked WP user, do not auto-merge by email; create/link only via `ownerId` rules (and surface a safe error if policy blocks create). Prefer unique usernames derived from email local-part with collision suffix.

**Rationale**: FR-003 and Principle II forbid email-only matching so identity stays auditable when emails change.

**Alternatives considered**: Email-first linking (common but unsafe). Soft-merge on email (ambiguous ownership). Rejected.

## 3. Role mapping

**Decision**: Custom role slug `msebaa_member` (display name “Member”). Mapping: `receivesMemberBenefits === true` → set role to `msebaa_member`; otherwise → `subscriber`. Never overwrite privileged roles (`administrator`, and any role with `manage_options` / `edit_users` as a safety net). Treat `null`/`false` benefits as non-member (subscriber).

**Rationale**: Spec clarification and FR-005 require one deterministic mapping; privileged roles must be preserved for operational admins.

**Alternatives considered**: Capability-only checks without a custom role (harder for themes/plugins). Multiple membership-type roles (out of scope for v1).

## 4. Disabling WordPress password login for linked users

**Decision**: On `authenticate`, if the resolved user has MemberSuite link meta, return a `WP_Error` directing them to the SSO form. Also filter `allow_password_reset` to false for linked users. Unlinked users (including administrators) keep normal WP login.

**Rationale**: FR-001 / SC-012. WordPress provides `authenticate` and `allow_password_reset` filters for this.

**Alternatives considered**: Removing passwords on link (`wp_set_password` random) without blocking authenticate (still allows reset flows). Incomplete alone; combine mark + authenticate block.

## 5. Theme helper surface

**Decision**: Publish seven prefixed helpers (within FR-006’s 6–8 range), all safe after `init`, no output, no fatals:

| Helper | Purpose | Safe default |
|--------|---------|--------------|
| `msebaa_is_user_logged_in()` | WP session present | `false` |
| `msebaa_is_member()` | Logged in + receives benefits (or admin operational override for gating helpers only where documented) | `false` |
| `msebaa_receives_member_benefits()` | Cached benefits flag | `false` |
| `msebaa_get_current_member()` | Array of core profile fields or `null` | `null` |
| `msebaa_get_member_field( string $key )` | Single profile field | `''` |
| `msebaa_get_login_url( string $redirect = '' )` | Configured login page URL + optional redirect arg | Home URL fallback |
| `msebaa_get_logout_url( string $redirect = '' )` | WP logout URL | Home URL |

**Rationale**: Constitution Principle IV; themes must not touch the API client.

**Alternatives considered**: OOP facades for themes (violates “functions as public contract”). REST endpoints for themes (unnecessary for PHP templates).

## 6. Caching and fail-closed gating

**Decision**: Store last-known membership/profile snapshot in user meta (authoritative mirror for the session) and short-lived transients (`msebaa_member_{user_id}`, TTL e.g. 15–60 minutes) for hot reads. Invalidate on SSO success and any explicit refresh. Theme helpers read cache/meta only — never call MemberSuite per template tag. Members-only checks use the same cached benefits flag. If no usable cached/synced status exists for a signed-in user, **fail closed** (deny). If MemberSuite is down mid-session but last-known status exists, use last-known (per edge cases).

**Rationale**: Principles V–VI and FR-007 / FR-015.

**Alternatives considered**: Live API on every page view (availability risk). Fail-open when unknown (security risk). Rejected.

## 7. Members-only posts/pages

**Decision**: Post meta key `_msebaa_members_only` (`'1'` / absent). Classic meta box + block editor compatible checkbox for `post`, `page`, and `attachment`. Save gated by `edit_post` (or equivalent for the object) + nonce. On `the_content` (singular only), if flagged and requester not entitled, replace body with translatable denial message + login link carrying return URL. Do not strip titles from archives (per assumptions).

**Rationale**: FR-008–FR-011; capability = can edit the item.

**Alternatives considered**: Custom taxonomy for gating (heavier). `template_redirect` to a dedicated denial template (worse UX than same-URL message).

## 8. Members-only media / direct URL protection

**Decision**: When an attachment is members-only, filter `wp_get_attachment_url` / related URL filters so public links point to a plugin download route (e.g. `?msebaa_download={attachment_id}` or rewrite). Handler verifies entitlement (member benefits or `manage_options`), then streams the file with correct headers. Unauthorized requests redirect to configured login page with denial query arg + return target. Also guard attachment singular templates via `template_redirect`.

**Rationale**: Direct `/wp-content/uploads/…` URLs bypass PHP if the real path is known; rewriting the published URL to a gated endpoint is the WordPress-native mitigation without requiring server config. Document that previously shared raw upload URLs may still hit the filesystem unless the host adds deny rules — optional hardening note in quickstart, not a v1 hard dependency.

**Alternatives considered**: Moving files outside webroot on flag (more robust, more complex moves/permissions). `.htaccess`-only (not portable across nginx/Local). Rejected as sole strategy.

## 9. Return-after-login

**Decision**: Pass sanitized return URL as a query arg on the login page / SSO form (e.g. `msebaa_redirect`). Persist in a short-lived transient or signed cookie during SSO callback. After successful member sign-in, `wp_safe_redirect` to that URL if local; else login page. Works for both post/page message links and media denial redirects (FR-011, FR-014).

**Rationale**: Spec clarification requires return to original resource.

**Alternatives considered**: Always send to homepage. Rejected by clarification.

## 10. Admin settings & secrets

**Decision**: Settings API page under Settings (or top-level “MemberSuite”) storing `msebaa_settings`: `tenant_id` (partition key), optional association ID for display, `login_page_id`, request timeout. No API secrets required for Outside SSO user login beyond tenant ID (user credentials are submitted per session). Never commit credentials; never echo tokens to the browser or logs.

**Rationale**: FR-016; Principle III. Outside SSO uses the member’s own credentials to `loginUser`, not a stored API user password in the form flow. If a service account is later needed for background refresh, store it encrypted/options outside VCS — out of scope unless profile refresh without user tokens is required. v1 syncs profile at login only; cache TTL covers freshness between visits.

**Alternatives considered**: Storing a persistent API-user password for cron refresh (useful later; not required for v1 acceptance if login sync + cache suffice).

## 11. Testing approach

**Decision**: Manual acceptance against MemberSuite sandbox per constitution quality gates. Automated: PHPUnit + WordPress test bootstrap (or `wp-env`) for helpers, role mapping, meta gating, authenticate filter, and download entitlement with mocked HTTP (`pre_http_request`). No live credentials in CI.

**Rationale**: Auth paths must be sandbox-verified; unit tests cover deterministic WP logic.

**Alternatives considered**: CI-only live API tests (flaky/secret-heavy). Rejected.

## 12. Plugin layout

**Decision**: Standard constitution layout — bootstrap `membersuite-ebaa.php`, `includes/`, `admin/`, `public/`, `languages/`, `uninstall.php`. Prefix `msebaa_` / `Msebaa_` / text domain `membersuite-ebaa`.

**Rationale**: Already mandated; greenfield repo has no conflicting structure.
