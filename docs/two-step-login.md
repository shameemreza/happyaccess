# Two-step login

Two-step login, also called two-factor authentication or 2FA, asks for a second code after the password. A stolen password alone isn't enough to get in.

## Turn it on

1. Go to Users > HappyAccess > Settings.
2. Switch on Two-step login. It saves right away.
3. Open the Login and security tab to set each role and the grace period.

If another two-step plugin is active, HappyAccess names it and asks before it turns on. See [other two-step plugins](#other-two-step-plugins).

On a multisite network where HappyAccess is network active, every site follows the main site's two-step settings. The other sites show a note in place of the switch. See [multisite](developers.md#multisite).

## The methods

People can use any of these:

- **Authenticator app:** works with any app that supports standard time-based codes (TOTP), like Google Authenticator, Microsoft Authenticator, Authy, 1Password and Bitwarden. Setup shows a QR code, drawn on your own site, and a setup key to type in by hand.
- **Email codes:** a 6-digit code sent to the email address on the account. It works for 10 minutes and allows 5 tries.
- **Backup codes:** 10 codes that each work once, for when the phone or the inbox isn't at hand.

Backup codes come with the first method someone turns on. They're shown once, with Copy and Download as text buttons. When a login with a backup code leaves someone with two or fewer, HappyAccess shows a notice after login that it's time to make new ones.

![The WordPress login screen asking for the code from an authenticator app after the password](../.github/assets/screenshots/two-step-code.png)

At login, the two-step screen asks for the first method the person has. Links under the form switch to the app, an email code or a backup code. A login has 10 minutes to finish this step.

## Off, optional or required per role

Under "How each role uses two-step login", pick one choice for each role:

- **Off:** the role isn't offered two-step login. Anyone who set it up before keeps it until they turn it off.
- **Optional:** people can set it up on their profile or in My Account. This is the default for every role.
- **Required:** people without a method get the setup screen at their next login.

When a person has several roles, the strictest one wins. For example, Required for Administrator and Shop manager, and Optional for everyone else.

WooCommerce logs a new account in as soon as it's made, at sign-up on My Account or at checkout, without the login form. So for a customer in a required role, the setup screen and the grace period start at their next login.

## The grace period

Required roles get a grace period to set it up. Pick 3 logins (the default), 5 logins, 7 days or 14 days.

The grace period starts at the first login that reaches the setup screen. Until it ends, the setup screen has a Later link, and it says how many skips are left or until when. After it ends, the login only finishes once setup is done.

## Set it up

Each person sets up their own two-step login. Changing it asks them to confirm it's them first, with their password or a code. That lasts 15 minutes.

### On the WordPress profile

1. Go to Users > Profile and scroll to Two-step login.
2. Next to Authenticator app, select Set up. Confirm it's you.
3. Scan the QR code with the app, type the code from the app, and select Turn on two-step login.
4. Save the backup codes that show next.

To use email codes instead, select Turn on next to Email codes, then type the code from the email and select Turn on email codes.

### In WooCommerce My Account

![The Two-step login page in WooCommerce My Account, with a QR code to scan, a setup key, a field for the code from the app, and options for email codes and backup codes](../.github/assets/screenshots/woocommerce-two-step-setup.png)

With WooCommerce active, My Account gets a Two-step login page, after Account details in the menu. It has the same steps as the profile. Customers, who WooCommerce keeps out of wp-admin, set it up here.

### At login, for required roles

Someone in a required role with no method gets a "Set up two-step login" screen after their password. They pick the app or email codes, finish the setup, save their backup codes, and select Continue.

### Backup codes

Select Make new codes next to Backup codes to get a new set of 10. The old ones stop working.

## Turn it off for one person

An administrator can turn off two-step login for someone who lost their phone or their backup codes.

1. Go to Users, open their profile, and scroll to Two-step login.
2. Select "Turn off two-step login for this user".

They get an email, and they can set it up again from their profile or My Account. [Locked out](locked-out.md) has every other way back in.

## Which logins get the second step

Every login through WordPress's own sign-in function, `wp_signon()`, gets the second step. That covers the WordPress login page, WooCommerce's login form and any other login form built on it. In a browser request, code that only checks a password with `wp_authenticate()`, for example before a sensitive change, is left alone. If that code then logs the person in with `wp_set_auth_cookie()`, HappyAccess holds the login back and sends them to the second step.

A login form that checks the password itself and then sets the login cookie skips the step. Its developer can send it to the step with the [`happyaccess_twostep_login_request`](developers.md#happyaccess_twostep_login_request) filter.

## XML-RPC

"Block XML-RPC login for accounts with two-step login" is on by default. XML-RPC can't show a second step, so a password alone would get in that way. With it on, those accounts are told to use an application password instead.

Application passwords skip the second step, since someone made them on purpose for an app or a script. Logins through AJAX, the REST API or WP-CLI with a password are refused for accounts with two-step login.

## New device alerts

When an account logs in from a browser it hasn't used before, the person gets an email with the time, the browser, the system and the IP address, and a link to change their password if it wasn't them.

1. Go to Users > HappyAccess > Login and security.
2. Under New device alerts, tick the roles to watch. Administrator is ticked by default.
3. Select Save changes.

A few details:

- **Two-step login must be on:** alerts are part of it and stop while it's off.
- **The first login is quiet:** the first login after alerts start for someone only remembers the browser.
- **A limit on emails:** at most 3 alerts an hour per person.
- **20 browsers each:** HappyAccess remembers up to 20 browsers per person and forgets the one seen longest ago.
- **New keys:** after new security keys in wp-config.php or a lost HappyAccess site key, every browser looks new at its next login, so each person gets an alert for it, at most 3 an hour.
- **Other two-step plugins:** with Two Factor or Kadence Security, the alert goes out once their step passes.

A browser is known by a cookie with a random ID. Clearing cookies, or a browser that blocks them, makes the next login look new. See [cookies](privacy-and-security.md#cookies).

## Who has it

The "Who has two-step login" panel on the Login and security tab counts the people with the app or email codes on, by role. For required roles it also shows how many are still in their grace period and how many can't skip anymore. The counts update every 5 minutes.

## Other two-step plugins

HappyAccess checks for Two Factor, Wordfence Login Security, WP 2FA and Kadence Security. When one of them already protects an account, HappyAccess skips that account, so nobody is asked twice:

- **No HappyAccess step:** the other plugin's check runs as usual.
- **No email code login:** those accounts keep their password and the other plugin's check.
- **Not counted:** they're left out of the "Who has two-step login" counts.

For a two-step plugin HappyAccess doesn't check, a developer can use the `happyaccess_user_has_other_2fa` filter. See [Developers](developers.md#filters).

Temporary support accounts never get a two-step step. Their access is already limited to their own link or code.
