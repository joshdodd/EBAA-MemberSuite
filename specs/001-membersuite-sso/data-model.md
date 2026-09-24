# Data Model: MemberSuite SSO and Member Access

**Feature**: `001-membersuite-sso`  
**Date**: 2026-09-21

## Entities

### 1. Plugin Settings (`msebaa_settings` option)

| Field | Type | Required | Validation | Notes |
|-------|------|----------|------------|-------|
| `tenant_id` | string | yes | sanitize_text_field; non-empty for SSO to run | MemberSuite tenant / partition key |
| `association_id` | string | no | sanitize_text_field | Optional GUID for admin reference |
| `login_page_id` | int | yes (for gating UX) | absint; must be published page when set | Page that embeds SSO shortcode |
| `http_timeout` | int | no | absint; clamp 5–30; default 15 | Seconds for `wp_remote_*` |

**Lifecycle**: Seeded on activation with empty tenant / default timeout. Removed on uninstall.

---

### 2. Member Identity Link (WordPress user + user meta)

Represents the association between a WP user and a MemberSuite Individual.

| Field | Storage | Type | Validation | Notes |
|-------|---------|------|------------|-------|
| WordPress user ID | `wp_users.ID` | int | core | Created via `wp_insert_user` on first SSO |
| `msebaa_owner_id` | user meta | string (GUID) | sanitize_text_field; unique among linked users | Stable MemberSuite Individual ID (`whoami.ownerId`) |
| `msebaa_user_id` | user meta | string (GUID) | sanitize_text_field | MemberSuite `whoami.userId` if distinct |
| `msebaa_membership_id` | user meta | string | sanitize_text_field | May be empty |
| `msebaa_receives_member_benefits` | user meta | string `'1'`/`'0'` | cast from bool; null/false → `'0'` | Source of entitlement |
| `msebaa_first_name` | user meta | string | sanitize_text_field | Synced from MemberSuite |
| `msebaa_last_name` | user meta | string | sanitize_text_field | Synced from MemberSuite |
| `msebaa_email` | user meta | string | sanitize_email | Mirror; WP `user_email` also updated when safe |
| `msebaa_last_synced` | user meta | string (GMT datetime) | `gmdate` | Cache freshness signal |
| `msebaa_linked` | user meta | string `'1'` | presence = SSO-linked | Triggers password-login block |

**Relationships**: 1:1 WordPress user ↔ MemberSuite `owner_id`. Lookup by `owner_id` never by email alone.

**State transitions**:

```text
[Unlinked visitor]
    --SSO success, no owner_id match--> [Linked WP user created]
    --SSO success, owner_id match-----> [Existing linked user signed in]

[Linked user]
    --SSO success--> profile/benefits meta refreshed; role remapped (unless privileged)
    --WP password login attempt--> rejected (authenticate filter)
```

**Role assignment** (not stored as separate entity):

- Benefits true → role `msebaa_member`
- Benefits false/null → role `subscriber`
- Skip remap if user has privileged role (e.g. `administrator`)

---

### 3. Membership Status Cache (transient)

| Field | Storage | Type | Notes |
|-------|---------|------|-------|
| Snapshot | transient `msebaa_member_{user_id}` | array | `receives_member_benefits`, profile subset, `synced_at` |
| TTL | — | int | Default 1800s (30 min); configurable later if needed |

**Invalidation**: On successful SSO sync; on uninstall (all `msebaa_*` transients).

**Fail-closed rule**: Entitlement requires an affirmative cached/synced benefits flag. Missing/unknown → deny members-only content.

---

### 4. Members-Only Content Item (post / page / attachment meta)

| Field | Storage | Type | Validation | Notes |
|-------|---------|------|------------|-------|
| `_msebaa_members_only` | post meta | string `'1'` or absent | Only `'1'` when checkbox on; delete meta when off | Applies to `post`, `page`, `attachment` |

**Save rules**: Actor must `current_user_can( 'edit_post', $post_id )` (or attachment equivalent); nonce verified; value sanitized.

**Entitlement evaluation** (runtime, not stored):

Allowed if any of:

1. Item not flagged, or
2. Requester has `manage_options` (operational admin), or
3. Requester is logged in AND `msebaa_receives_member_benefits` is true (from meta/cache)

Otherwise: posts/pages → denial message; media → login redirect.

---

### 5. Login Redirect Context

| Field | Storage | Type | Validation | Notes |
|-------|---------|------|------------|-------|
| Return URL | query arg `msebaa_redirect` and/or transient `msebaa_redirect_{token}` | string URL | `wp_validate_redirect` / local-only | Original post, page, or media download URL |
| TTL | transient | ~15 minutes | — | Cleared after successful redirect |

**State transitions**:

```text
[Denial] --encode return URL on login link--> [Pending return]
[Pending return] --SSO success + entitled--> [Redirect to return URL]
[Pending return] --SSO success + not entitled--> [Remain on denial / message]
[Pending return] --expire/missing--> [Login page default]
```

---

### 6. Custom Role

| Field | Value |
|-------|-------|
| Role slug | `msebaa_member` |
| Display name | Member (translatable) |
| Capabilities | Same baseline as `subscriber` (read) unless product later expands |

Registered on activation; removed on uninstall (users remapped to `subscriber` on uninstall if still on custom role — document in uninstall behavior; do not delete user accounts).

---

## Validation Summary (from requirements)

| Rule | Entity |
|------|--------|
| Link by MemberSuite ID only | Member Identity Link |
| Benefits → role deterministic | Member Identity Link + Role |
| Members Only editable only if can edit item | Members-Only Content Item |
| Fail closed when status unknown | Membership Status Cache + gating |
| Uninstall removes settings, flags, cache — not users/content | Settings, meta, transients |

## Out of scope storage

- MemberSuite passwords
- Persistent MemberSuite API service-account secrets (v1 login-time tokens only; not written to DB beyond request lifecycle)
- Extended custom CRM fields beyond core profile
