# Contract: MemberSuite API — Password Reset Email

**Feature**: `002-password-reset`  
**Audience**: Plugin implementers  
**Base URL**: `https://rest.membersuite.com`  
**Transport**: `wp_remote_*` over HTTPS with configured timeout

## 1. Obtain API Bearer token

```http
POST /platform/v2/loginUser/{tenantId}
Content-Type: application/json
Accept: application/json

{"email":"<api_user_email>","password":"<api_user_password>"}
```

**Success**: JSON includes `idToken` (valid ~5 hours per MemberSuite).  
**Usage**: Cache internally (TTL ≤ 4 hours). For subsequent non-SSO calls:

```http
Authorization: Bearer <idToken>
```

(Literal `Bearer`, then a space, then the token — per MemberSuite guidance.)

**Failure**: Treat as unavailable for password reset; do not expose details to visitors.

## 2. Request password-reset email

**Locked 2026-09-28 (T024).** Reconfirmed against live Security Swagger (`https://rest.membersuite.com/security/swagger/docs/v1`). This is the only reset-email operation. Do not replace it with a portal scrape or `ForgotPassword.aspx` POST.

```http
GET /security/v1/portalUsers/{tenantId}/sendForgottenPortalPasswordEmail?email={email}
Authorization: Bearer <idToken>
Accept: application/json
```

| Item | Value |
|------|--------|
| Operation | `PortalUsers_SendForgottenPortalPasswordEmail` |
| Path param | `tenantId` — Association Key (same partition key as `loginUser`) |
| Query | `email` (required); `nextUrl` (optional, unused in v1) |
| Success | `200` boolean — “The reset email was sent.” |
| 400 | Swagger: no email address was supplied. Sandbox also returns 400 `{"message":"User not found."}` when the address is not a portal user. Treat both as ambiguous (non-revealing confirmation), not as unavailable |
| 401 | Access token missing, expired, or invalid |
| Writes | None — emails the association’s password-reset template; no Individual/Membership create/update/delete |

**Client contract** (stable for the plugin):

| Method | Input | Output |
|--------|-------|--------|
| `request_password_reset_email( string $email )` | Sanitized email | `true` on accepted transport; `WP_Error` on config/auth/transport failure |

**Enumeration**: Callers MUST NOT branch visitor messaging on “user not found” vs success when MemberSuite distinguishes them; map both to the same non-revealing confirmation when the HTTP layer completed without transport error. If the API returns hard failure only, still prefer non-revealing confirmation unless the failure is clearly configuration/auth (then unavailable). Ambiguous HTTP 4xx other than 401/403 are treated as accepted transport.

**Sandbox transport (2026-09-28):** An authenticated GET for `msebaa-phase5-nonmember@example.com` returned HTTP 400 `{"message":"User not found."}` in under a second. `request_password_reset_email()` maps that response to `true` (accepted transport). No portal scrape. A known portal user is the Swagger 200 boolean and would send MemberSuite’s reset email; that call was not repeated against a live member inbox during this lock.

## 3. Forbidden operations (this feature)

- PATCH/POST/DELETE that create, update, or delete Individual/Membership CRM records
- Logging of `api_user_password`, `idToken`, or visitor passwords (none collected)

## 4. Configuration inputs

- `tenant_id` (admin label Association Key), `association_id`, `api_user_email`, `api_user_password` from `msebaa_settings`
