---
description: "Task list for MemberSuite Password Reset Email implementation"
---

# Tasks: MemberSuite Password Reset Email

**Input**: Design documents from `/specs/002-password-reset/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Not requested in the feature specification — no automated test tasks. Validate via `quickstart.md` sandbox scenarios (Polish phase).

**Organization**: Tasks grouped by user story. Admin connection settings that block live reset are in Foundational so US1 can be sandbox-tested.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1 / US2 on story-phase tasks only
- Exact file paths in every description

## Path Conventions

WordPress plugin root per `plan.md`: `includes/`, `admin/`, `public/`, `uninstall.php` (extends `001-membersuite-sso` package).

**Shared 001 dependency**: This feature may land before or after `001-membersuite-sso` code exists. Foundational work MUST create shared settings, API client, and SSO shortcode classes if they are absent (per `specs/001-membersuite-sso/`), then extend them for password reset. Do not invent a second settings store or duplicate option keys.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Ensure additive class files exist for password-reset work

- [ ] T001 Create stub `includes/class-msebaa-password-reset.php` and `includes/class-msebaa-rate-limit.php` with `ABSPATH` guards and `Msebaa_*` class skeletons
- [ ] T002 [P] Create stub `public/class-msebaa-password-reset-shortcode.php` with `ABSPATH` guard and class skeleton for `[msebaa_password_reset]`
- [ ] T003 [P] Create `public/css/msebaa-password-reset.css` placeholder for reset-form styles
- [ ] T004 Wire new stubs into plugin boot in `includes/class-msebaa-plugin.php` (or `membersuite-ebaa.php` if boot lives there) without executing reset side effects on bare include

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared 001 classes (create if absent), settings, API token, rate limiter — MUST complete before user stories

**⚠️ CRITICAL**: No user story work until this phase is complete

- [ ] T005 **Create if absent** (else skip to extend tasks): shared plugin infrastructure from `specs/001-membersuite-sso/` — at minimum `includes/class-msebaa-settings.php`, `includes/class-msebaa-api-client.php`, `public/class-msebaa-shortcode.php` (`[msebaa_sso_login]`), and boot wiring in `includes/class-msebaa-plugin.php` / `membersuite-ebaa.php` / `admin/class-msebaa-admin-settings.php` as needed so later “extend” tasks have real targets. Follow 001 contracts for Outside SSO login skeleton; do not invent alternate option keys.
- [ ] T006 Create or extend settings defaults and reader in `includes/class-msebaa-settings.php`: require `tenant_id` (`sanitize_text_field`), `association_id` (`sanitize_text_field`), `api_user_email` (`sanitize_email`), `api_user_password` (stored secret); keep `http_timeout` absint clamp 5–30 default 15; expose `msebaa_get_settings(): array` and `msebaa_has_api_credentials(): bool`
- [ ] T007 Create or extend Settings API admin UI in `admin/class-msebaa-admin-settings.php` (`manage_options`): fields for Association ID, Association Key (`tenant_id`), API user email, API user password (type password, never re-display); admin label MUST be “Association Key” (not “Tenant” alone); sanitize callback retains prior password when submitted password is empty; helper text “Leave blank to keep the current password”
- [ ] T008 [P] Implement rate limiter in `includes/class-msebaa-rate-limit.php`: transient key `msebaa_pwreset_{visitor_hash}`, TTL 3600s, limit **5** per visitor; methods `is_limited()`, `record_attempt()`; visitor hash from opaque hash of IP (+ UA if available); `record_attempt()` used only when under limit and an MS call was attempted (per `data-model.md`)
- [ ] T009 Create or extend API-user login + Bearer cache in `includes/class-msebaa-api-client.php`: `POST /platform/v2/loginUser/{tenantId}` with API email/password; cache `idToken` in transient `msebaa_api_id_token` TTL **14400** (4h); Authorization header `Bearer ` + token (space required); clear cache on 401 and when API email/password/tenant settings change; never log token or password
- [ ] T010 **Abort/escalation gate (REST only)**: Before coding a reset call body, confirm in MemberSuite sandbox Swagger (Platform + Security) an authenticated REST operation that triggers MemberSuite’s standard password-reset email without CRM profile/membership writes. If none exists: **ABORT** further MemberSuite-call work for this feature; escalate to MemberSuite CSM; do **not** implement portal HTML scrape or `ForgotPassword.aspx` POST; do **not** invent a non-REST workaround. Resume only after CSM documents a REST path **or** `/speckit-specify` amends the spec. If a path exists, encapsulate it as `request_password_reset_email( string $email )` in `includes/class-msebaa-api-client.php` per `specs/002-password-reset/contracts/membersuite-password-reset-api.md`; return `true` or `WP_Error`; no Individual/Membership create/update/delete
- [ ] T011 Update `uninstall.php` to delete `msebaa_settings` (including API password), transient `msebaa_api_id_token`, and all `msebaa_pwreset_*` transients; MUST NOT delete WordPress users

**Checkpoint**: Foundation ready — user stories can begin (only if T010 found a REST path or was formally deferred via spec amendment)

---

## Phase 3: User Story 1 — Request a Password Reset Email (Priority: P1) 🎯 MVP

**Goal**: Visitors request MemberSuite’s password-reset email via standalone shortcode and from the SSO sign-in form; in-place non-revealing confirmation; rate limit without MS call when over limit

**Independent Test**: Use `[msebaa_password_reset]` and SSO forgot path; known email gets MS reset mail + same confirmation as unknown email; sixth attempt in an hour does not trigger another MS email (`quickstart.md` Scenario A)

**Blocked if**: T010 aborted (no REST operation) until CSM or spec amendment unlocks it.

### Implementation for User Story 1

- [ ] T012 [US1] Implement orchestration in `includes/class-msebaa-password-reset.php`: verify nonce action `msebaa_password_reset` (field `msebaa_pwreset_nonce`); `sanitize_email` on `msebaa_reset_email`; reject empty/invalid with validation error (no MS call, no `record_attempt`); if `! msebaa_has_api_credentials()` → unavailable (no MS call, no `record_attempt`); if rate limited → same non-revealing confirmation as FR-003 and **do not** call MemberSuite and **do not** `record_attempt`; else call `request_password_reset_email`, then `record_attempt()` (including when MS returns transport/`WP_Error`), map auth/transport failures to unavailable, map accepted/ambiguous MS outcomes to identical non-revealing confirmation; never reveal account existence or rate-limit state
- [ ] T013 [P] [US1] Implement shortcode `[msebaa_password_reset]` in `public/class-msebaa-password-reset-shortcode.php` per `specs/002-password-reset/contracts/password-reset-shortcode.md`: email field, submit, nonce action `msebaa_password_reset` / field `msebaa_pwreset_nonce`; in-place messages only (no redirect); enqueue `public/css/msebaa-password-reset.css` only when shortcode present
- [ ] T014 [P] [US1] Style reset form and confirmation/unavailable/validation states in `public/css/msebaa-password-reset.css` (accessible, no hardcoded `<link>` tags)
- [ ] T015 [US1] Register shortcode and POST handler from `includes/class-msebaa-plugin.php` / `public/class-msebaa-password-reset-shortcode.php`
- [ ] T016 [US1] Create or extend `[msebaa_sso_login]` in `public/class-msebaa-shortcode.php` (create SSO form per 001 if absent): add “Forgot password?” control that **MUST** reveal an inline reset panel on the same embed (v1 required); link-to-standalone-page is documented fallback only if inline cannot ship in the same release; reuse orchestration from `class-msebaa-password-reset.php`
- [ ] T017 [US1] Ensure visitor-facing strings for confirmation, unavailable, and validation use text domain `membersuite-ebaa` and escape on output in `public/class-msebaa-password-reset-shortcode.php` and related templates
- [ ] T018 [P] [US1] Allow password-reset submit while the visitor is already signed in (`includes/class-msebaa-password-reset.php` / shortcodes): do not require sign-out; do not change the WordPress session password (FR-012)
- [ ] T019 [P] [US1] Ensure reset form paths never invoke WordPress `retrieve_password` / lost-password email for this feature in `includes/class-msebaa-password-reset.php` and related handlers (FR-013)

**Checkpoint**: User Story 1 independently testable (MVP)

---

## Phase 4: User Story 2 — Configure Association Connection (Priority: P2)

**Goal**: Administrators can configure and safely update Association ID, Key, and API credentials; incomplete config yields unavailable public messaging; secrets never leak to visitors

**Independent Test**: Save credentials; blank password save retains secret; clear settings → public form unavailable; front end never shows credentials (`quickstart.md` Scenario B)

### Acceptance checks for User Story 2

*(Implementation of settings UI and blank-password retain is in T006–T007; Phase 4 verifies completeness.)*

- [ ] T020 [US2] Confirm T007 admin field labels/descriptions (Association ID, Association Key, API user email/password) meet SC-006 so an admin can configure without developer help in `admin/class-msebaa-admin-settings.php`
- [ ] T021 [US2] Confirm T007/T006 save path invalidates `msebaa_api_id_token` when `tenant_id`, `api_user_email`, or `api_user_password` changes in `admin/class-msebaa-admin-settings.php` / `includes/class-msebaa-settings.php`; wire only if missing
- [ ] T022 [US2] Confirm T012 public reset path uses `msebaa_has_api_credentials()` so missing/incomplete settings show unavailable (not false success) via `includes/class-msebaa-password-reset.php`
- [ ] T023 [P] [US2] Audit front-end and error paths so API password, `idToken`, and association secrets never appear in HTML, redirects, or visitor messages across `public/` and `includes/class-msebaa-password-reset.php`

**Checkpoint**: US1 and US2 both work for sandbox acceptance

---

## Phase 5: Polish & Cross-Cutting Concerns

**Purpose**: Sandbox confirmation of API path, i18n, security

- [ ] T024 Re-confirm concrete MemberSuite reset-email REST endpoint in sandbox; lock path/parameters in `includes/class-msebaa-api-client.php` and update `specs/002-password-reset/contracts/membersuite-password-reset-api.md` with the final operation name. **Same abort gate as T010**: if only a portal scrape remains available, stop and escalate to CSM / require spec amendment — do not ship a non-REST workaround
- [ ] T025 [P] Ensure all new user-facing strings are translatable with text domain `membersuite-ebaa` in `admin/` and `public/` password-reset files
- [ ] T026 Security pass: no CRM writes from reset feature; nonces on reset POST; `manage_options` on settings; no secrets in logs
- [ ] T027 Run `specs/002-password-reset/quickstart.md` Scenarios A–B against MemberSuite sandbox; verify A1 in-place confirmation within 30 seconds (SC-001); fix gaps

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Start immediately
- **Foundational (Phase 2)**: Depends on Setup — **BLOCKS** US1 and US2; T005 before T006–T009; T010 gates MS-call implementation
- **US1 (Phase 3)**: Depends on Foundational **and** T010 REST path confirmed — MVP public reset
- **US2 (Phase 4)**: Depends on Foundational; acceptance checks for admin settings started in T006–T007
- **Polish (Phase 5)**: After desired stories

### User Story Dependencies

- **US1 (P1)**: Needs Foundational API + rate limit + settings credentials + confirmed REST reset operation (T010)
- **US2 (P2)**: Can proceed after Foundational settings work; complements US1 with settings UX and secret hygiene (admin UI can proceed even if T010 aborted, but live reset cannot)

### Parallel Opportunities

- Phase 1: T002 || T003 after T001
- Phase 2: T008 || T009 after T006 interfaces exist; T007 after T006; T010 after T009 auth works
- Phase 3: T013 || T014; T016 after T012/T013; T018 || T019 after T012
- Phase 4: T023 parallel with T020–T022
- Phase 5: T025 || T026

---

## Parallel Example: User Story 1

```bash
# After orchestration (T012) is underway:
Task: "T013 Implement shortcode [msebaa_password_reset] in public/class-msebaa-password-reset-shortcode.php"
Task: "T014 Style reset form in public/css/msebaa-password-reset.css"

# Then:
Task: "T016 Add Forgot password? inline panel to public/class-msebaa-shortcode.php"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup  
2. Phase 2 Foundational (create-if-absent 001 shared classes + settings + API + rate limit + **T010 REST gate**)  
3. Phase 3 US1 shortcode + orchestration (**only if T010 passed**)  
4. **STOP** — quickstart Scenario A (incl. SC-001 ≤30s)  
5. Then US2 acceptance checks + Scenario B  

### Incremental Delivery

1. Setup + Foundational → credentials and API ready (or ABORT at T010)  
2. US1 → public reset MVP  
3. US2 → admin acceptance checks  
4. Polish → sandbox lock of exact MS endpoint  

---

## Notes

- No automated test tasks (not requested in spec)
- Exact MemberSuite reset REST path is sandbox-confirmed in T010/T024; portal scrape is explicitly forbidden
- Shared settings/API/SSO shortcode: create per 001 if absent (T005), then extend (T006–T009, T016)
- Rate-limit increment rules: `data-model.md` + T012
- Commit after each task or logical group
- `[P]` = different files, no incomplete-task dependencies
