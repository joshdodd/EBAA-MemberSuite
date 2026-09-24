---
description: "Task list for MemberSuite SSO and Member Access implementation"
---

# Tasks: MemberSuite SSO and Member Access

**Input**: Design documents from `/specs/001-membersuite-sso/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: Not requested in the feature specification — no automated test tasks. Validate via `quickstart.md` sandbox scenarios (Polish phase).

**Organization**: Tasks grouped by user story for independent implementation and testing.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: User story label (US1, US2, US3) — required on story-phase tasks only
- Include exact file paths in descriptions

## Path Conventions

WordPress plugin at repository root per `plan.md`: `membersuite-ebaa.php`, `includes/`, `admin/`, `public/`, `languages/`, `uninstall.php`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Plugin skeleton and directory layout

- [ ] T001 Create plugin directory layout `includes/`, `admin/`, `public/css/`, `public/js/`, `languages/` per `specs/001-membersuite-sso/plan.md`
- [ ] T002 Create main plugin bootstrap `membersuite-ebaa.php` with valid plugin header (`Plugin Name`, `Description`, `Version`, `Author`, `License`, `Text Domain: membersuite-ebaa`, `Requires at least`, `Requires PHP: 8.1`), `ABSPATH` guard, and constants `MSEBAA_PLUGIN_FILE`, `MSEBAA_PLUGIN_DIR`, `MSEBAA_VERSION`
- [ ] T003 [P] Create empty stub files for planned classes: `includes/class-msebaa-plugin.php`, `includes/class-msebaa-settings.php`, `includes/class-msebaa-api-client.php`, `includes/class-msebaa-sso.php`, `includes/class-msebaa-user-repository.php`, `includes/class-msebaa-cache.php`, `includes/class-msebaa-roles.php`, `includes/class-msebaa-helpers.php`, `includes/class-msebaa-content-gate.php`, `includes/class-msebaa-media-gate.php`, `admin/class-msebaa-admin-settings.php`, `admin/class-msebaa-members-only-meta.php`, `public/class-msebaa-shortcode.php` (each with `ABSPATH` guard and class skeleton)
- [ ] T004 Wire bootstrap in `membersuite-ebaa.php` to require stubs and call `Msebaa_Plugin` boot on `plugins_loaded` without side effects on bare include

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared settings, roles, cache, activation/uninstall — MUST complete before any user story

**⚠️ CRITICAL**: No user story work until this phase is complete

- [ ] T005 Implement settings defaults and reader in `includes/class-msebaa-settings.php`: option key `msebaa_settings` with fields `tenant_id` (sanitize_text_field), `association_id` (sanitize_text_field, optional), `login_page_id` (absint), `http_timeout` (absint, clamp 5–30, default 15); expose `msebaa_get_settings(): array`
- [ ] T006 [P] Implement Settings API admin page in `admin/class-msebaa-admin-settings.php` (`manage_options` only): register_setting sanitize callback, sections/fields for tenant_id, association_id, login_page_id (page dropdown), http_timeout; register menu and load only in admin
- [ ] T007 [P] Implement role registration in `includes/class-msebaa-roles.php`: role slug `msebaa_member`, display name translatable “Member”, capabilities baseline same as `subscriber`; provide `msebaa_map_role_from_benefits( bool $receives_benefits, \WP_User $user ): void` that sets `msebaa_member` when true else `subscriber`, and MUST NOT overwrite privileged roles (e.g. `administrator` / `manage_options`)
- [ ] T008 [P] Implement membership cache in `includes/class-msebaa-cache.php`: transient key `msebaa_member_{user_id}`, TTL default 1800s, get/set/invalidate methods; store snapshot array with `receives_member_benefits`, profile subset, `synced_at`
- [ ] T009 Implement user meta repository in `includes/class-msebaa-user-repository.php` for identity link fields: `msebaa_owner_id` (GUID, unique among linked users), `msebaa_user_id`, `msebaa_membership_id` (may be empty), `msebaa_receives_member_benefits` (`'1'`/`'0'`; null/false → `'0'`), `msebaa_first_name`, `msebaa_last_name`, `msebaa_email` (sanitize_email), `msebaa_last_synced` (GMT), `msebaa_linked` (`'1'`); methods find-by-owner-id, save-link, get-snapshot (never link by email alone)
- [ ] T010 Implement MemberSuite REST client skeleton in `includes/class-msebaa-api-client.php`: base URL `https://rest.membersuite.com`, all calls via `wp_remote_get`/`wp_remote_post`, HTTPS, timeout from settings; methods stubs for `login_user`, `jwt_sso`, `bearer_token_sso`, `whoami`; never log passwords or tokens; return `WP_Error` on failure
- [ ] T011 Implement activation/deactivation in `includes/class-msebaa-plugin.php` (or bootstrap): `register_activation_hook` seeds `msebaa_settings` defaults and registers `msebaa_member` role; `register_deactivation_hook` clears scheduled events only (MUST NOT delete user data); wire hooks from `membersuite-ebaa.php`
- [ ] T012 Implement `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN`: delete `msebaa_settings`, all `_msebaa_members_only` post meta, all `msebaa_*` user meta and `msebaa_*` transients, remove role `msebaa_member` (remap those users to `subscriber`); MUST NOT delete WordPress users or posts/pages/media files

