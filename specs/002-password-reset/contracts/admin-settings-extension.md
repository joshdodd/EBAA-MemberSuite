# Contract: Admin Settings (Password Reset Extension)

**Feature**: `002-password-reset`  
**Audience**: Site administrators (`manage_options`)  
**Extends**: `specs/001-membersuite-sso/contracts/admin-settings.md`

## Additional / required fields on `msebaa_settings`

| Admin label | Storage key | Input | Sanitize / behavior |
|-------------|-------------|-------|---------------------|
| Association ID | `association_id` | text | `sanitize_text_field`; required for reset |
| Association Key | `tenant_id` | text | `sanitize_text_field`; required for reset (MemberSuite tenant / partition key; do not label UI “Tenant” alone) |
| API user email | `api_user_email` | email | `sanitize_email`; required for reset |
| API user password | `api_user_password` | password | Never re-display; empty submit retains stored value; non-empty replaces |

## UI rules

- Password input `autocomplete="new-password"` (or equivalent) and type `password`.
- Optional helper text: “Leave blank to keep the current password.”
- Capability: `manage_options` only.

## Uninstall

Delete entire `msebaa_settings` (including API password) and related transients (`msebaa_api_id_token`, `msebaa_pwreset_*`).
