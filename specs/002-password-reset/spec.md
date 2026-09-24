# Feature Specification: MemberSuite Password Reset Email

**Feature Branch**: `002-password-reset`

**Created**: 2026-09-21

**Status**: Draft

**Input**: User description: "Add the ability for the user to reset their password by triggering the MemberSuite password-reset email. Via API. Also, with this implementations, do you need to store the API User email and password? I have Association ID, Association Key and an API email and password. Note that we will never write to the MemberSuite database - read only. Lastly, an important note was given from the MemberSuite Team regarding Bearer Token generation - A successful response will give you an idToken string valid for 5 hours that you will use as the authorization to make the other non SSO API calls."

## Clarifications

### Session 2026-09-21

- Q: How should visitors reach and use the password-reset experience relative to the member sign-in form? → A: Both — Forgot-password control on the sign-in form that reveals an **inline** reset panel (v1), and a standalone shortcode editors can place on any page
- Q: How strictly should the site limit how often someone can submit password-reset requests? → A: Soft limit — about 5 requests per hour per visitor; same non-revealing confirmation if over limit
- Q: After a visitor successfully submits a password-reset request, what should happen on the site? → A: Stay on the same page; show the non-revealing confirmation in place (no redirect)
- Q: When an administrator edits connection settings later, how should the saved API service-account password behave on the settings form? → A: Blank password field; blank on save keeps the existing password; new value replaces it
- Q: When a visitor is over the soft rate limit, should the site still ask MemberSuite to send a password-reset email? → A: No — do not call MemberSuite when over the limit; still show the same non-revealing confirmation

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Request a Password Reset Email (Priority: P1)

A visitor who cannot sign in opens the site’s password-reset experience—either via a Forgot-password control on the member sign-in form (inline reset panel on that embed) or on a page that embeds the standalone password-reset shortcode. They enter the email address associated with their MemberSuite account and submit. The site asks MemberSuite to send its standard password-reset email. The visitor sees a clear confirmation that, if an account exists for that email, instructions have been sent. They complete the actual password change using MemberSuite’s own reset link and flow—not by entering a new password on this website.

**Why this priority**: Without a reset path, members who forget credentials are locked out of SSO and every members-only experience that depends on it.

**Independent Test**: From either the sign-in form’s reset path or a page with the standalone reset shortcode, submit a known MemberSuite email and confirm MemberSuite delivers the reset email and the site shows a safe confirmation; submit an unknown email and confirm the site still shows a non-revealing confirmation and does not expose whether the address exists.

**Acceptance Scenarios**:

1. **Given** a visitor on the password-reset experience who is not signed in, **When** they submit an email that belongs to a MemberSuite account, **Then** MemberSuite is instructed to send its password-reset email and the visitor remains on the same page seeing a confirmation that instructions have been sent if an account exists for that address.
2. **Given** a visitor on the password-reset experience, **When** they submit an email that does not match a MemberSuite account (or any invalid/unknown case), **Then** they remain on the same page and see the same style of non-revealing confirmation (no indication whether the email exists) and no sensitive system details.
3. **Given** a visitor who requested a reset, **When** they open the link in the MemberSuite email, **Then** they complete password change in MemberSuite’s process; this website does not collect or store a new MemberSuite password.
4. **Given** a visitor viewing the member sign-in form (`[msebaa_sso_login]`), **When** they choose the forgot-password / reset option, **Then** an inline password-reset panel is revealed on that same embed (v1 required); they do not need a separate undocumented URL. Linking to a page that only contains `[msebaa_password_reset]` is a documented fallback only if the inline panel cannot ship in the same release.
5. **Given** a page that embeds the standalone password-reset shortcode, **When** a visitor opens that page, **Then** they can submit a reset request without using the sign-in form.
6. **Given** MemberSuite is unreachable or the reset request cannot be completed, **When** the visitor submits a valid-looking email, **Then** they see a clear temporary-unavailable message and remain able to try again later; no raw service errors are shown.

---

### User Story 2 - Configure Association Connection for Reset Requests (Priority: P2)

A site administrator configures the association connection values required so the site can ask MemberSuite to send password-reset emails on behalf of members. Configuration is available only to administrators, is never exposed to visitors, and is sufficient for this read-oriented integration (including reset-email requests). After configuration, User Story 1 works in the live environment.

