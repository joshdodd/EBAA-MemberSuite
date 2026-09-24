# Data Model: MemberSuite Password Reset Email

**Feature**: `002-password-reset`  
**Date**: 2026-09-21

## Entities

### 1. Association connection settings (`msebaa_settings` option — extended)

Extends the plugin settings introduced for SSO. This feature **requires** the following fields:

| Field | Type | Required | Validation | Notes |
|-------|------|----------|------------|-------|
| `tenant_id` | string | yes | `sanitize_text_field`; non-empty for reset to run | Admin label **Association Key** (MemberSuite tenant / partition key); do not label UI “Tenant” alone |
| `association_id` | string | yes | `sanitize_text_field`; non-empty for reset to run | Association ID (GUID) |
| `api_user_email` | string | yes | `sanitize_email` | API service-account email |
| `api_user_password` | string | yes (stored) | stored as provided after capability/nonce checks; **never** echoed back to the form | Blank POST on save → keep existing |
| `login_page_id` | int | no (for this feature) | `absint` | Shared with SSO; optional here |
| `http_timeout` | int | no | `absint`; clamp 5–30; default 15 | Shared |

**Lifecycle**: Created/updated via Settings API (`manage_options`). Removed on uninstall (FR-011). Never logged.

**Password edit rule**: Form always renders empty password input. Sanitize callback: if submitted password is empty string and a stored password exists, retain stored value; else replace.

---

### 2. API Bearer token cache (transient)

| Field | Storage | Type | Notes |
|-------|---------|------|-------|
| `idToken` | transient `msebaa_api_id_token` | string | From API-user `loginUser` |
| TTL | — | int | Default 14400s (4 hours); under MemberSuite ~5h validity |

**Invalidation**: On 401 from MemberSuite; on API password/email/tenant change in settings; on uninstall.

---

### 3. Password reset request (ephemeral)

Not persisted as a durable entity. Runtime attributes:

| Attribute | Type | Notes |
|-----------|------|-------|
| `email` | string | `sanitize_email`; reject empty/invalid before any remote call |
| `visitor_key` | string | Opaque hash for rate limiting |
| `outcome` | enum | `accepted` \| `rate_limited` \| `unavailable` \| `validation_error` |

No MemberSuite password is ever stored. No CRM write payloads.

---

### 4. Rate-limit counter (transient)

| Field | Storage | Type | Notes |
|-------|---------|------|-------|
| Count | transient `msebaa_pwreset_{visitor_hash}` | int | See increment rules below |
| TTL | — | int | 3600 seconds |
| Limit | — | int | **5** requests per window per visitor |

**Increment rules**:

- Increment **only** when the visitor is under the limit and a MemberSuite reset-email call is attempted (including when that call returns transport/`WP_Error` after the attempt).
- Do **not** increment on validation error, missing/incomplete configuration, or over-limit paths.
- Over-limit: outcome `rate_limited` (visitor-facing = same confirmation as `accepted`); no MemberSuite call; no increment.

**Behavior**: If count ≥ 5 → outcome `rate_limited`; no MemberSuite call.

---

## Relationships

```text
[Admin] --configures--> [Association connection settings]
[Visitor] --submits email--> [Password reset request]
[Password reset request] --if under limit & configured--> [API Bearer token] --authorizes--> [MemberSuite reset-email operation]
[Password reset request] --increments--> [Rate-limit counter]
```

## State transitions (visitor UX)

```text
[Form]
  --invalid email--> [Validation error] (stay; show field error; no MS call)
  --missing settings--> [Unavailable message] (stay; no MS call)
  --over rate limit--> [Non-revealing confirmation] (stay; no MS call)
  --MS success or ambiguous MS response--> [Non-revealing confirmation] (stay)
  --MS transport failure--> [Unavailable message] (stay)
```

## Out of scope storage

- New MemberSuite passwords
- Permanent log of reset emails sent
- Custom tables
