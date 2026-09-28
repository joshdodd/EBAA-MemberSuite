# MemberSuite EBAA

Guide for the people who install this plugin, publish members-only content, and build the theme around it.

Visitors sign in with their MemberSuite email and password. The plugin links that person to a WordPress user, and content marked **Members Only** is limited to people who currently receive member benefits. Password recovery sends MemberSuite’s own reset email.

| | |
| --- | --- |
| **Requires WordPress** | 6.0 or newer |
| **Tested up to** | 6.7 |
| **Requires PHP** | 8.1 |
| **License** | [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) |

## Setup

An administrator with the `manage_options` capability completes this once.

1. Activate the plugin.
2. Create a published page for sign-in and place this shortcode in the content:

   ```
   [msebaa_sso_login]
   ```

3. Open **Settings → MemberSuite EBAA** and save the connection:

   | Field | What to enter |
   | --- | --- |
   | **Association ID** | The association GUID from MemberSuite (typically 36 characters with hyphens). Required for password-reset emails. |
   | **Association Key** | The numeric partition key used in MemberSuite REST URLs. This is not the association GUID. |
   | **API user email** | Email of a dedicated MemberSuite API user. This is not a member’s sign-in. |
   | **API user password** | Password for that API user. Leave the field blank on later saves to keep the stored password. The form never shows the saved value. |
   | **Login page** | The published page that contains `[msebaa_sso_login]`. Theme login links and members-only “Sign in” links use this page. |
   | **HTTP timeout** | Seconds to wait on MemberSuite requests. Allowed range is 5–30. The default is 15. |

4. Optionally create a second page with `[msebaa_password_reset]` if you want a standalone reset form. The sign-in page already includes a **Forgot password?** panel, so a second page is only needed when the reset form should live on its own.

Sign-in needs the Association Key. Password reset also needs the Association ID, API user email, and API user password. Until those four values are saved, the reset form tells the visitor that reset is temporarily unavailable.

## Shortcodes

Anyone who can publish shortcodes can place these in post or page content.

### `[msebaa_sso_login]`

The member sign-in form: email, password, and a **Sign in** button. Styles load only on pages that render this shortcode.

| Attribute | Description |
| --- | --- |
| `redirect` | Optional local URL to open after a successful sign-in. Off-site addresses are ignored. |

```
[msebaa_sso_login]
[msebaa_sso_login redirect="https://example.org/members/"]
```

When the visitor is already signed in, the form is replaced with “You are already signed in.” A **Forgot password?** disclosure on the same embed reveals the reset form, whether or not the visitor is signed in.

If the theme sends someone here with `msebaa_redirect` (see `msebaa_get_login_url()`), that return address is used when the shortcode itself has no `redirect` attribute.

### `[msebaa_password_reset]`

A standalone email form that asks MemberSuite to send its password-reset email. It has no attributes. It works for signed-in and signed-out visitors, and it does not change the current WordPress session.

```
[msebaa_password_reset]
```

The form stays on the same page and shows one of these messages:

- **Please enter a valid email address.**
- **If an account exists for that email address, password reset instructions have been sent.** The same sentence is shown when the visitor has submitted too many requests, so the form does not reveal whether an account exists.
- **Password reset is temporarily unavailable. Please try again later.** Shown when the API account is not configured or MemberSuite cannot be reached.

## Members-only content

Editors mark individual posts, pages, and media files. There is no site-wide “lock everything” switch.

### Posts and pages

In the editor sidebar, open the **Members Only** box and check **Members Only**. Site administrators can still open the item. Save the post or page to store the choice.

| Who is viewing the single post or page | What they see |
| --- | --- |
| Signed-in member currently receiving benefits | The full content |
| A user who can `manage_options` | The full content |
| Everyone else, including a signed-in person whose benefits are unknown | The body is replaced with “This content is for logged-in members only.” and a **Sign in** link back to this item |

Archive, search, and feed listings are left as the theme renders them. Titles and excerpts can appear there. The restriction applies when the single post or page is opened.

After a successful sign-in, a member returns to that post or page. A person who still does not receive benefits stays on the same denial message.

Templates should print the body with `the_content()`. The plugin replaces restricted content on that filter. Printing `$post->post_content` directly skips the gate.

### Media

In the media modal, or on the attachment edit screen, check **Members Only**. The public file URL is rewritten to a download address on the site (`/?msebaa_download={id}`) instead of the raw uploads path.

| Who requests the file | What happens |
| --- | --- |
| Signed-in member currently receiving benefits, or a user who can `manage_options` | The file is served |
| Everyone else | They are sent to the login page with “This file is not accessible to non-members.” |

A member who signs in from that prompt is sent back to the download. The media library in wp-admin still shows the real file URL so editors can manage the library.

## Theme helpers

Themes read membership through these seven functions. They are the supported API. Call them after `init`, in any template. They do not print output, stop the request, or contact MemberSuite. When membership is unknown they return the default listed below.