**Why this priority**: Reset requests cannot succeed without correct association connection settings; this is the operational prerequisite for P1 in production.

**Independent Test**: As an administrator, save Association ID, Association Key, and API service-account email/password; confirm settings persist for admins only; with settings present, a reset request from the public form can be initiated; with required settings missing, the public form fails safely with a clear unavailable message rather than a broken page.

**Acceptance Scenarios**:

1. **Given** a user with site administration rights, **When** they open the plugin connection settings, **Then** they can enter and save Association ID, Association Key, and the API service-account email and password used for authorized MemberSuite service calls.
2. **Given** saved connection settings, **When** a non-administrator visits the public site, **Then** those credentials are never shown in pages, messages, or client-visible responses.
3. **Given** required connection settings are missing or incomplete, **When** a visitor submits a password-reset request, **Then** the site does not claim success and shows a safe unavailable message.
4. **Given** an administrator updates the API service-account password, **When** they save settings, **Then** subsequent reset requests use the updated credentials without requiring a code change.
5. **Given** connection settings already include an API service-account password, **When** an administrator opens settings and saves without entering a new password, **Then** the previously saved password is retained and continues to work for reset requests.

---

### Edge Cases

- What happens if the visitor submits an empty or malformed email? The form rejects the input with a clear validation message and does not call MemberSuite.
- What happens if the visitor submits the same email repeatedly in a short period? The site applies a soft limit of about 5 requests per hour per visitor. When over the limit, the site does **not** ask MemberSuite to send another email; the visitor still sees the same non-revealing confirmation and learns neither that a limit applied nor whether an account exists.
- What happens if the member’s email in MemberSuite differs from what they type? Same non-revealing confirmation; they only receive email if MemberSuite accepts that address for reset.
- What happens if a signed-in member opens the reset experience? They may still request a reset for an email address; the site does not require them to be signed out, and does not change their current session password locally (MemberSuite remains the password authority).
- What happens regarding WordPress account passwords? MemberSuite-linked members do not use WordPress passwords for sign-in; this feature only triggers MemberSuite’s reset email, never a WordPress password-reset email for linked members.
- What about writing data to MemberSuite? The site must not create, update, or delete member profile or membership records in MemberSuite. Requesting MemberSuite’s standard password-reset email is an identity-service action only and is in scope; profile/membership write-backs remain out of scope.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Visitors MUST be able to request that MemberSuite send its standard password-reset email by submitting an email address on a site-provided password-reset experience.
- **FR-002**: The member sign-in experience (`[msebaa_sso_login]`) MUST provide a Forgot-password control that **reveals an inline password-reset panel on the same embed** (v1 required), AND the plugin MUST provide a standalone shortcode `[msebaa_password_reset]` so editors can embed the same reset experience on any page. A link to a page that only contains the standalone shortcode is a documented fallback only if the inline panel cannot ship in the same release.
- **FR-003**: After a reset request is accepted for processing, the site MUST show a confirmation on the same page (no redirect) that does not reveal whether an account exists for the submitted email.
- **FR-004**: The site MUST NOT collect, store, or set a new MemberSuite password on this website; password change completion happens through MemberSuite’s emailed reset process.
- **FR-005**: The site MUST NOT create, update, or delete MemberSuite member profile or membership records as part of this feature (read-oriented integration only, aside from requesting the password-reset email).
- **FR-006**: Site administrators MUST be able to configure Association ID, Association Key, and API service-account email and password required for authorized MemberSuite service calls used by this feature. The password field MUST appear blank when editing; saving with a blank password MUST retain the previously stored password; entering a new value MUST replace it.
- **FR-007**: API service-account credentials and association secrets MUST NOT be exposed to visitors, committed to public project files, or included in visitor-facing error messages.
- **FR-008**: Failed validation, missing configuration, and MemberSuite unavailability MUST produce clear visitor-facing messages without sensitive internals.
- **FR-009**: Reset requests MUST be protected against casual forgery (e.g., only accepted from the site’s own reset form with a verified request token appropriate to the platform).
- **FR-010**: The site MUST apply a soft rate limit of approximately 5 password-reset requests per hour per visitor. Attempts beyond the limit MUST NOT trigger a MemberSuite password-reset email request, MUST still show the **same non-revealing confirmation copy as FR-003**, and MUST NOT disclose that a limit was applied or whether an account exists.
- **FR-011**: Uninstalling or removing plugin data MUST remove stored association connection settings for this feature; it MUST NOT delete WordPress user accounts.
- **FR-012**: Visitors who are already signed in MUST still be able to submit a password-reset request; the site MUST NOT require sign-out and MUST NOT change the visitor’s current WordPress session password.
- **FR-013**: This feature MUST trigger only MemberSuite’s password-reset email; it MUST NOT send or invoke WordPress lost-password / `retrieve_password` flows for the reset form (including for MemberSuite-linked members).

