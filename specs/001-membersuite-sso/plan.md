# Implementation Plan: MemberSuite SSO and Member Access

**Branch**: `001-membersuite-sso` | **Date**: 2026-09-21 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-membersuite-sso/spec.md`

## Summary

Build a WordPress plugin that authenticates visitors through MemberSuite REST **Outside SSO (Option 1)**, provisions/links WordPress users by stable Individual ID, maps `receivesMemberBenefits` to a custom member role (or `subscriber`), exposes theme helper functions backed by cached membership data, and gates posts/pages/media marked Members Only (fail closed). Approach: Settings API + shortcode form → server-side SSO handshake (`loginUser` → `JWTSSO` → `bearerTokenSSO` → `whoami`) → WP session; meta-box flag + content filter / gated download endpoint for entitlement.

## Technical Context

**Language/Version**: PHP 8.1+ (WordPress plugin; `Requires PHP: 8.1`)

**Primary Dependencies**: WordPress 6.x core APIs (Settings, HTTP `wp_remote_*`, Users/Roles, Transients, Meta); MemberSuite REST API (`rest.membersuite.com`) Outside SSO

**Storage**: WordPress options (`msebaa_settings`), user meta (identity link + profile snapshot), post meta (`_msebaa_members_only`), transients (`msebaa_member_{user_id}`); no custom tables

**Testing**: Manual MemberSuite sandbox acceptance (required); PHPUnit + WordPress test bootstrap / wp-env with `pre_http_request` mocks for unit/integration of helpers, roles, gating, authenticate filter

**Target Platform**: WordPress site (PHP-FPM / Apache or nginx); Local WP and production HTTPS

**Project Type**: WordPress plugin (single package at repository root)

**Performance Goals**: Sign-in complete under 30s under normal network (SC-001); theme helpers and entitlement checks serve from cache/meta with no MemberSuite call on the common render/download authorization path

**Constraints**: Prefix `msebaa_` / `Msebaa_`; Outside SSO only; never store MemberSuite passwords; sanitize/escape/nonce/capability everywhere; fail closed when membership unknown; privileged WP roles never downgraded; linked users cannot use WP password login; HTTPS + explicit HTTP timeouts

**Scale/Scope**: Single-association EBAA site; ~7 theme helpers; one shortcode; members-only for post, page, attachment; core profile fields only (name, email, benefits)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Plan alignment |
|-----------|--------|----------------|
| I. WordPress-native & standards-compliant | PASS | Hooks-only plugin; WPCS; Settings API; prefixed symbols; standard directory layout |
| II. MemberSuite system of record / Outside SSO | PASS | Option 1 REST flow; link by `ownerId`; deterministic benefits → role mapping |
| III. Credential & session security | PASS | Server-side tokens only; nonces on SSO + meta saves; `manage_options` for settings; no secrets in repo/logs |
| IV. Stable theme-facing API | PASS | Seven documented helpers in `contracts/theme-helpers.md`; internals not public |
| V. Cache-first graceful degradation | PASS | User meta + transients; helpers never hit API per tag; invalidate on SSO sync |
| VI. Members-only gating fail closed | PASS | Meta checkbox; same entitlement predicate; post/page message; media redirect; unknown → deny |
| Scope & structure | PASS | Shortcode, provision/link, sync, roles, helpers, cache, gating only; `includes/` `admin/` `public/` `uninstall.php` |
| Quality gates | PASS | Sandbox verification called out in quickstart; review against sanitization/cache/helpers/fail-closed |

**Gate result**: PASS — no unjustified violations. Complexity Tracking left empty.

### Post–Phase 1 re-check

Design artifacts (`research.md`, `data-model.md`, `contracts/*`, `quickstart.md`) preserve Outside SSO, helper contract, cache-first + fail-closed gating, and uninstall boundaries. **PASS**.

## Project Structure

### Documentation (this feature)

```text
specs/001-membersuite-sso/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── theme-helpers.md
│   ├── sso-shortcode.md
│   ├── membersuite-rest-sso.md
│   ├── members-only-gating.md
│   └── admin-settings.md
├── checklists/
│   └── requirements.md
└── tasks.md              # Phase 2 (/speckit-tasks) — not created here
```

### Source Code (repository root)

```text
membersuite-ebaa.php          # Plugin bootstrap, constants, hooks registration
uninstall.php
includes/
├── class-msebaa-plugin.php           # Boot / service wiring
├── class-msebaa-settings.php         # Settings read helpers
├── class-msebaa-api-client.php       # MemberSuite REST (loginUser, JWTSSO, whoami, …)
├── class-msebaa-sso.php              # SSO orchestration, user provision/link, role map
├── class-msebaa-user-repository.php  # User meta identity + profile snapshot
├── class-msebaa-cache.php            # Transients invalidate/get
├── class-msebaa-roles.php            # Register msebaa_member; mapping rules
├── class-msebaa-helpers.php          # Theme-facing functions (or helpers.php)
├── class-msebaa-content-gate.php     # Post/page the_content denial
└── class-msebaa-media-gate.php       # URL filter + download endpoint
admin/
├── class-msebaa-admin-settings.php   # Settings API page
└── class-msebaa-members-only-meta.php# Checkbox for post/page/attachment
public/
├── class-msebaa-shortcode.php        # [msebaa_sso_login]
├── css/msebaa-sso-form.css           # Enqueued only where shortcode present
└── js/msebaa-sso-form.js             # Optional; enqueue only if needed
languages/
└── membersuite-ebaa.pot              # Generated later
tests/                                # PHPUnit (added at implement)
├── bootstrap.php
├── test-helpers.php
├── test-role-mapping.php
├── test-content-gate.php
└── test-authenticate-block.php
```

**Structure Decision**: Single WordPress plugin at repo root per constitution (no separate frontend/backend packages). Classes follow `Msebaa_*` → `class-msebaa-*.php` naming; admin vs public code split for load isolation.

## Complexity Tracking

> No constitution violations requiring justification.
