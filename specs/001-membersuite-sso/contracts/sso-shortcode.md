# Contract: SSO Shortcode and Form

**Feature**: `001-membersuite-sso`  
**Audience**: Editors (content) and front-end visitors

## Shortcode

| Item | Value |
|------|--------|
| Tag | `[msebaa_sso_login]` |
| Attributes (v1) | none required; optional `redirect` (local URL string) reserved |
| Capability to embed | Anyone who can publish content containing shortcodes |
| Output when already logged in | Translatable “already signed in” notice (no credential fields) |
| Output when logged out | Email + password fields, submit control, nonce field |

## Form submission

| Item | Value |
|------|--------|
| Method | `POST` |
| Action | Same page or dedicated admin-post / custom endpoint handled by plugin |
| Nonce | `msebaa_sso_login` (name `msebaa_sso_nonce`) |
| Fields | `msebaa_email` (sanitize_email), `msebaa_password` (not stored; used only for outbound MemberSuite call), optional `msebaa_redirect` |

## Success behavior

1. Complete Outside SSO handshake (see `membersuite-rest-sso.md`).
2. Create or reuse linked WP user; sync profile; map role.
3. Set WP auth cookies.
4. `wp_safe_redirect` to validated return URL or current page.
5. Never expose tokens, passwords, or raw API bodies to the browser.

## Failure behavior

| Condition | Visitor message (conceptual) | Signed in? |
|-----------|------------------------------|------------|
| Bad credentials / SSO error | Sign-in failed; try again or contact support | No |
| Timeout / MemberSuite unreachable | Service temporarily unavailable | No |
| Identity link conflict / provision failure | Unable to complete sign-in | No |
| Nonce / capability failure | Request invalid | No |

Messages are translatable; no `firstErrorMessage` from MemberSuite is shown verbatim if it may leak internals — map to generic copy.

## Password login interaction

Linked users attempting `wp-login.php` password auth receive an error pointing them to the SSO form / configured login page (see theme helper `msebaa_get_login_url()`).
