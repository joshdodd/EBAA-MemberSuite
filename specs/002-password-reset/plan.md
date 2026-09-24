# Implementation Plan: MemberSuite Password Reset Email

**Branch**: `002-password-reset` | **Date**: 2026-09-21 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-password-reset/spec.md`

## Summary

Add a WordPress-facing password-reset experience that asks MemberSuite to send its standard reset email (visitor finishes on MemberSuite). Approach: extend plugin settings with Association ID, Association Key, and API service-account credentials; obtain a cached Bearer `idToken` via API-user `loginUser`; call a sandbox-confirmed reset-email API method; expose `[msebaa_password_reset]` plus a Forgot-password path from `[msebaa_sso_login]`; enforce ~5/hour/visitor rate limit without calling MemberSuite when over limit; always show non-revealing in-place confirmation on success/limit paths.

## Technical Context

**Language/Version**: PHP 8.1+ (WordPress plugin; `Requires PHP: 8.1`)

**Primary Dependencies**: WordPress 6.x (Settings API, HTTP `wp_remote_*`, Transients, Shortcodes); MemberSuite REST (`rest.membersuite.com`) API-user auth + password-reset email operation

**Storage**: Option `msebaa_settings` (extended); transients `msebaa_api_id_token`, `msebaa_pwreset_{hash}`; no custom tables

**Testing**: Manual MemberSuite sandbox per `quickstart.md` (required); optional PHPUnit for rate limit + settings password-retain when harness exists

**Target Platform**: WordPress on PHP-FPM/Apache or nginx; HTTPS in production

**Project Type**: WordPress plugin (extends same package as `001-membersuite-sso`)

**Performance Goals**: Reset request → in-place confirmation under 30s (SC-001); reuse cached API token within 4h TTL

**Constraints**: Prefix `msebaa_` / `Msebaa_`; store API credentials only in protected settings; Bearer header format per MemberSuite; no CRM writes; no visitor-facing secrets; nonces on forms; rate limit 5/hour without MS call when exceeded; blank password retain on settings save

**Scale/Scope**: Single association; one standalone shortcode; SSO form forgot link; no on-site set-password UI

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Plan alignment |
|-----------|--------|----------------|
| I. WordPress-native & standards-compliant | PASS | Settings API, shortcodes, `wp_remote_*`, prefixed classes under `includes/` `admin/` `public/` |
| II. MemberSuite system of record | PASS | Passwords remain MemberSuite’s; site only requests vendor reset email |
| III. Credential & session security | PASS | API password in options only; never to browser/logs; nonces; `manage_options` |
| IV. Stable theme-facing API | PASS | No new required theme helpers; shortcodes are editor-facing (optional later helper for reset URL deferred) |
| V. Cache-first graceful degradation | PASS | API Bearer cached in transient; unavailable messaging when MS/config fails |
| VI. Members-only gating | N/A | Unchanged by this feature |
| Scope & structure | PASS | Password-reset email recovery is an enumerated capability in constitution v1.2.0+ |
| Quality gates | PASS | Sandbox verification of reset email + failure cases in quickstart |

**Gate result**: PASS — Complexity Tracking empty.

### Post–Phase 1 re-check

Design artifacts keep read-only CRM boundary, secure credential handling, non-revealing UX, and sandbox-gated API path confirmation. **PASS**.

## Project Structure

### Documentation (this feature)

```text
specs/002-password-reset/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   ├── password-reset-shortcode.md
│   ├── membersuite-password-reset-api.md
│   └── admin-settings-extension.md
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root — additive)

```text
includes/
├── class-msebaa-settings.php          # Extend: association_id, api_user_*, blank-password retain
├── class-msebaa-api-client.php        # Extend: API login, token cache, request_password_reset_email()
├── class-msebaa-password-reset.php    # Orchestration: validate, rate limit, call client, map outcomes
└── class-msebaa-rate-limit.php        # Optional small helper for pwreset counters
admin/
└── class-msebaa-admin-settings.php    # Extend settings fields + password UI
public/
├── class-msebaa-shortcode.php         # Add forgot link / inline reset on SSO form
├── class-msebaa-password-reset-shortcode.php  # [msebaa_password_reset]
├── css/msebaa-sso-form.css            # Shared / extended styles
└── css/msebaa-password-reset.css      # Or shared stylesheet
uninstall.php                          # Ensure api secrets + pwreset/api token transients removed
```

**Structure Decision**: Same single-plugin layout as `001-membersuite-sso`; this feature adds password-reset classes and extends settings/API client rather than a second plugin.

## Complexity Tracking

> No unjustified constitution violations.
