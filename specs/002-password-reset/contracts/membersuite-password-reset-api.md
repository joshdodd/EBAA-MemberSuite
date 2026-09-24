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

```text
POST (or documented Security/Platform operation)
Authorization: Bearer <idToken>
Body/query: visitor email (and association/tenant context as required by the operation)
```

**Concrete path**: Confirm in MemberSuite sandbox Swagger (Platform + Security) during implementation; encapsulate in `request_password_reset_email( $email )`. Prefer an operation that triggers MemberSuite’s standard reset email without CRM profile writes.

**Client contract** (stable for the plugin):

| Method | Input | Output |
|--------|-------|--------|
| `request_password_reset_email( string $email )` | Sanitized email | `true` on accepted transport; `WP_Error` on config/auth/transport failure |

**Enumeration**: Callers MUST NOT branch visitor messaging on “user not found” vs success when MemberSuite distinguishes them; map both to the same non-revealing confirmation when the HTTP layer completed without transport error. If the API returns hard failure only, still prefer non-revealing confirmation unless the failure is clearly configuration/auth (then unavailable).

## 3. Forbidden operations (this feature)

- PATCH/POST/DELETE that create, update, or delete Individual/Membership CRM records
- Logging of `api_user_password`, `idToken`, or visitor passwords (none collected)

## 4. Configuration inputs

- `tenant_id` (admin label Association Key), `association_id`, `api_user_email`, `api_user_password` from `msebaa_settings`
