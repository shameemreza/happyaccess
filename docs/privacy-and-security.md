# Privacy and security

HappyAccess keeps everything on your site. With reCAPTCHA off, which is the default, it sends nothing anywhere.

## What it stores

HappyAccess keeps its data in four database tables (`happyaccess_tokens`, `happyaccess_logs`, `happyaccess_attempts` and `happyaccess_challenges`, with your table prefix), a few options and some user meta.

- **The activity log:** what temporary users did, logins, setting changes and other HappyAccess events, with the IP address and browser name of each. It stores titles and names only, never post content, setting values or passwords. Entries are deleted after the number of days in Settings > Keep activity for, 30 by default.
- **Passes:** the name and email address you enter, the IP allowlist, and who created the pass. They're kept until the access ends, plus the same number of days, then deleted.
- **Rate limit records and login steps:** short-lived records of tries and logins in progress. They're deleted after a day.
- **Two-step login:** each person's settings, their authenticator secret, which is encrypted, and their backup codes, stored as hashes.
- **New device alerts:** for each watched person, up to 20 browsers they logged in from, as hashes of random IDs, with the last time each was seen.

To store shorter IP addresses, tick Shorten IP addresses in the log in Settings. To stop the log, untick Keep a log. That also stops the record of what support people change.

## The WordPress privacy tools

- **Export Personal Data and Erase Personal Data** in Tools include HappyAccess data. Logs are matched by user and by the email address on a pass, so a support person without an account on your site is covered too.
- **Erasing an email address** ends any pass sent to it, removes the IP addresses and browser names from its log entries, and clears the name, email address and allowlist from its pass records.
- **Erasing a user** removes their list of known browsers. Their two-step login settings stay, because removing them would switch off their account's protection without their say.
- **Suggested text for your privacy policy** is in Settings > Privacy, under HappyAccess.

If you give admin access to people outside your business, your privacy policy should say so.

## Cookies

HappyAccess sets these cookies only during the login steps that need them. All of them are HttpOnly, and secure on HTTPS sites.

| Cookie | What it's for | How long it lasts |
|---|---|---|
| `happyaccess_pl_request` | Ties a passwordless code to the browser that asked for it. | The code lifetime, 30 minutes at most. |
| `happyaccess_pl_confirm` | Protects the confirm screen of a passwordless login link. | 10 minutes. |
| `happyaccess_ts` | Holds a login between the password and the two-step code. | 10 minutes. |
| `happyaccess_dev` | A random ID that tells the site it has seen this browser before, for new device alerts. | 1 year. |

## Outside services

Google reCAPTCHA v3 is the only outside service, and it's off until you turn it on with your own keys.

When it's on, the access code screen, the login link screen and the email code forms load Google's script. Each time someone submits one of them, Google gets the visitor's IP address, browser details and how they used the page. HappyAccess sends the answer to `https://www.google.com/recaptcha/api/siteverify` to check it.

To turn it on:

1. Get reCAPTCHA v3 keys from Google for your site's domain.
2. Go to Users > HappyAccess > Settings and switch on reCAPTCHA.
3. Paste the site key and the secret key, and select Save changes.

HappyAccess checks the secret key with Google when you save, and refuses one Google doesn't know. The secret key is stored apart from the other settings and never shown again.

A few things to know:

- **The two-step screens never use it.** A problem with Google can't lock out someone who already entered the right password.
- **When Google can't be reached, those screens refuse the login.** A bot could otherwise slip in during an outage. The Activity tab notes it once an hour.
- **Google's policies:** [terms](https://policies.google.com/terms) and [privacy](https://policies.google.com/privacy).

If the keys are wrong, [Locked out](locked-out.md#the-recaptcha-keys-are-wrong) has the fix.

## How codes are stored

HappyAccess never stores a code or a link in a form someone could use.

- **Access codes, login links and email codes:** stored as HMAC-SHA256 hashes, keyed with your site's AUTH_KEY and AUTH_SALT plus a random key HappyAccess makes for your site. They're checked in constant time.
- **Backup codes:** stored with WordPress's own password hashing.
- **Authenticator secrets:** encrypted with libsodium, or AES-256-GCM through OpenSSL when libsodium isn't there. The key comes from your site's SECURE_AUTH_KEY and SECURE_AUTH_SALT plus HappyAccess's own key.

The link and the code of a pass show once, when you create it. After that, only New link and code gets you a working pair.

The codes themselves:

- **Access codes:** 8 random digits.
- **Login links:** a 256-bit random key.
- **Email codes:** 6 random digits, for passwordless login and two-step login.
- **Backup codes:** 10 characters from a 32-character alphabet that leaves out 0, 1, I and O.

Changing your security keys in wp-config.php makes stored codes and secrets unreadable. See [what to do after you change them](locked-out.md#you-changed-the-security-keys-in-wp-configphp).

## Rate limits

Wrong codes are limited per IP address, per account and across the whole site.

- **Per IP address:** every code screen follows Settings > Wrong codes before a pause. The default is 5 tries, then a 30-minute pause.
- **Per code:** an email code is cancelled after 5 wrong tries.
- **Per account, for two-step login:** after 10 wrong codes in an hour, the account takes no codes for an hour. Each pause in the same day lasts twice as long, up to 16 hours, and the person gets an email. Resetting the password ends it.
- **Email code requests:** 3 per account every 15 minutes, and 10 per IP address an hour.
- **Across the site:** after 30 access code tries in an hour, the access code screen pauses for an hour. After 100 wrong email codes in an hour, email code logins pause for an hour. After 100 wrong two-step codes in an hour, HappyAccess only sends a warning, so one account can't pause two-step login for everyone. Each of these emails the site's email address.

HappyAccess codes don't count as wrong passwords, so a brute force plugin won't lock anyone out over them.

## Other protections

- **Login links open a confirm screen first.** Only the button on it logs anyone in, so a link scanner in Outlook, Gmail or Slack can't use the link up.
- **The passwordless screens give the same answer** for an email with an account and one without.
- **Temporary accounts are limited by level,** can't touch your account, and can't change or switch off HappyAccess. See [access levels](temporary-access.md#access-levels).
- **The admin routes** only let in people who can manage the site's options, and never a temporary account.

## Deleting HappyAccess

Deleting the plugin from the Plugins screen always ends every pass and removes its scheduled events. The tables, settings and user meta go too when "Delete all HappyAccess data when the plugin is deleted" is ticked in Settings. It's off by default, so your data is still there if you install HappyAccess again.

Deactivating it ends every pass and keeps everything else.

## Report a security issue

Please report it privately through the [security policy](../SECURITY.md), not in a public issue or the support forum. On GitHub, open the [Security tab](https://github.com/shameemreza/happyaccess/security) and select "Report a vulnerability".
