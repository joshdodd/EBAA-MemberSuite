# Feature Specification: MemberSuite SSO and Member Access

**Feature Branch**: `001-membersuite-sso`

**Created**: 2026-09-21

**Updated**: 2026-09-21

**Status**: Draft

**Input**: User description: "develop a wordpress plugin that integrates with the MemberSuiteRest API to authenticate users and implement a SSO process. The plugin should provide a shortcode that embeds the SSO Form and should also provide helper functions to retrieve users data and determine membership status. The plugin should also allow site admins to mark uploaded media files as \"members only\" which only allows users to download the files if they are a logged in member. Non logged in members that try to directly access the memebers only media files will be redirected to the login page with a message that the file in not accessible by non members."

**Update**: "update the spec to include a meta field checkbox that allows admins to mark a post, page or media as \"Members Only\". If a user tries to access a members only post and they are not authenticated as a Member, show a message that the content is for logged in members only."

## Clarifications

### Session 2026-09-21

- Q: Who is allowed to turn the Members Only checkbox on or off for a post, page, or media item? → A: Anyone who can edit that post, page, or media item
- Q: After someone is blocked from a members-only post or page and then signs in successfully as a member, should they be sent back to that same post or page? → A: Yes — return to the original post or page after successful member sign-in
- Q: When MemberSuite says a person does or does not receive member benefits, which WordPress roles should the plugin assign? → A: Benefits true → custom member role; benefits false → `subscriber`
- Q: After a person is linked through MemberSuite SSO, can they still sign in with a normal WordPress username and password? → A: No — linked users must use SSO only; WordPress password login is disabled for them
- Q: When a non-member sees the members-only message on a post or page, should that message include a way to sign in? → A: Yes — message includes a link to the configured login page

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Sign In via Embedded SSO Form (Priority: P1)

A visitor opens a page that contains the SSO login form. They enter their association credentials. On success they are signed into the website as a WordPress user linked to their MemberSuite identity, with profile data and membership status reflected on the site. On failure they see a clear, non-technical error and remain signed out.

**Why this priority**: Authentication is the foundation for every other member experience (helpers, gated content, role-based access). Without it, nothing else delivers value.

**Independent Test**: Place the SSO form on a blank page, sign in with valid and invalid MemberSuite credentials, and confirm a WordPress session is created only on success, with membership status available afterward.

**Acceptance Scenarios**:

1. **Given** a page displaying the SSO form and a visitor who is not signed in, **When** they submit valid MemberSuite credentials, **Then** they are signed into the site as a linked WordPress user and see confirmation they are logged in.
2. **Given** a page displaying the SSO form, **When** they submit invalid credentials, **Then** they remain signed out and see a clear message that sign-in failed, with no sensitive details exposed.
3. **Given** a WordPress user linked via MemberSuite SSO, **When** they attempt to sign in with a WordPress username and password, **Then** that password login is rejected and they are directed to use the SSO form instead.
4. **Given** a visitor who has never signed into this site before, **When** they successfully authenticate, **Then** a WordPress account is created and linked to their MemberSuite identity.
5. **Given** a returning visitor whose WordPress account is already linked, **When** they successfully authenticate, **Then** their existing account is reused (not duplicated) and their session is established.
6. **Given** a successfully authenticated member, **When** sign-in completes, **Then** their WordPress role is the custom member role if they receive member benefits, or `subscriber` if they do not.

---

### User Story 2 - Theme Helpers for Member Data and Status (Priority: P2)

A theme or content editor needs to show personalized member information (name, profile fields, membership status, login state) without building custom integrations. Documented helper functions return safe, predictable values that themes can use on any page after the site has initialized.

**Why this priority**: Once members can sign in, the site must expose membership and profile data to themes in a stable, reusable way — this is how member-aware pages are built.

**Independent Test**: With a signed-in member and a signed-out visitor, call each published helper and confirm correct values for the member and safe defaults for the visitor, including when membership data is temporarily unavailable.

**Acceptance Scenarios**:

