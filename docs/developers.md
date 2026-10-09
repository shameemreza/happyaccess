# Developers

WP-CLI commands, hooks, wp-config constants and multisite notes for HappyAccess 1.1.

## WP-CLI

The `wp happyaccess` commands manage temporary access passes. They're there while Temporary access is on, and while any pass or temporary account is left after it's switched off. `wp happyaccess twostep reset` is there while Two-step login is on.

On multisite, add `--url=<site address>` to pick the site.

### wp happyaccess grant

Creates a pass and prints the login link, the access code and a message to share. The link and the code show once, here, and can't be read again.

Passes made here are always Protected admin. Use the admin screen for Custom access or Full admin.

| Option | What it does |
|---|---|
| `--label=<label>` | Required. Who or what the access is for. Shown in the list and the Activity tab. |
| `--email=<email>` | Emails the link and the code to this address. |
| `--role=<role>` | Role of the temporary account. Default: `administrator`. |
| `--expires=<duration>` | How long it lasts, as whole days or hours, like `3d` or `12h`. From 1 hour to 30 days, and longer values are cut to 30 days. Default: the Default pass length setting, 3 days unless you changed it. |
| `--one-time` | Ends the link and the code after the first login. |
| `--allow-installs` | Lets the account install and update plugins and themes. Blocked by default. |
| `--redirect=<url>` | A URL on this site to open after login, like an admin screen. A URL on another site is dropped with a warning. |
| `--notify=<mode>` | Login alerts: `first` (default), `every` or `off`. |

Pass the global `--user` option so the pass has an owner, who gets the alerts and the account's posts when it ends. Without it, WP-CLI warns that no owner is set, and HappyAccess falls back to the first administrator account.

```
# Three days of administrator access.
wp happyaccess grant --label="Acme support" --expires=3d --user=1

# Single-use editor access, emailed to the agent.
wp happyaccess grant --label="Ticket 4521" --role=editor --one-time --email=agent@example.com --user=admin
```

### wp happyaccess list

Lists the passes that haven't ended yet, suspended and used ones included. The columns are id, label, role, status, expires (in site time) and logins.

| Option | What it does |
|---|---|
| `--format=<format>` | `table` (default), `json`, `csv`, `yaml` or `count`. |

```
wp happyaccess list
wp happyaccess list --format=json
```

### wp happyaccess extend

Moves the end of a pass forward. The new end is never more than 30 days from now.

| Option | What it does |
|---|---|
| `<id>` | Required. The pass ID, from `wp happyaccess list`. |
| `--by=<duration>` | Required. How much time to add, like `1d` or `12h`. |

```
wp happyaccess extend 4 --by=1d
```

### wp happyaccess revoke

Ends a pass now and removes its temporary account.

| Option | What it does |
|---|---|
| `<id>` | The pass ID, from `wp happyaccess list`. |
| `--all` | Ends every pass that hasn't ended yet. Use it in place of an ID. |
| `--yes` | Skips the question that `--all` asks. |

```
wp happyaccess revoke 4
wp happyaccess revoke --all
```

### wp happyaccess twostep reset

Turns off two-step login for one person. The app, email codes, backup codes and the grace period all start over, and the reset is written to the Activity tab. The command doesn't email the person.

| Option | What it does |
|---|---|
| `<user>` | Required. User ID, login or email address. |

```
wp happyaccess twostep reset 12
wp happyaccess twostep reset sam
wp happyaccess twostep reset sam@example.com
```

## Filters

### happyaccess_client_ip

Changes the IP address HappyAccess sees for a request. It's used everywhere an IP shows up: the activity log, rate limits, the pass IP allowlist and the alert emails.

- **`$ip` (string):** the IP address HappyAccess worked out, from REMOTE_ADDR or the header picked in Settings > Visitor IP comes from.

Return a valid IPv4 or IPv6 address. Anything else is ignored. Only read a proxy header your server can trust, because visitors can set most headers to anything.

```
add_filter( 'happyaccess_client_ip', function ( $ip ) {
	return isset( $_SERVER['HTTP_TRUE_CLIENT_IP'] ) ? $_SERVER['HTTP_TRUE_CLIENT_IP'] : $ip;
} );
```

### happyaccess_user_has_other_2fa

Tells HappyAccess that a person already has two-step login from another plugin. HappyAccess then skips its own step for them, keeps them off email code logins, and leaves them out of the two-step counts.

- **`$has` (bool):** whether one of the plugins HappyAccess checks (Two Factor, Wordfence Login Security, WP 2FA, Kadence Security) protects the account.
- **`$user` (WP_User):** the person.

Return true for a two-step plugin HappyAccess doesn't check.

### happyaccess_passwordless_allowed

Decides whether a person may log in with an email code or link. It runs last, after the other checks.

- **`$allowed` (bool):** true, or false when the person has two-step login from another plugin.
- **`$user` (WP_User):** the person.

