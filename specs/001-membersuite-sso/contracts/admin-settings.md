# Contract: Admin Settings

**Feature**: `001-membersuite-sso`  
**Audience**: Site administrators (`manage_options`)

## Settings surface

WordPress Settings API registration under a plugin settings page (e.g. Settings → MemberSuite EBAA).

| Option key | `msebaa_settings` (array) |
|------------|---------------------------|
| Capability | `manage_options` |
| Sanitize | Dedicated sanitize callback; never store passwords from SSO form here |

## Fields

| Field | Input | Sanitize |
|-------|-------|----------|
| Tenant / Partition ID | text | `sanitize_text_field` |
| Association ID | text (optional) | `sanitize_text_field` |
| Login page | dropdown of pages | `absint` |
| HTTP timeout (seconds) | number | `absint`, clamped |

## Security

- Settings form uses Settings API nonces.
- No MemberSuite user passwords stored in options for v1.
- Do not display secrets in HTML source beyond what admin entered for tenant IDs.

## Read API (internal)

`msebaa_get_settings(): array` returns defaults merged with saved values for use by SSO client and redirect helpers.