1. **Given** a signed-in member with synced profile data, **When** a theme requests current member profile information via helpers, **Then** the helpers return the member's core profile fields and membership status.
2. **Given** a visitor who is not signed in, **When** a theme calls the same helpers, **Then** each helper returns a documented safe default and does not produce an error visible to the visitor.
3. **Given** a signed-in user whose membership no longer receives member benefits, **When** a theme checks membership status via helpers, **Then** the helpers report that the user is signed in but does not receive member benefits.
4. **Given** membership data that cannot be refreshed from MemberSuite, **When** a theme calls helpers, **Then** helpers still return usable cached or default values without failing the page.

---

### User Story 3 - Mark Content Members-Only and Gate Access (Priority: P3)

An editor who can edit a post, page, or media item marks it as Members Only using a checkbox in the editor. Logged-in members who receive member benefits can view or download that content. Non-members who open a members-only post or page see a clear message that the content is for logged-in members only (the restricted body content is not shown). Non-members who open a members-only media file's direct URL are redirected to the login page with a message that the file is not accessible to non-members.

**Why this priority**: Protects member-exclusive content and assets; depends on authentication and membership status already working, but delivers distinct business value once those exist.

**Independent Test**: Mark one post, one page, and one media file as Members Only; attempt access as (a) signed-out visitor, (b) signed-in non-member, and (c) signed-in member; verify only (c) sees/downloads the content, (a)/(b) see the denial message on posts/pages, and (a)/(b) are redirected for media.

**Acceptance Scenarios**:

1. **Given** a post, page, or media item in the editor, **When** a user who can edit that item enables the Members Only checkbox and saves, **Then** the item is treated as members-only for subsequent public access attempts.
2. **Given** a post or page marked Members Only, **When** a signed-in member who receives member benefits views it, **Then** the full content is shown normally.
3. **Given** a post or page marked Members Only, **When** a visitor who is not signed in (or a signed-in user who does not receive member benefits) views it, **Then** the restricted body content is not shown and they see a message that the content is for logged-in members only, including a link to the configured login page.
4. **Given** a non-member was shown the members-only message on a post or page and then successfully signs in as a member, **When** sign-in completes, **Then** they are returned to that same post or page and can view the full content.
5. **Given** a media file marked Members Only, **When** a signed-in member who receives member benefits requests the file, **Then** the file downloads normally.
6. **Given** a media file marked Members Only, **When** a visitor who is not signed in requests the file's direct URL, **Then** they are redirected to the site's login page and see a message that the file is not accessible to non-members.
7. **Given** a media file marked Members Only, **When** a signed-in user who does not receive member benefits requests the file, **Then** they are denied access and directed to the login page (or an equivalent denial destination) with a message that the file is not accessible to non-members.
8. **Given** a post, page, or media item that is not marked Members Only, **When** any visitor requests it, **Then** access behaves as normal public content.
9. **Given** a previously members-only post, page, or media item, **When** a user who can edit that item clears the Members Only checkbox and saves, **Then** subsequent public access is unrestricted.

---

### Edge Cases

