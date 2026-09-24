# Contract: Password Reset Shortcodes & Forms

**Feature**: `002-password-reset`  
**Audience**: Editors and visitors

## Standalone shortcode

| Item | Value |
|------|--------|
| Tag | `[msebaa_password_reset]` |
| Attributes (v1) | none required |
| Output | Email field + submit; nonce; in-place messages |

## Sign-in form integration

| Item | Value |
|------|--------|
| Host shortcode | `[msebaa_sso_login]` (from feature 001) |
| Control | Translatable “Forgot password?” link/button |
| Behavior (v1) | **MUST** reveal an inline reset panel on the same embed. Linking to a page that only contains `[msebaa_password_reset]` is a documented fallback only if the inline panel cannot ship in the same release. |

## Form submission

| Item | Value |
|------|--------|
| Method | `POST` |
| Nonce action | `msebaa_password_reset` (passed to `wp_nonce_field` / `check_admin_referer` / `wp_verify_nonce`) |
| Nonce field | `msebaa_pwreset_nonce` (form field name) |
| Fields | `msebaa_reset_email` (`sanitize_email`) |

## Outcomes (same page, no redirect)

| Outcome | Visitor copy (conceptual) | Calls MemberSuite? |
|---------|---------------------------|--------------------|
| Validation error | Clear “enter a valid email” | No |
| Accepted (known or unknown email) | Non-revealing “if an account exists, instructions were sent” | Yes (when under limit) |
| Rate limited | **Identical** to accepted | No |
| Unavailable (config/transport) | Distinct temporary-unavailable | No |

Never expose: whether account exists, rate limit hit, API errors, credentials, or tokens.
