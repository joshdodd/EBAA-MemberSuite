# Contract: Members-Only Gating

**Feature**: `001-membersuite-sso`  
**Audience**: Editors, visitors, implementers

## Editor control

| Item | Value |
|------|--------|
| UI | Checkbox labeled “Members Only” (translatable) |
| Objects | `post`, `page`, `attachment` (media) |
| Who can change | Users who can edit that object (`edit_post` / attachment caps) |
| Persistence | Post meta `_msebaa_members_only` = `'1'` when checked; meta deleted when unchecked |
| Save guards | Capability check + nonce; ignore POST from users who cannot edit |

## Entitlement predicate

```text
allowed =
  NOT members_only
  OR current_user_can( 'manage_options' )
  OR ( is_user_logged_in() AND receives_member_benefits_cached === true )
```

If benefits status unknown/missing for a logged-in non-admin → **not allowed** (fail closed).

## Posts and pages (singular view)

| Requester | Behavior |
|-----------|----------|
| Allowed | Full `the_content` |
| Denied | Restricted body hidden; show message: content is for logged-in members only; include link to configured login page with return URL to this singular permalink |
| Archives / search / feeds | Titles/excerpts may appear; enforcement on singular open only |

## Media (direct access)

| Requester | Behavior |
|-----------|----------|
| Allowed | File download/stream succeeds |
| Denied | HTTP redirect to configured login page; query/message indicates file not accessible to non-members; return URL points at gated download endpoint for the attachment |
| Published URL | Attachment URLs for flagged media resolve to plugin download endpoint, not raw uploads path |

### Download endpoint (conceptual)

```http
GET /?msebaa_download={attachment_id}&msebaa_nonce={optional}
```

or equivalent rewrite. Validates attachment is members-only flagged (or still checks entitlement for defense in depth), then streams file or redirects.

## Return after sign-in

After successful SSO where user is now entitled, redirect to the stored return URL (post/page permalink or media download URL). If still not entitled, show the same denial experience.

## Uninstall

Remove `_msebaa_members_only` meta from all posts; do not delete the posts or files.
