# HappyAccess

Give support a login that ends by itself, let people log in with an email code, and add two-step login. No passwords shared.

HappyAccess is a free WordPress plugin. There's no paid version. Install it from [WordPress.org](https://wordpress.org/plugins/happyaccess/).

## Why I made it

I'm Shameem Reza, and I've spent years helping people fix their WordPress sites. Getting into the site was always the messy part. Someone makes an admin account, sends the password in an email, and promises to delete the account later. Most people forget, and the account stays.

HappyAccess started as my fix for that. You give support a link or a code instead of a password. The access ends when the time is up, and the account goes with it.

In 1.1.0 I rebuilt it from scratch and added fixes for two login problems I kept running into: people who forget passwords, and accounts that need more than a password. Each one is a switch in Settings. A feature you leave off loads no code.

HappyAccess is my own, independent project.

## What it does

### Temporary access

- **Passes that end by themselves:** pick 1 day, 3 days, 7 days or your own range up to 30 days, then send the link or the code.
- **A confirm screen for links:** email and chat link scanners open the link without using it up.
- **Three access levels:** Protected admin (the default), Custom access where you tick each permission, or Full admin with an alert on every login.
- **Activity:** see the posts, orders, settings, plugins, themes and users they changed, and export it as CSV.
- **Control:** suspend, extend or end a pass at any time. Emergency lock in the admin bar ends every pass at once.
- **WP-CLI:** `wp happyaccess grant`, `list`, `extend` and `revoke`.

### Passwordless login

- **Email codes and links:** people ask for a login code by email and log in with it, or with the link in the same email.
- **Where it shows up:** the WordPress login page, WooCommerce My Account, the classic WooCommerce checkout, the HappyAccess Login block and the `[happyaccess_login]` shortcode.
- **No account lookups:** the screen answers the same way for an email that has an account and one that doesn't.
- **Per role:** keep "Password or email code", or switch a role to "Email code only".

### Two-step login

- **After the password:** a code from an authenticator app, an email code or a backup code.
- **Optional or required per role:** required roles get a short grace period, then setup is part of their next login.
- **WooCommerce customers:** setup and the second step work in My Account too.
- **Lockout help:** an admin can turn it off on the user's profile, or you can run `wp happyaccess twostep reset <user>`.
- **No double prompts:** accounts already protected by Two Factor, Wordfence Login Security, WP 2FA or Kadence Security are skipped.

New device alerts come with it. When an administrator logs in from a browser their account hasn't used before, they get an email with the time, the browser and the IP address.

## Screenshots

The screenshots are on the [WordPress.org plugin page](https://wordpress.org/plugins/happyaccess/) for now.

## Install

From your site:

1. Go to Plugins > Add New and search for HappyAccess.
2. Install and activate it.
3. Go to Users > HappyAccess. Temporary access is on. Turn on Passwordless login or Two-step login in Settings if you want them.

HappyAccess needs WordPress 6.7 or later and PHP 7.4 or later.

To run it from this repo, clone it into `wp-content/plugins/happyaccess` and build the admin screen first. The `build` folder isn't in git.

```
npm ci
npm run build
```

## Security first

HappyAccess hands out logins, so I built every part of it expecting someone to try to break in.

### How codes and keys are stored

- **Hashed, never plain:** access codes, email codes and login link keys are stored as HMAC-SHA256 hashes, keyed with a per-site secret. The plain code is shown once, in the response that creates or regenerates it, and that response is marked `no-store`.
- **Backup codes:** stored as WordPress password hashes.
- **Authenticator secrets:** encrypted with libsodium, or AES-256-GCM when sodium isn't available. The key is built from your site's security keys and a secret HappyAccess keeps.
- **One use:** a login code or link is marked used in a single database update, and the login only goes ahead when that update changed a row. Two tabs can't both use the same code.
- **Links wait for a click:** opening a login link shows a confirm screen. Only the button on that screen logs you in.

### Rate limits

Every try counts against the rate limits before the code is checked.

- **Per IP address:** 5 tries in 15 minutes lock that address out for 30 minutes. A successful login clears the count, and you can change the tries and the lockout in Settings.
- **Per site, for access codes:** by default, 30 access code tries in an hour, from anywhere, pause the access code screen for an hour.
- **Per login, for two-step:** 5 wrong codes cancel that login, and the person starts again from the password.
- **Per account, for two-step:** 10 wrong codes in an hour, from any IP, pause that account's code step for an hour. Each pause in the last 24 hours doubles the next one, up to 16 hours, and the user gets an email.

### Protected admin

Protected admin is the default level. It's an administrator who can do the support work but can't take over the site.

- **No new admins:** a temporary user can't create, promote, delete or remove users.
- **No new code:** no installing, updating or deleting plugins and themes, no core updates, and no file editor.
- **Hands off the owner:** they can't see or edit the account that created the pass, and they can only edit accounts with no more than the `read` capability, like customers and subscribers.
- **No password or email changes:** for any account, their own included.
- **Site settings stay put:** the admin email, site address, default role and registration settings are locked.

A few rules hold on every level, Full admin included. HappyAccess can't be deactivated or deleted, its settings can't be changed, application passwords stay blocked, and the plugin and theme file editors stay off. Temporary users can't log in with a password either, only with their link or code.

Found a security issue? Please report it privately. The [security policy](SECURITY.md) explains how.

## Development

You need PHP 7.4 or later, Composer, Node 22 (see `.nvmrc`) and MySQL or MariaDB.

```
composer install
npm ci
npm run build
```

The PHPUnit suite needs WordPress and an empty test database. [CONTRIBUTING.md](CONTRIBUTING.md) has the full setup, the checks to run and the rules for pull requests.

## Links

- [HappyAccess on WordPress.org](https://wordpress.org/plugins/happyaccess/).
- [Support forum](https://wordpress.org/support/plugin/happyaccess/).
- [Security policy](SECURITY.md).
- [Contributing](CONTRIBUTING.md).
- [Changelog](CHANGELOG.md).

## Credits and license

The QR code on the two-step setup screen is drawn by [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) by Kazuhiko Arase, under the MIT license.

HappyAccess is licensed under the GPL, version 2 or later.