Do not decide membership by checking the **Member** role. Administrators are not treated as members unless their own snapshot says they receive benefits. Use `current_user_can( 'manage_options' )` when a template needs an operational override.

### `msebaa_is_user_logged_in(): bool`

True when any WordPress session is active, whether or not it is linked to MemberSuite. Default: `false`.

```php
if ( msebaa_is_user_logged_in() ) {
	echo '<a href="' . esc_url( msebaa_get_logout_url() ) . '">' . esc_html__( 'Sign out', 'your-theme' ) . '</a>';
}
```

### `msebaa_is_member(): bool`

True when the visitor is signed in **and** currently receiving member benefits. Default: `false`.

```php
if ( msebaa_is_member() ) {
	echo esc_html__( 'Member resources', 'your-theme' );
}
```

### `msebaa_receives_member_benefits(): bool`

The cached benefits flag for the current user. Default: `false` when signed out or when the flag is missing. This is the same check the content gate uses.

```php
$show_dues_notice = msebaa_is_user_logged_in() && ! msebaa_receives_member_benefits();
```

### `msebaa_get_current_member(): ?array`

The linked member snapshot, or `null` when nobody linked is signed in.

| Key | Type |
| --- | --- |
| `owner_id` | `string` — MemberSuite Individual ID |
| `email` | `string` |
| `first_name` | `string` |
| `last_name` | `string` |
| `receives_member_benefits` | `bool` |
| `membership_id` | `string` — may be empty |
| `wp_user_id` | `int` |
| `last_synced` | `string` — GMT datetime, or empty |

```php
$member = msebaa_get_current_member();

if ( null !== $member ) {
	echo esc_html( $member['first_name'] . ' ' . $member['last_name'] );
}
```

### `msebaa_get_member_field( string $key ): string`

One string from that snapshot. Allowed keys: `owner_id`, `email`, `first_name`, `last_name`, `membership_id`, `last_synced`. Any other key, a signed-out visitor, or a missing field returns `''`.

```php
$first_name = msebaa_get_member_field( 'first_name' );
```

### `msebaa_get_login_url( string $redirect = '' ): string`

URL of the login page chosen in settings. A local `$redirect` is appended as `msebaa_redirect` so sign-in can return there. Off-site redirects are dropped. Default: the home URL when the login page is unset or unpublished.

```php
$login_url = msebaa_get_login_url( get_permalink() );
```

### `msebaa_get_logout_url( string $redirect = '' ): string`

WordPress logout URL. An optional local `$redirect` is where the visitor lands after signing out. Anything off-site is ignored and logout returns home.

```php
$logout_url = msebaa_get_logout_url( home_url( '/' ) );
```

### Putting the helpers together

```php
<?php
if ( msebaa_is_member() ) {
	printf(
		'<p>%s</p>',
		esc_html(
			sprintf(
				/* translators: %s: member first name */
				__( 'Welcome back, %s.', 'your-theme' ),
				msebaa_get_member_field( 'first_name' )
			)
		)
	);
	printf(
		'<a href="%s">%s</a>',
		esc_url( msebaa_get_logout_url( home_url( '/' ) ) ),
		esc_html__( 'Sign out', 'your-theme' )
	);
} else {
	printf(
		'<a href="%s">%s</a>',
		esc_url( msebaa_get_login_url( get_permalink() ) ),
		esc_html__( 'Sign in', 'your-theme' )
	);
}
?>
```

## Styling the forms

The plugin ships a small layout stylesheet and leaves colors and type to the theme. Target these classes:

| Class | Element |
| --- | --- |
| `.msebaa-sso-form` | Sign-in wrapper |
| `.msebaa-sso-form--signed-in` | Wrapper when a session already exists |
| `.msebaa-sso-form__error` | Sign-in error |
| `.msebaa-sso-form__forgot` | **Forgot password?** disclosure |
| `.msebaa-password-reset` | Reset form |
| `.msebaa-members-only` | Denial message on a locked post or page |
| `.msebaa-media-denied` | Denial message after a locked file request |

## Sign-in behavior

- A linked member cannot sign in with a WordPress password at `wp-login.php`. They are told to use the member sign-in page. Accounts that are not linked to MemberSuite, including administrators, still use WordPress login.
- On first successful sign-in the plugin creates or reuses a WordPress user and stores the MemberSuite profile. A person who receives member benefits is given the **Member** role (the same capabilities as Subscriber). Privileged accounts, including administrators, are not switched to that role.
- A failed sign-in stays on the form with a generic message. MemberSuite error text is not shown.
- If MemberSuite cannot be reached, sign-in fails and the form says the membership service is temporarily unavailable. People who are already signed in keep browsing. Helpers and members-only checks use the last synced snapshot and do not call MemberSuite while the page renders.

## Changelog

### 0.1.0

- Outside SSO sign-in shortcode with user provisioning and benefits-based role mapping.
- Theme helper functions for membership state.
- Members Only gating for posts, pages, and media.
- Password-reset shortcode and inline reset panel on the sign-in form.
