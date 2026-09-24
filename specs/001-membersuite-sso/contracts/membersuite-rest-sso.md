# Contract: MemberSuite REST Outside SSO

**Feature**: `001-membersuite-sso`  
**Audience**: Plugin implementers  
**Base URL**: `https://rest.membersuite.com`  
**Transport**: `wp_remote_get` / `wp_remote_post` over HTTPS with configured timeout

All calls are server-side only. Tokens and passwords MUST NOT be logged or sent to the browser.

## 1. Login user

```http
POST /platform/v2/loginUser/{tenantId}
Content-Type: application/json
Accept: application/json

{"email":"<string>","password":"<string>"}
```

**Success**: JSON includes `idToken`, `accessToken`, `refreshToken` (and success indicators per API envelope).  
**Failure**: Treat as auth failure; map to generic visitor error.

## 2. JWT SSO handshake

```http
POST /platform/v2/JWTSSO/{tenantId}
Content-Type: application/x-www-form-urlencoded

idToken=…&refreshToken=…&accessToken=…&nextUrl=<plugin-callback-url>&IsSignUp=false&IsForgotPassword=false
```

**Success**: Response `Location` header contains URL with `tokenGUID` query param (valid ~5 minutes).  
**Plugin**: Persist return-target state associated with the callback; extract `tokenGUID` (server-side or after browser redirect per research decision).

## 3. Bearer token from SSO

```http
GET /platform/v2/bearerTokenSSO?tokenGUID=<guid>&partitionKey=<tenantId>
Accept: application/json
```

**Success**: Body includes SSO `idToken` (valid ~1 hour).  
**Failure**: Abort sign-in; generic error.

## 4. Who am I

```http
GET /platform/v2/whoami
Accept: application/json
Authorization: Bearer <idToken>
```

**Success body (fields used by plugin)**:

| Field | Use |
|-------|-----|
| `ownerId` | Stable Individual link key |
| `userId` | Stored for reference |
| `email` | Profile sync |
| `firstName` / `lastName` | Profile sync |
| `membershipId` | Stored when present |
| `receivesMemberBenefits` | Entitlement + role mapping (`true` vs null/false) |

## 5. Optional individual enrich

```http
GET /crm/v1/individuals/{tenantId}?msql=<urlencoded MSQL where ID='{ownerId}'>&page=1&pageSize=1
Authorization: Bearer <idToken>
```

Used only if `whoami` lacks a needed core field; v1 may skip if `whoami` is sufficient.

## Error handling contract

| WP HTTP layer | Plugin behavior |
|---------------|-----------------|
| `is_wp_error` / non-2xx / timeout | Graceful failure; no WP session |
| API `success: false` | Graceful failure; do not trust partial tokens |
| Missing `ownerId` on success path | Fail closed; do not create unlinked session |

## Configuration inputs

- `tenantId` / partition key from `msebaa_settings`
- Callback / `nextUrl` must be an HTTPS (or site) URL owned by this WordPress install
