# Locked out

Every way back in, from the gentlest to the last resort. Pick the one that matches what you see.

## Someone lost their phone or backup codes

An administrator can turn off two-step login for another person from their profile.

1. Log in as an administrator.
2. Go to Users and open the person's profile.
3. Scroll to Two-step login and select "Turn off two-step login for this user".
4. Tell them to log in with their password and set it up again.

They get an email that two-step login was turned off, with a link to set it up again. The button only shows while they have the app or email codes on. You can't use it on your own account.

## Reset someone from WP-CLI

If you have WP-CLI, this does the same as the profile button, without logging in.

1. Open a terminal in the site's folder.
2. Run the command with the person's user ID, login or email address:

```
wp happyaccess twostep reset sam@example.com
```

3. Tell them to log in with their password and set it up again.

The app, email codes, backup codes and the grace period all start over. The command doesn't email the person, and it's there while Two-step login is on. On multisite, add `--url=` with the site's address.

## You're locked out of two-step login yourself

When you're the only administrator and you can't get a code, pause two-step login in wp-config.php.

1. Open wp-config.php on your server, with your host's file manager or over SFTP.
2. Add this line above the line that says "That's all, stop editing!":

```
define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );
```

3. Log in with your password. No second step shows.
4. Go to Users > Profile and set up two-step login again.
5. Remove the line from wp-config.php.

While the line is there, nobody on the site gets a second step, and administrators see a warning about it in the dashboard. Don't leave it in.

## Two-step login is paused for your account

After 10 wrong codes in an hour, HappyAccess stops taking codes for that account for an hour, and emails the person. Each pause in the same day lasts twice as long as the one before, up to 16 hours.

Codes are only asked for after the right password, so whoever typed them may know the password. To get back in:

1. Go to the login page and select "Lost your password?".
2. Reset the password from the email.
3. Log in with the new password and your code.

Resetting the password ends the pause. Waiting it out works too.

## Email stopped working for an email code only role

If a role is set to Email code only and your site stops sending email, nobody in that role can get a code. Turn password login back on in wp-config.php.

1. Open wp-config.php on your server.
2. Add this line above the line that says "That's all, stop editing!":

```
define( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN', true );
```

3. Log in with your password.
4. Fix the site's email, or set the role back to "Password or email code" in Users > HappyAccess > Login and security.
5. Remove the line from wp-config.php.

While the line is there, every role can log in with a password.

## A support person you don't trust anymore

Use Emergency lock to end every pass at once.

1. Go to Users > HappyAccess.
2. Select Emergency lock in the header.
3. Select End all passes.

Everyone using a pass is logged out and their accounts are deleted. While any pass is current, the admin bar has an Emergency lock link too.

From WP-CLI, this ends every pass without asking:

```
wp happyaccess revoke --all --yes
```

To end only one pass, select Revoke on it in Users > HappyAccess > Temporary access. Then check the Activity tab for what the account changed.

## The reCAPTCHA keys are wrong

With wrong keys, the access code screen, the login link screen and the email code forms all say "The security check didn't pass. Reload the page and try again." The normal password login and the two-step screens don't use reCAPTCHA, so they keep working.

1. Log in at your normal login page with your password.
2. Go to Users > HappyAccess > Settings.
3. Switch off reCAPTCHA and select Save changes. Code and link logins work again right away.
4. To turn it back on, get reCAPTCHA v3 keys from Google for this site's domain, paste the site key and the secret key, and save.

If your role is Email code only, add the HAPPYACCESS_ALLOW_PASSWORD_LOGIN line from the [section above](#email-stopped-working-for-an-email-code-only-role) first. If you have WP-CLI and can't log in at all, this turns reCAPTCHA off:

```
wp option patch update happyaccess_settings security recaptcha_enabled false
```

## You changed the security keys in wp-config.php

HappyAccess uses your site's security keys to protect its codes. Authenticator secrets are encrypted with SECURE_AUTH_KEY and SECURE_AUTH_SALT. Access codes, login links and device IDs are hashed with AUTH_KEY and AUTH_SALT. New keys mean the old values can't be read.

Two-step login stays on, but four things change:

- **Authenticator app codes stop working:** people log in with an email code or a backup code, then set up the app again on their profile or in My Account. Their profile shows a warning until they do. The site's email address gets one message about it, at most once a week.
- **Current passes stop working:** select New link and code on each pass in Temporary access and send the new ones.
- **Codes and links already sent stop working:** people ask for a new login code.
- **New device alerts fire once:** every browser looks new at its next login.

WordPress also logs everyone out when the keys change.
