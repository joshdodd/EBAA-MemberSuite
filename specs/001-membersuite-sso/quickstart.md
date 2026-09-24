# Quickstart: MemberSuite SSO Validation Guide

**Feature**: `001-membersuite-sso`  
**Purpose**: Runnable checks that prove the feature end-to-end after implementation.  
**See also**: [data-model.md](./data-model.md), [contracts/](./contracts/)

## Prerequisites

- WordPress 6.x site with this plugin installed and activated (PHP 8.1+)
- MemberSuite **sandbox** tenant with Outside SSO enabled
- Sandbox credentials: one user with `receivesMemberBenefits = true`, one with false/null
- Admin account that is **not** MemberSuite-linked (for settings and operational media access)
- A published Page that will host the login form

## Setup

1. Activate **MemberSuite EBAA**.
2. Open the plugin settings (`manage_options`).
3. Enter **Tenant / Partition ID**; set **Login page** to the page that will contain the form; save.
4. Edit the login page; insert shortcode `[msebaa_sso_login]`; publish.
5. Confirm custom role `msebaa_member` exists (Users → roles / user edit after first member login).

## Scenario A — SSO sign-in (P1)

| Step | Action | Expected |
|------|--------|----------|
| A1 | Visit login page signed out; submit **valid member** credentials | Signed in within ~30s; confirmation / session present |
| A2 | Check user in WP admin | Linked meta present (`owner_id`); role `msebaa_member` if benefits true |
| A3 | Sign out; submit **invalid** credentials | Remain signed out; generic failure message; no tokens/API dumps |
| A4 | As linked user, try `wp-login.php` password | Rejected; guidance to use SSO |
| A5 | First-time identity (no prior WP user) | New WP user created once; second login reuses same user |
| A6 | Sign in with benefits-false sandbox user | Role `subscriber`; helpers report not receiving benefits |

## Scenario B — Theme helpers (P2)

On a test template or temporary `functions.php` probe (remove after):

1. While signed in as member: call helpers from [contracts/theme-helpers.md](./contracts/theme-helpers.md) — profile fields and benefits match sandbox.
2. While signed out: same helpers return documented defaults; page renders without fatals.
3. Simulate API outage after login (block outbound HTTPS or mock): helpers still return last-known values; page does not white-screen.

## Scenario C — Members-only posts/pages (P3)

| Step | Action | Expected |
|------|--------|----------|
| C1 | As editor, check **Members Only** on a post and a page; publish | Meta `_msebaa_members_only` = `1` |
| C2 | Visit as signed-out | Body replaced with members-only message + login link (no restricted body) |
| C3 | Visit as benefits-false user | Same denial as signed-out |
| C4 | Visit as benefits-true member | Full content |
| C5 | From denial, use login link; sign in as member | Returned to same post/page with full content |
| C6 | As `manage_options` admin (even non-member) | Content visible (operational access) |
| C7 | Uncheck Members Only; save | Public access restored |

## Scenario D — Members-only media (P3)

| Step | Action | Expected |
|------|--------|----------|
| D1 | Upload a file; check **Members Only**; save | Attachment flagged; public URL points at gated download |
| D2 | Open URL signed out | Redirect to login page + non-member file message |
| D3 | Open as benefits-true member | File downloads |
| D4 | Open as benefits-false user | Denied like signed-out |
| D5 | After denial, SSO as member | Return offers/downloads original file |
| D6 | Admin with `manage_options` | Can download for operations |

## Scenario E — Uninstall hygiene

1. Note a linked user ID and a flagged post ID.
2. Uninstall plugin.
3. Confirm: settings gone; `_msebaa_members_only` removed; `msebaa_*` transients gone; **user account and post/media still exist**.

## Automated smoke (when tests exist)

```bash
# From plugin root — exact runner TBD at implement time (wp-env / phpunit)
composer test
# or: vendor/bin/phpunit
```

Expect unit coverage for role mapping, authenticate block, helper defaults, and entitlement predicate with HTTP mocked.

## Pass criteria mapping

| Quickstart area | Success criteria |
|-----------------|------------------|
| A | SC-001, SC-002, SC-012 |
| B | SC-003, SC-010 |
| C | SC-004, SC-005, SC-006, SC-011 |
| D | SC-007, SC-008, SC-009, SC-011 |
