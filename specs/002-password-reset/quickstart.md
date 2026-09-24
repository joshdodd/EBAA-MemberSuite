# Quickstart: Password Reset Email Validation

**Feature**: `002-password-reset`  
**See also**: [data-model.md](./data-model.md), [contracts/](./contracts/)

## Prerequisites

- WordPress with MemberSuite EBAA plugin (PHP 8.1+)
- MemberSuite sandbox: Association ID, Association Key (`tenant_id`), API user email/password
- Sandbox member email that can receive mail; a second address known not to be a member
- Page for SSO form (optional) and/or a page for `[msebaa_password_reset]`

## Setup

1. Activate plugin; open MemberSuite EBAA settings (`manage_options`).
2. Enter Association ID, Association Key, API user email, and API password; save.
3. Re-open settings: confirm password field is blank; save again without typing password; confirm reset still works afterward (Scenario B).
4. Create a page with `[msebaa_password_reset]`; publish.
5. If SSO shortcode exists, confirm “Forgot password?” reaches reset UI.

## Scenario A — Reset request (P1)

| Step | Action | Expected |
|------|--------|----------|
| A1 | Submit known MemberSuite email on reset form | In-place non-revealing confirmation within **30 seconds** under normal sandbox conditions (SC-001); no redirect; inbox receives MS reset email |
| A2 | Submit unknown email | Same confirmation style; no existence leak; no secrets in HTML |
| A3 | Open link in MS email | Complete password change on MemberSuite; site never collected a new password |
| A4 | Use Forgot password from SSO form (if present) | Inline reset panel on the same embed (v1); no undocumented URL required |
| A5 | Remove/break API credentials; submit email | Unavailable message (not false success) |
| A6 | Submit 6 times within an hour from same visitor | 6th shows same confirmation; no 6th MS email |

## Scenario B — Admin settings (P2)

| Step | Action | Expected |
|------|--------|----------|
| B1 | Save all connection fields | Persist; non-admins never see credentials on front |
| B2 | Change API password; save | Subsequent resets use new password |
| B3 | Save with blank password field | Prior password retained |
| B4 | Clear required fields; try public reset | Unavailable, not broken page |

## Pass criteria mapping

| Area | Success criteria |
|------|------------------|
| A | SC-001–SC-005, SC-007 |
| B | SC-005, SC-006 |