**Checkpoint**: Foundation ready — user story implementation can begin

---

## Phase 3: User Story 1 — Sign In via Embedded SSO Form (Priority: P1) 🎯 MVP

**Goal**: Visitors authenticate via `[msebaa_sso_login]` Outside SSO; WordPress users are created/linked by `ownerId`, roles mapped from benefits, password login blocked for linked users

**Independent Test**: Place SSO form on a blank page; sign in with valid/invalid MemberSuite credentials; confirm WP session only on success; membership status and role correct; linked users cannot use `wp-login.php` password (see `specs/001-membersuite-sso/quickstart.md` Scenario A)

### Implementation for User Story 1

- [ ] T013 [P] [US1] Complete API client Outside SSO methods in `includes/class-msebaa-api-client.php` per `specs/001-membersuite-sso/contracts/membersuite-rest-sso.md`: `POST /platform/v2/loginUser/{tenantId}`, `POST /platform/v2/JWTSSO/{tenantId}` (form-urlencoded; extract `tokenGUID` from Location), `GET /platform/v2/bearerTokenSSO`, `GET /platform/v2/whoami` with Bearer idToken
- [ ] T014 [US1] Implement SSO orchestration in `includes/class-msebaa-sso.php`: validate nonce `msebaa_sso_login`; sanitize email; call API client; on success provision/link via user repository by `ownerId` only; sync profile fields; call role mapper; set auth cookie; invalidate/set cache; `wp_safe_redirect` to validated return URL; on failure leave signed out with generic translatable errors (no API dumps)
- [ ] T015 [US1] Implement login redirect context storage in `includes/class-msebaa-sso.php` (or small helper in same file): accept `msebaa_redirect` query/POST, validate with `wp_validate_redirect` (local only), persist via short-lived transient (~15 min) through SSO callback; clear after use
- [ ] T016 [P] [US1] Implement shortcode `[msebaa_sso_login]` in `public/class-msebaa-shortcode.php` per `specs/001-membersuite-sso/contracts/sso-shortcode.md`: logged-out form (email, password, nonce `msebaa_sso_nonce`, optional redirect); logged-in “already signed in” notice; enqueue `public/css/msebaa-sso-form.css` only when shortcode present
- [ ] T017 [P] [US1] Add minimal styles in `public/css/msebaa-sso-form.css` for accessible form layout (no hardcoded script/link tags elsewhere)
- [ ] T018 [US1] Register shortcode and SSO form POST handler (admin-post or `init` action) from `includes/class-msebaa-plugin.php` / `public/class-msebaa-shortcode.php`
- [ ] T019 [US1] Block WordPress password login for linked users in `includes/class-msebaa-sso.php`: filter `authenticate` to return `WP_Error` directing to SSO/login page when `msebaa_linked` is set; filter `allow_password_reset` to false for linked users; unlinked users (including admins) unchanged
- [ ] T020 [US1] Ensure SSO success maps `receivesMemberBenefits`: true → `msebaa_member`, null/false → `subscriber`, never downgrade privileged roles; missing `ownerId` fails closed (no session)

**Checkpoint**: User Story 1 fully functional and independently testable (MVP)

---

## Phase 4: User Story 2 — Theme Helpers for Member Data and Status (Priority: P2)

**Goal**: Documented theme helpers return safe, typed values from cache/meta without calling MemberSuite on the render path

