<!--
SYNC IMPACT REPORT (scratch — remove before committing the amended constitution)
Version change: (none / unratified template) → 1.0.0
Bump rationale: Initial ratification. All template placeholders replaced with concrete,
testable governance for the MemberSuite EBAA WordPress plugin.

Modified principles:
- [PRINCIPLE_1_NAME] → I. WordPress-Native and Standards-Compliant
- [PRINCIPLE_2_NAME] → II. MemberSuite Is the System of Record
- [PRINCIPLE_3_NAME] → III. Credential and Session Security (NON-NEGOTIABLE)
- [PRINCIPLE_4_NAME] → IV. Stable Theme-Facing API
- [PRINCIPLE_5_NAME] → V. Cache-First with Graceful Degradation

Added sections:
- [SECTION_2_NAME] → Scope and Technical Constraints
- [SECTION_3_NAME] → Development Workflow and Quality Gates

Removed sections: none

Follow-up TODOs: none. RATIFICATION_DATE set to the date of initial adoption.
-->

# MemberSuite EBAA Constitution

## Core Principles

### I. WordPress-Native and Standards-Compliant

The plugin MUST be a standard WordPress plugin: all behavior is registered through actions and
filters, and no WordPress core file is ever modified. Code MUST follow the WordPress Coding
Standards and target PHP 8.1+. Every function, class, option, transient, hook, and cron event MUST
carry the `msebaa_` / `Msebaa_` prefix. Core APIs (Options, Settings, HTTP, Transients, Cron,
`WP_Error`) MUST be used instead of custom equivalents.

Rationale: the plugin runs inside a shared site; unprefixed or non-standard code creates collisions
and upgrade risk that outlive any short-term convenience.

### II. MemberSuite Is the System of Record

MemberSuite owns identity, membership status, and profile truth. WordPress user records are a
mirror, never an authority. Authentication MUST use the MemberSuite REST API under the Outside SSO
model (Option 1): credentials are verified by MemberSuite, and the plugin never implements its own
password store or membership logic. On successful authentication the plugin MUST provision a new
WordPress user or link an existing one via a stable MemberSuite identifier — never by email string
matching alone. Role assignment MUST derive deterministically from `receivesMemberBenefits`, with
exactly one mapping path from that field to a WordPress role.

Rationale: a single, deterministic source of truth is what keeps entitlement decisions auditable
and prevents WordPress and MemberSuite from silently disagreeing about who is a member.

### III. Credential and Session Security (NON-NEGOTIABLE)

API keys, secrets, and credentials MUST NOT be committed to the repository or exposed to the
browser; they live in configuration outside version control. All MemberSuite calls MUST use
`wp_remote_*` over HTTPS with an explicit timeout. Login and any state-changing request MUST verify
a nonce and, where privileged, a specific capability via `current_user_can()`. All input MUST be
sanitized on the way in and all output escaped on the way out. Credentials and personally
identifiable data MUST NOT be written to logs or error messages shown to users.

Rationale: this plugin is an authentication boundary — a single leak here compromises member
accounts, not just site content.

### IV. Stable Theme-Facing API

The plugin MUST expose between six and eight documented, theme-friendly helper functions (for
example: current member, membership status, member benefit eligibility, profile field access,
login state, and logout URL). These functions form the public contract: they MUST be safely
callable from any theme template after `init`, MUST return predictable typed values with safe
defaults when no member is present, and MUST NOT emit output or fatal on API failure. Internal
classes and API clients are implementation details and MUST NOT be presented as theme API.
Changing or removing a helper's signature or return contract is a breaking change.

Rationale: themes are written once and maintained by others; an unstable helper surface turns
every plugin update into a site-wide regression risk.

### V. Cache-First with Graceful Degradation

Every MemberSuite response used for page rendering MUST pass through a caching layer built on
WordPress transients with `msebaa_`-prefixed keys and an explicit expiration. Theme helper
functions MUST serve from cache on the common path and MUST NOT trigger a remote API call per
template tag. Caches MUST be invalidated on the events that change the underlying data — at
minimum login, profile sync, and membership status change. When MemberSuite is unreachable or
returns an error, the plugin MUST degrade gracefully: return safe defaults, surface a
human-readable message, and never expose a fatal error or raw API payload to a visitor.

Rationale: an external API on the render path becomes the site's availability ceiling unless
caching and fallback behavior are mandatory rather than optional.

## Scope and Technical Constraints

- Platform: WordPress plugin, PHP 8.1+, text domain `membersuite-ebaa`.
- Integration: MemberSuite REST API, Outside SSO (Option 1) only. Other SSO modes are out of scope
  until this constitution is amended.
- Required capabilities, and no more: shortcode-based embeddable login form, user
  provisioning/linking, core profile data sync, role mapping from `receivesMemberBenefits`, 6–8
  theme helper functions, and the caching layer that serves them.
- Structure: `includes/`, `admin/`, `public/`, `languages/`, plus `uninstall.php`. Every PHP file
  guards direct access with an `ABSPATH` check; one class per file.
- Lifecycle: activation seeds defaults and registers roles; deactivation clears scheduled events
  but MUST NOT delete user data; `uninstall.php` removes plugin options, transients, and cron
  events.
- All user-facing strings MUST be translatable. Assets MUST be enqueued, never hardcoded, and
  loaded only on the screens that use them.
- Anything not required by the capabilities above is out of scope; new surface area requires a
  documented justification before it is built.

## Development Workflow and Quality Gates

- Every change MUST be traceable to a spec and plan produced through the Spec Kit workflow before
  implementation begins.
- Authentication, user provisioning, role mapping, and cache invalidation paths MUST be verified
  against a MemberSuite sandbox — including the failure cases (bad credentials, API timeout,
  missing profile fields) — before release.
- Code review MUST confirm compliance with these principles. A reviewer MUST reject changes that
  bypass sanitization, escaping, nonce checks, the caching layer, or the helper-function contract.
- Breaking changes to the theme-facing helper functions require a MAJOR version bump and a
  migration note for theme maintainers.
- Complexity MUST be justified in review; the simplest implementation satisfying the principle
  wins by default.

## Governance

This constitution supersedes other practices and conventions for this plugin. Where it conflicts
with a habit, tool default, or prior implementation, this document governs.

Amendments MUST be proposed as a written change to this file, stating the motivation and the
impact on existing code, and MUST be approved by the project maintainer before merge. Versioning
follows semantic versioning: MAJOR for removing or redefining a principle in a
backward-incompatible way, MINOR for adding a principle or materially expanding guidance, PATCH
for clarifications and non-semantic refinements. The amendment that changes this file MUST also
update the version line and `Last Amended` date in the same change.

Compliance is reviewed at every pull request. Runtime development guidance lives in
`.cursor/rules/wordpress-plugin-standards.mdc`, which MUST remain consistent with this
constitution; if the two disagree, this constitution wins and the rule file MUST be corrected.

**Version**: 1.0.0 | **Ratified**: 2026-09-15 | **Last Amended**: 2026-09-15