Temporary support accounts, people who aren't members of the current site on multisite, and accounts marked as spam are refused before the filter runs, so it can't let them in.

### happyaccess_verify_captcha

Has the final word on a reCAPTCHA check. It only runs while reCAPTCHA is on.

- **`$passed` (bool):** whether Google's answer passed.
- **`$action` (string):** the screen: `code` (access code), `link` (login link), `pl_request` (asking for an email code) or `pl_verify` (entering an email code or opening its link).

### happyaccess_passwordless_send_cookie

Lets your code send the passwordless cookies itself, for hosts that manage response headers their own way. Return true when you sent the cookie, and HappyAccess skips `setcookie()`.

- **`$handled` (bool):** false.
- **`$name` (string):** `happyaccess_pl_request` or `happyaccess_pl_confirm`.
- **`$value` (string):** the value. An empty value with a past expiry removes the cookie.
- **`$options` (array):** `expires`, `path`, `domain`, `secure`, `httponly` and `samesite`.

### happyaccess_twostep_send_cookie

The same as the one above, for the `happyaccess_ts` cookie of the two-step step and the `happyaccess_dev` cookie of new device alerts. It takes the same arguments.

### happyaccess_cleanup_counts

Filters the counts that the hourly cleanup returns. Use it to log or report what the cleanup did.

- **`$counts` (array):** `grants` (passes that ran out and were ended), `grants_purged` (old pass records deleted), `logs` (log entries deleted), `attempts` (rate limit records deleted) and `challenges` (old login steps deleted).

## Actions

### happyaccess_grant_ended

Fires after a pass ends and its temporary account is removed.

- **`$grant` (array):** the pass as stored after it ended. It has keys like `id`, `label`, `recipient_email`, `role`, `level`, `created_by`, `expires_at`, `login_count`, `status` and `end_reason`.
- **`$reason` (string):** why it ended: `revoked` (from the admin screen), `expired`, `emergency_lock`, `revoked_cli`, `feature_disabled`, `plugin_deactivated`, `plugin_deleted` or `privacy_erased`.

```
add_action( 'happyaccess_grant_ended', function ( $grant, $reason ) {
	error_log( sprintf( 'Pass %d (%s) ended: %s', $grant['id'], $grant['label'], $reason ) );
}, 10, 2 );
```

### happyaccess_session_ended

Fires when HappyAccess logs out a temporary account on its next request after the pass ended or was suspended.

- **`$user_id` (int):** the temporary account.
- **`$reason` (string):** `revoked`, `suspended` or `expired`.

### WordPress hooks HappyAccess fires

HappyAccess fires the core `wp_login` action after a login with an access code, a login link, an email code or a two-step code, so code that listens for logins sees these too. A wrong password or code at the two-step "Confirm it's you" check fires `wp_login_failed`, so security plugins count it.

## wp-config constants

Add these above the line that says "That's all, stop editing!" in wp-config.php. Both are for getting back in, so remove them once you are.

| Constant | What it does |
|---|---|
| `HAPPYACCESS_DISABLE_TWOSTEP` | Set to true to pause two-step login for everyone. Administrators see a warning while it's on. |
| `HAPPYACCESS_ALLOW_PASSWORD_LOGIN` | Set to true to let every role log in with a password, including roles set to Email code only. |

```
define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );
define( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN', true );
```

[Locked out](locked-out.md) has the steps for each.

## REST API

HappyAccess registers its routes under the `happyaccess/v1` namespace, for its own screens only:

- **The admin screen:** passes, activity, settings and the two-step counts. Only people who can manage the site's options can use these routes, and temporary support accounts never can.
- **The email code forms:** asking for a code and entering it. They need a header the HappyAccess script sends.
- **The two-step setup on the profile and My Account:** for the logged-in person's own settings, after they confirm it's them.

These routes aren't a public API. They can change in any release without notice. For scripts, use WP-CLI or the hooks above.

## Multisite

HappyAccess works network activated or activated per site.

- **Each site on its own:** every site has its own passes, activity log, settings and database tables, and its own Users > HappyAccess screen for that site's administrators. There's no network admin screen.
- **New sites:** while HappyAccess is network active, a new site gets its tables when it's created.
- **Two-step login when network active:** the Two-step login switch, each role's choice, the grace period and the XML-RPC setting come from the main site, applied to each person's roles on the site they log in to. A login on one site can be good on others, so one site's rules can't be weaker than another's. The other sites show a note in place of their own settings.
- **Two-step login per site:** on a subdirectory network without network activation, sites share their logins, so the Login and security tab suggests network activation or the same roles on every site.
- **Super admins:** for two-step login they count as administrators under the main site's rules. They can always log in with a password, whatever Email code only says.
- **Email code logins:** only work for people who are members of the site.
- **Temporary accounts:** when a pass ends, its account is removed from the site, and deleted from the network if it belongs to no other site. A pass never gets network admin permissions.
- **Network deactivation:** ends every pass on every site.