**Independent Test**: With signed-in member and signed-out visitor, call each helper; confirm correct values vs documented defaults; with API unreachable after login, helpers still return last-known values without fatals (`quickstart.md` Scenario B)

### Implementation for User Story 2

- [ ] T021 [US2] Implement theme helpers in `includes/class-msebaa-helpers.php` (or loaded functions file) per `specs/001-membersuite-sso/contracts/theme-helpers.md`: `msebaa_is_user_logged_in()`, `msebaa_is_member()`, `msebaa_receives_member_benefits()`, `msebaa_get_current_member(): ?array`, `msebaa_get_member_field( string $key ): string`, `msebaa_get_login_url( string $redirect = '' ): string`, `msebaa_get_logout_url( string $redirect = '' ): string` — all safe after `init`, no echo/die, defaults as contracted
- [ ] T022 [US2] Wire helpers to read only from `Msebaa_Cache` / user repository snapshot in `includes/class-msebaa-helpers.php` — MUST NOT trigger remote MemberSuite calls per template tag; unknown/missing benefits → `false`
- [ ] T023 [US2] Ensure `msebaa_get_login_url()` uses configured `login_page_id` from settings with safe `msebaa_redirect` append; fallback `home_url( '/' )` when unset
- [ ] T024 [P] [US2] Add PHPDoc blocks on each helper in `includes/class-msebaa-helpers.php` documenting return types and safe defaults for theme authors
- [ ] T025 [US2] Load helpers from `includes/class-msebaa-plugin.php` on every front/admin request after `init` so templates can call them reliably

**Checkpoint**: User Stories 1 and 2 work independently

---

## Phase 5: User Story 3 — Mark Content Members-Only and Gate Access (Priority: P3)

**Goal**: Editors mark post/page/media Members Only; members with benefits (or admins) access content; others see denial message or media login redirect with return-after-login

**Independent Test**: Flag one post, page, and media file; verify access as signed-out, signed-in non-member, and signed-in member; confirm post/page message + login link and media redirect (`quickstart.md` Scenarios C–D)

### Implementation for User Story 3

- [ ] T026 [P] [US3] Implement Members Only checkbox meta box in `admin/class-msebaa-members-only-meta.php` for `post`, `page`, and `attachment`: save meta `_msebaa_members_only` as `'1'` when checked, delete meta when unchecked; require `current_user_can( 'edit_post', $post_id )` (or attachment equivalent) + nonce; users who cannot edit MUST NOT change the flag
- [ ] T027 [US3] Implement shared entitlement predicate in `includes/class-msebaa-content-gate.php` (reusable by media gate): allowed if not members-only OR `manage_options` OR (logged in AND cached/synced `receives_member_benefits === true`); missing/unknown benefits → deny (fail closed)
- [ ] T028 [US3] Implement post/page body gating in `includes/class-msebaa-content-gate.php`: on singular `the_content`, if flagged and not allowed, replace body with translatable “for logged-in members only” message including login link from `msebaa_get_login_url( get_permalink() )`; do not strip archive teasers
- [ ] T029 [US3] Implement media URL rewriting and download endpoint in `includes/class-msebaa-media-gate.php`: filter `wp_get_attachment_url` (and related) so members-only attachments point to gated route (e.g. `?msebaa_download={attachment_id}`); on request, if allowed stream file with correct headers; if denied `wp_safe_redirect` to login page with non-member file message + return URL; also guard attachment singular via `template_redirect`
- [ ] T030 [US3] Integrate return-after-login for media and content denials with US1 redirect context in `includes/class-msebaa-sso.php` / gates: after successful member SSO, redirect to original post/page/download URL when now entitled; otherwise remain denied
- [ ] T031 [US3] Register meta box, content filters, and media gate hooks from `includes/class-msebaa-plugin.php` (admin class only in admin; front gates on public requests)
- [ ] T032 [US3] Confirm uninstall path still deletes `_msebaa_members_only` without deleting attachments (verify `uninstall.php` covers US3 meta)

**Checkpoint**: All three user stories independently functional

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: i18n, security pass, sandbox validation across stories

