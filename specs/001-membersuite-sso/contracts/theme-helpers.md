# Contract: Theme Helper Functions

**Feature**: `001-membersuite-sso`  
**Audience**: Theme and template authors  
**Stability**: Public API — breaking changes require MAJOR version bump

Helpers are plain PHP functions, prefixed `msebaa_`, callable after WordPress `init`. They MUST NOT echo, die, or throw. On failure or missing member they return the documented safe default.

## `msebaa_is_user_logged_in(): bool`

True when a WordPress user session is active (MemberSuite-linked or not).

**Default**: `false`

## `msebaa_is_member(): bool`

True when the current user is signed in and currently receives member benefits (`receivesMemberBenefits`), based on cached/synced meta. Site administrators are **not** automatically true here unless they also receive benefits; use WordPress `current_user_can( 'manage_options' )` separately for operational overrides in themes if needed.

**Default**: `false`

## `msebaa_receives_member_benefits(): bool`

Cached benefits flag for the current user.

**Default**: `false` (signed out or unknown)

## `msebaa_get_current_member(): ?array`

Associative array when a linked session exists:

```php
array(
	'owner_id'                   => string, // MemberSuite Individual GUID
	'email'                      => string,
	'first_name'                 => string,
	'last_name'                  => string,
	'receives_member_benefits'   => bool,
	'membership_id'              => string, // may be ''
	'wp_user_id'                 => int,
	'last_synced'                => string, // GMT datetime or ''
)
```

**Default**: `null`

## `msebaa_get_member_field( string $key ): string`

Returns a string field from the current member snapshot. Allowed keys: `owner_id`, `email`, `first_name`, `last_name`, `membership_id`, `last_synced`. Unknown keys return `''`.

**Default**: `''`

## `msebaa_get_login_url( string $redirect = '' ): string`

URL of the configured login page. If `$redirect` is a local URL, it is appended as a safe return argument (`msebaa_redirect`).

**Default**: `home_url( '/' )` if login page unset

## `msebaa_get_logout_url( string $redirect = '' ): string`

WordPress logout URL via `wp_logout_url()`, with optional local redirect.

**Default**: logout URL targeting home

## Non-goals

- No direct MemberSuite HTTP from themes
- No access to internal `Msebaa_*` client classes as public API