### Key Entities

- **Password reset request**: A visitor-initiated ask, keyed by email address, that results in MemberSuite sending (or not sending) its reset email; the site stores no new password.
- **Association connection settings**: Administrator-configured Association ID, Association Key, and API service-account email/password used to authorize MemberSuite service calls for this feature. Storage keys: `association_id`, `tenant_id` (admin UI label **Association Key** — MemberSuite tenant / partition key; do not use “Tenant” alone in admin or visitor copy), `api_user_email`, `api_user_password`. The password is never displayed back in the settings form after save; blank submit preserves the stored value.
- **Confirmation message**: The non-revealing visitor-facing outcome shown in place on the reset experience after a request attempt (success-style wording when the request was accepted for processing or rate-limited; distinct unavailable wording when the service cannot be reached or is not configured). No redirect is used for the success path.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A visitor can submit a password-reset request from the site and see an in-place confirmation in under 30 seconds under normal conditions (no redirect away from the reset experience).
- **SC-002**: 100% of acceptance-test submissions for both known and unknown emails show non-revealing confirmation copy when the service is available (no account-existence disclosure).
- **SC-003**: 100% of successful requests for a known MemberSuite email result in MemberSuite’s password-reset email being sent (verified in sandbox/inbox during acceptance testing).
- **SC-004**: 0 visitor-facing responses in acceptance testing include API credentials, association secrets, or raw service error payloads.
- **SC-005**: With required connection settings removed, 100% of reset attempts show a safe unavailable outcome rather than a broken page or false “email sent” success.
- **SC-006**: An administrator can enter Association ID, Association Key, and API service-account credentials and confirm a sandbox reset request succeeds in under 10 minutes without developer assistance.
- **SC-007**: In acceptance testing, a sixth reset attempt within the same hour from the same visitor still receives only the non-revealing confirmation (no distinct “too many requests” or account-existence disclosure), and no additional MemberSuite reset email is triggered for that over-limit attempt.

## Assumptions

- This feature extends the MemberSuite member sign-in capability already specified for the plugin; MemberSuite remains the system of record for credentials.
- “Trigger the password-reset email” means asking MemberSuite to send its own reset message; the visitor finishes resetting on MemberSuite’s side. Building a custom “choose new password” form on this website is out of scope.
- The integration remains read-oriented for member/profile data: no MemberSuite profile or membership write-backs. Requesting the vendor’s password-reset email is explicitly allowed and is not treated as a CRM record write.
- Administrators will have Association ID, Association Key, and a dedicated API service-account email and password from MemberSuite for authorized service calls used by this feature.
- Storing the API service-account email and password in protected site configuration (not in the code repository) is required so the site can perform those authorized MemberSuite service calls; Association ID and Association Key alone are not sufficient. When editing settings, the password field stays blank; a blank save keeps the existing secret.
- MemberSuite’s own guidance for service authorization (time-limited token after API-user sign-in, used on subsequent service calls) will be followed during planning and implementation; details belong in the technical plan, not in visitor-facing behavior.
- Outside SSO member sign-in (member email/password) remains separate from the API service account; member passwords are never stored by the site.
- Linked members continue to authenticate via the MemberSuite sign-in form, not WordPress passwords; this feature does not re-enable WordPress password login for linked users.
- Rate limiting uses a soft cap of about 5 requests per hour per visitor. Over-limit attempts keep the same non-revealing confirmation and do not call MemberSuite. Exact storage of counters is an implementation detail.
- Localization of visitor-facing strings follows the same plugin text domain practices as the rest of the product.
