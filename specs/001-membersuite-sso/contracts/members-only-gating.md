# Contract: Members-Only Gating

**Feature**: `001-membersuite-sso`  
**Audience**: Editors, visitors, implementers

## Editor control

| Item | Value |
|------|--------|
| UI | One checkbox labeled “Members Only” (translatable) per item |
| Objects | `post` and `page`: sidebar meta box. `attachment`: media-details field only (`attachment_fields_to_edit`). No sidebar meta box on Edit Media |
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
| Allowed | Download endpoint streams the private file |
| Denied | HTTP redirect to the configured login page; message says the file is not accessible to non-members; return URL is the gated download endpoint |
| Attachment permalink | Same redirect when denied |
| Uploads URL and resized copies | Public files are removed while the flag is on. A request that reaches WordPress redirects the same way. The body is never the file |
| Links in post content | Rendered and stored links to that file use the download endpoint while the flag is on, and the public file URL again after it is cleared |
| Edit Media File URL | Shows the download endpoint while flagged |

### Download endpoint

```http
GET /?msebaa_download={attachment_id}
```

Streams the private file when the requester is entitled. Redirects otherwise.

### Private files

While flagged, bytes live in `dirname( ABSPATH ) . '/msebaa-private/{attachment_id}/'`. Post meta `_msebaa_private_paths` lists the uploads-relative paths that must redirect. Unchecking moves the files back and deletes that meta.

## Return after sign-in

After successful SSO where user is now entitled, redirect to the stored return URL (post/page permalink or media download URL). If still not entitled, show the same denial experience.

## Uninstall

Restore privately stored members-only files to their public uploads paths, then remove `_msebaa_members_only` and `_msebaa_private_paths`. Do not delete the posts or the media files.