- What happens when a MemberSuite-linked user tries WordPress password login? Password login is rejected for linked accounts; they must use the SSO form. Site administrators who are not MemberSuite-linked retain normal WordPress login.
- What happens when MemberSuite is unreachable after a member is already signed in? Helpers and membership checks use the last known cached membership data; members-only content and media decisions use that cached status rather than failing open.
- How does the system handle a MemberSuite account that authenticates successfully but has incomplete profile fields? Sign-in still succeeds; missing fields are stored as empty and helpers return empty/safe defaults for those fields.
- What happens when two WordPress accounts would map to the same MemberSuite identity? The system links to the existing linked account and does not create a second link; sign-in proceeds with the already-linked user.
- What happens when a members-only post or page URL is shared publicly? Non-members who open it see the members-only message and never see the restricted body content.
- What happens when a members-only file URL is shared publicly? Direct access by non-members always redirects to login with the denial message; the file contents are never served to unauthorized requesters.
- What happens when an administrator (who can manage the site) requests members-only content or media? Site administrators retain access for operational purposes even if they are not members.
- What happens after a denied user successfully signs in from a media redirect or after viewing a members-only post/page message? They are returned to the originally requested post, page, or file when they now qualify as a member; if they still do not qualify, they remain denied with the same message.
- What happens if a members-only post appears in archives, feeds, or search results? Listing teasers may remain visible per normal site behavior, but opening the single post or page still enforces the members-only message and hides restricted body content for non-members.
- What happens when Members Only is checked on a draft? The flag is stored; public enforcement applies once the content is published and publicly reachable.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST authenticate visitors against MemberSuite using the Outside SSO model (credentials verified by MemberSuite; the site does not store or validate MemberSuite passwords itself). For WordPress users linked to a MemberSuite identity, the system MUST disable WordPress password login and require the SSO form; unlinked users (including site administrators) MAY continue to use normal WordPress login.
- **FR-002**: Editors MUST be able to embed an SSO login form on any page or post via a shortcode.
- **FR-003**: On successful authentication, the system MUST create a WordPress user if none exists, or sign into the existing linked WordPress user, associating the account by a stable MemberSuite identifier (not by email alone).
- **FR-004**: On successful authentication, the system MUST sync core profile data from MemberSuite onto the linked WordPress user (at minimum: name, email, and membership benefit eligibility).
- **FR-005**: The system MUST assign WordPress roles from MemberSuite's `receivesMemberBenefits` using a single deterministic mapping: benefits true → a plugin-defined custom member role; benefits false → WordPress `subscriber`. Site administrator and other privileged WordPress roles MUST NOT be overwritten by this mapping.
- **FR-006**: The system MUST publish a documented set of theme-facing helper functions (6–8) that retrieve current user/member data and membership status, return safe defaults when no member is present, and never cause a visible page failure when MemberSuite is unavailable.
- **FR-007**: Theme helpers MUST reflect membership and profile data that is kept reasonably fresh via a caching layer, without requiring the theme author to call MemberSuite directly.
- **FR-008**: Users who can edit a given post, page, or media item MUST be able to mark or unmark it as Members Only via a checkbox in the content/media editor; the selection MUST persist with that item. Users who cannot edit the item MUST NOT be able to change the flag.
- **FR-009**: The system MUST allow full view of a members-only post or page only when the requester is signed in and currently receives member benefits (or is a site administrator performing operational access).
- **FR-010**: When a non-member or signed-out visitor requests a members-only post or page, the system MUST NOT display the restricted body content and MUST show a message that the content is for logged-in members only, including a link to the configured login page (so the visitor can sign in and return).
- **FR-011**: After a denied visitor successfully signs in and qualifies as a member following a members-only post or page denial, the system MUST return them to the originally requested post or page.
- **FR-012**: The system MUST allow download of a members-only media file only when the requester is signed in and currently receives member benefits (or is a site administrator performing operational access).
- **FR-013**: When a non-member or signed-out visitor requests a members-only media file by direct URL, the system MUST redirect them to the configured login page and display a message that the file is not accessible to non-members.
- **FR-014**: After a denied visitor successfully signs in and qualifies as a member following a media denial, the system MUST offer return to the originally requested media file.
- **FR-015**: Failed sign-in, denied content access, denied media access, and service-unavailable states MUST show clear visitor-facing messages and MUST NOT reveal credentials, API errors, or other sensitive internals.
- **FR-016**: Site administrators MUST be able to configure MemberSuite connection settings and the login page used for media-access redirects and for post/page return-after-login flows.
- **FR-017**: Uninstalling the plugin MUST remove plugin settings, Members Only flags, and cached membership data; it MUST NOT delete WordPress user accounts or the underlying posts, pages, or media files.

### Key Entities

