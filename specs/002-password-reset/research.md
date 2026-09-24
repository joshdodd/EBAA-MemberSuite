# Research: MemberSuite Password Reset Email

**Feature**: `002-password-reset`  
**Date**: 2026-09-21

## 1. How to trigger MemberSuite’s password-reset email

**Decision**: Implement a server-side flow that (1) authenticates as the dedicated API service account to obtain a Bearer `idToken`, then (2) calls a MemberSuite platform/security operation that requests the standard password-reset email for the visitor-supplied address. Abstract the second step behind `Msebaa_Api_Client::request_password_reset_email( string $email )` so the concrete path can be confirmed against sandbox Swagger / CSM without changing UX contracts.

**Rationale**: Visitors who forgot their password cannot complete `loginUser` with member credentials. Public GrowthZone REST docs (March 2024) document API-user `loginUser` → `idToken` (~5 hours) for non-SSO calls with `Authorization: Bearer <idToken>`, but do not publish a clear “forgot password” REST example. Portal `ForgotPassword.aspx` proves MemberSuite sends reset emails; the product requirement is to trigger that via API, not scrape the portal.

**Sandbox verification (required before release)**:

1. Sign in as API user: `POST /platform/v2/loginUser/{tenantId}` with API email/password.
2. Inspect Platform + Security Swagger for forgot/reset password operations; prefer an authenticated POST that accepts email (or Individual id resolved by read-only search).
3. Confirm a known sandbox inbox receives MemberSuite’s reset email; confirm unknown emails do not leak existence through our UX (we always show the same confirmation).
4. If no REST operation exists, escalate to MemberSuite CSM; do **not** fall back to HTML portal posting in v1 without an explicit spec amendment.

**Alternatives considered**:

| Approach | Why rejected / deferred |
|----------|-------------------------|
| Outside SSO `JWTSSO` with `IsForgotPassword=true` | Documented flag exists, but sample flow still requires tokens from `loginUser` (member password)—unsuitable when password is forgotten. Revisit only if CSM documents a passwordless forgot path. |
| Reverse SSO / portal redirect to ForgotPassword | Leaves the WordPress site; not “trigger via API” from our form. |
| Scrape / POST portal ForgotPassword.aspx | Fragile, not REST, higher ToS/security risk. |
| Build on-site “set new password” UI | Out of scope per spec; MemberSuite owns password change. |

## 2. API service-account credentials storage

**Decision**: Extend `msebaa_settings` with `association_id`, `tenant_id` (admin label **Association Key**), `api_user_email`, `api_user_password`. Store only in WordPress options (Settings API); never commit to git; never expose to the browser. Password field blank on edit; blank save retains previous value. Optionally encrypt-at-rest later; v1 relies on WP DB access controls + `manage_options`.

**Rationale**: Spec FR-006/FR-007 and user clarification; non-SSO calls require API user Bearer. SSO member login (feature 001) does not need these credentials; password reset and future read-only enrichment do.

**Alternatives considered**: Constants in `wp-config.php` only (harder for non-dev admins; FR wants admin UI). OAuth client credentials (not what MemberSuite issued).

## 3. Bearer token lifecycle

**Decision**: After API `loginUser` success, cache `idToken` in a transient `msebaa_api_id_token` with TTL **≤ 4 hours** (under MemberSuite’s ~5 hour validity). Reuse for reset calls; on 401, clear transient and re-login once. Send header exactly: `Authorization: Bearer ` + idToken (space required per MemberSuite). Do not log the token.

**Rationale**: Matches vendor guidance quoted in the feature input; avoids login-per-request while staying inside token lifetime.

**Alternatives considered**: Login on every reset (simpler, more load). Persist token in options (survives longer than needed; worse if DB leaked).

## 4. Read-only boundary

**Decision**: Allow only: API-user login, token cache, password-reset email request, and optional read-only Individual lookup if required to invoke reset by id. Forbid PATCH/POST that create/update/delete CRM/membership records from this feature’s code paths.

**Rationale**: Spec FR-005; constitution MemberSuite-as-SoR without write-back scope.

## 5. UX: shortcodes and confirmation

**Decision**:

- Standalone shortcode: `[msebaa_password_reset]`
- SSO shortcode `[msebaa_sso_login]` gains a “Forgot password?” control that **MUST** reveal an inline reset panel on the same embed (v1). Linking to a page containing only `[msebaa_password_reset]` is a documented fallback only if the inline panel cannot ship in the same release.
- On accepted/rate-limited outcomes: same-page non-revealing confirmation (no redirect); rate-limited uses the same copy as accepted (FR-003 / FR-010)
- On missing config / hard failure: distinct unavailable message (still no secrets)

**Rationale**: Clarification session (both entry points; in-place confirmation); analyze lock for inline-first v1.

## 6. Rate limiting

**Decision**: Transient counter keyed by hashed visitor identifier (prefer `msebaa_pwreset_` + hash of IP + User-Agent, or IP alone if UA unstable), **5 attempts per 3600 seconds**. Increment **only** when under limit and a MemberSuite call is attempted (including after transport/`WP_Error`). Do **not** increment on validation, missing config, or over-limit. When over limit: return success-style confirmation, **do not** call MemberSuite. Do not reveal limit in UI.

**Rationale**: Clarifications Q2/Q5; FR-010; SC-007; data-model increment rules.

**Alternatives considered**: Configurable admin limit (deferred). Per-email limits (enables enumeration side channels if mishandled).

## 7. Relationship to feature 001 (SSO)

**Decision**: This feature extends the same plugin package and `msebaa_settings` option. Implementation may land before or after 001 code exists; plan assumes shared settings/API client classes from `001` plan. If 001 is not yet implemented, this feature still introduces/extends those files as needed (no duplicate option keys).

**Rationale**: Spec assumptions; avoid parallel settings stores.

## 8. Testing

**Decision**: Manual sandbox acceptance per quickstart (required). Optional PHPUnit for rate-limiter and settings sanitize (blank password retain) with HTTP mocked—only if/when test harness from 001 exists; not required by this spec.

**Rationale**: Spec has no TDD mandate; constitution quality gates favor sandbox for MemberSuite paths.