- [ ] T033 [P] Ensure all visitor-facing strings use `__()`, `_e()`, `esc_html__()`, etc. with text domain `membersuite-ebaa` across `public/`, `admin/`, and `includes/`
- [ ] T034 [P] Generate or stub `languages/membersuite-ebaa.pot` for translator bootstrap
- [ ] T035 Security pass: confirm no passwords/tokens in logs or HTML; all outputs escaped; SSO and meta saves use nonces; settings require `manage_options`; HTTP only via `wp_remote_*`
- [ ] T036 Run end-to-end validation against MemberSuite sandbox using `specs/001-membersuite-sso/quickstart.md` Scenarios A–E; fix any gaps found
- [ ] T037 [P] Add brief theme-helper usage notes (PHPDoc or `readme.txt` Plugin URI / description section) pointing theme authors at the seven helpers without exposing internal classes

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately
- **Foundational (Phase 2)**: Depends on Setup — **BLOCKS** all user stories
- **User Story 1 (Phase 3)**: Depends on Foundational — MVP
- **User Story 2 (Phase 4)**: Depends on Foundational; practically needs US1 sync/cache populated for meaningful member data, but helpers must still return safe defaults without US1 UI
- **User Story 3 (Phase 5)**: Depends on Foundational + helpers/login URL (US2) and SSO return context (US1) for full acceptance; entitlement predicate can be built once cache/user meta exist
- **Polish (Phase 6)**: After desired stories complete

### User Story Dependencies

- **US1 (P1)**: After Phase 2 only — no dependency on US2/US3
- **US2 (P2)**: After Phase 2; uses repository/cache filled by US1 SSO in production, independently testable with seeded meta
- **US3 (P3)**: After Phase 2; full return-after-login UX needs US1; login link in denial message needs US2 `msebaa_get_login_url`

### Within Each User Story

- API/repository before orchestration
- Orchestration before shortcode/UI wiring
- Entitlement predicate before content/media gates
- Story complete before next priority (recommended solo workflow)

### Parallel Opportunities

- Phase 1: T003 parallelizable once T001 done
- Phase 2: T006, T007, T008 can proceed in parallel after T005 interfaces are clear; T010 parallel with T009
- Phase 3: T013 || T016/T017; then T014 depends on T013+T009; T019 parallel with shortcode once link meta exists
- Phase 4: T024 parallel with implementation polish
- Phase 5: T026 parallel with T027; T028 and T029 after T027
- Phase 6: T033, T034, T037 in parallel

---

## Parallel Example: User Story 1

```bash
# After Foundational completes, in parallel:
Task: "T013 Complete API client Outside SSO methods in includes/class-msebaa-api-client.php"
Task: "T016 Implement shortcode [msebaa_sso_login] in public/class-msebaa-shortcode.php"
Task: "T017 Add minimal styles in public/css/msebaa-sso-form.css"

# Then sequential orchestration:
Task: "T014 Implement SSO orchestration in includes/class-msebaa-sso.php"
Task: "T019 Block WordPress password login for linked users in includes/class-msebaa-sso.php"
```

## Parallel Example: User Story 3

```bash
# In parallel after entitlement design:
Task: "T026 Implement Members Only checkbox in admin/class-msebaa-members-only-meta.php"
Task: "T027 Implement shared entitlement predicate in includes/class-msebaa-content-gate.php"

# Then in parallel:
Task: "T028 Implement post/page body gating in includes/class-msebaa-content-gate.php"
Task: "T029 Implement media URL rewriting and download endpoint in includes/class-msebaa-media-gate.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational
3. Complete Phase 3: User Story 1
4. **STOP and VALIDATE** with quickstart Scenario A (sandbox)
5. Demo SSO sign-in before building helpers/gating

### Incremental Delivery

1. Setup + Foundational → foundation ready
2. US1 → MVP SSO
3. US2 → theme helpers
4. US3 → members-only posts/pages/media
5. Polish → i18n, security pass, full quickstart A–E

### Parallel Team Strategy

1. Team completes Setup + Foundational together
2. Then: Dev A → US1; Dev B can seed meta and start US2 helpers; Dev C can start meta box UI (T026) while entitlement lands
3. Integrate return-after-login (T030) after US1 redirect context exists

---

## Notes

- [P] = different files, no incomplete-task dependencies
- [USn] maps to spec user stories for traceability
- No automated test tasks (not requested in spec); sandbox + quickstart are the acceptance path
- Commit after each task or logical group
- Stop at checkpoints to validate independently