- **Member identity link**: Association between a WordPress user and a stable MemberSuite person/individual identifier; includes last-synced profile fields and whether the person receives member benefits.
- **SSO session**: The signed-in WordPress session established only after MemberSuite confirms credentials; carries no MemberSuite password.
- **Membership status**: Whether the linked identity currently receives member benefits; drives role mapping (custom member role vs `subscriber`), helper responses, and members-only entitlement.
- **Members-only content item**: A post, page, or media item flagged via the Members Only checkbox by a user who can edit that item; entitlement is evaluated on every public view or direct file access attempt.
- **Login redirect context**: Remembered target (the denied post, page, or media URL) used to return a user to their original request after successful member sign-in.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A visitor with valid credentials can complete sign-in from the embedded form and reach a signed-in state in under 30 seconds under normal network conditions.
- **SC-002**: 100% of failed sign-in attempts leave the visitor signed out and show a human-readable error with no sensitive details.
- **SC-003**: Theme helpers return a correct membership status for a signed-in member and documented safe defaults for a signed-out visitor in 100% of checks during acceptance testing.
- **SC-004**: 100% of views of members-only posts or pages by signed-out visitors or signed-in non-members show the "for logged-in members only" message with a login-page link and do not reveal restricted body content.
- **SC-005**: 100% of views of members-only posts or pages by signed-in members who receive member benefits show the full content.
- **SC-006**: After a post/page denial followed by successful member sign-in, 100% of acceptance-test cases return the user to the originally requested post or page with full content visible.
- **SC-007**: 100% of direct URL requests to members-only media by signed-out visitors result in a redirect to the login page with the non-member denial message, and the file contents are never delivered.
- **SC-008**: 100% of direct URL requests to members-only media by signed-in members who receive member benefits result in a successful download.
- **SC-009**: Signed-in users who do not receive member benefits are denied members-only media at the same rate as signed-out visitors (100% denial in acceptance testing).
- **SC-010**: When MemberSuite is unavailable, signed-in members can still view pages that use theme helpers without seeing a broken page or raw error (0 page failures attributable to helper calls in that scenario).
- **SC-011**: An editor who can edit the items can mark a post, page, and media item as Members Only and verify restricted vs allowed access for each in under 10 minutes without developer assistance.
- **SC-012**: 100% of WordPress password login attempts by MemberSuite-linked users are rejected in acceptance testing, with guidance to use the SSO form.

## Assumptions

- Authentication uses MemberSuite Outside SSO (Option 1) as established by the project constitution; other SSO modes are out of scope for this feature. MemberSuite-linked users authenticate only via the SSO form; WordPress password login is disabled for those accounts.
- "Logged-in member" / "authenticated as a Member" means a signed-in WordPress user linked to MemberSuite whose current status indicates they receive member benefits (`receivesMemberBenefits`), which maps to the plugin's custom member role.
- Role mapping is fixed for v1: `receivesMemberBenefits` true → custom member role; false → `subscriber`. Privileged roles (e.g. administrator) are never downgraded by sync.
- Signed-in users who do not receive member benefits are treated like non-members for all members-only checks (posts, pages, and media).
- The Members Only control is a checkbox available when editing a post, page, or media item to anyone who can edit that item; the flag persists with that item.
- Site administrators retain operational *view/download* access to members-only content; marking Members Only is not limited to administrators.
- Denial UX differs by content type by design: posts and pages stay on the same URL and replace restricted body content with the members-only message plus a link to the configured login page; media direct URLs redirect to the configured login page with a file-access denial message. After successful member sign-in, both flows return the user to the originally requested post, page, or media URL when they qualify.
- Core profile sync covers standard identity fields needed by themes (name, email, membership benefit flag); extended custom fields can be added in a later feature.
- The login page used for media redirects and post/page return-after-login is configurable by administrators and will typically be the page that embeds the SSO form.
- After a successful sign-in that followed a content or media denial, the user is returned to the originally requested URL when they now qualify; otherwise they remain denied.
- Unmarked posts, pages, and media remain publicly accessible as usual.
- Archive, feed, and search listings may still surface titles or excerpts; enforcement applies when the single post/page is opened or when a media file is requested directly.
- Caching of membership/profile data is required so theme helpers and members-only checks do not depend on a live MemberSuite call on every page view or download attempt; when a last-known membership status exists it may be used, but if membership status cannot be confirmed (no usable cache), members-only gating fails closed and content is not shown.
- Members-only content gating is in scope per constitution v1.1.0.
