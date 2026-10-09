=== HappyAccess - Temporary Login, Passwordless Login and 2FA ===
Contributors: shameemreza
Tags: temporary login, passwordless login, two factor authentication, magic link, 2fa
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Temporary admin access without sharing a password, login by email code or link, and two-step login with an authenticator app. Free.

== Description ==

HappyAccess fixes the three login problems most WordPress and WooCommerce sites run into, in one light plugin:

* **Temporary login:** give a support person, developer or agency access to your site with a link or a code that ends by itself. No password shared, no account left behind.
* **Passwordless login:** let customers and users log in with a 6-digit code or a login link sent to their email. It works on the WordPress login page, WooCommerce My Account and checkout.
* **Two-step login (2FA):** ask for a code from an authenticator app, an email code or a backup code after the password, and require it for the roles you pick.

Two-step login also brings **new device alerts**: your administrators get an email when their account logs in from a browser it hasn't seen before.

Each feature is a switch in Settings. Turn on only what you need. Anything you leave off adds no code to your pages.

https://www.youtube.com/watch?v=VtsEFprDkdM

= How to give temporary admin access without sharing a password =

Someone from support needs to look at your site. The usual way is to make them an admin account, send a password by email, and promise to delete the account later. Most people forget, and the account stays forever.

With HappyAccess, you create a pass in Users > HappyAccess and send them the link or the 8-digit code. They log in with it. When the time you picked is up, their sessions end and their account is removed by itself.

* **You pick how long:** 1 day, 3 days, 7 days or your own range, up to 30 days.
* **You pick what they can do:**
    * **Protected admin** (the default): a full admin who can't install plugins, create other admins or touch your account.
    * **Custom access:** tick exactly the permissions they need.
    * **Full admin:** no limits, with an email alert on every login and a warning if they create another admin.
* **Safe links:** the login link opens a confirm screen first, so email and chat link scanners can't use it up.
* **Your choice of limits:** one-time passes, an IP address allowlist, and an email when they log in.
* **See what they changed:** the Activity tab lists the posts, pages, products, settings, plugins, themes and users they changed, and order status changes, with times and IP addresses. Export it as CSV.
* **Stay in control:** suspend, extend or end a pass at any time. The Emergency lock in the admin bar ends every pass at once.
* **Your account stays private:** their account can't see or edit yours, and HappyAccess is hidden from their plugin list.
* **Their work stays:** posts they wrote move to your account when the access ends. Nothing they made is deleted.
* **For agencies and hosts:** WP-CLI commands to create, list, extend and end passes from the terminal.

= How to log in to WordPress without a password =

Forgotten passwords are the most common login problem on any site with customers. Passwordless login lets people skip the password and log in with an email code or a magic login link instead.

1. They select "Email me a login code" on the login form and type their email or username.
2. They get one email with a 6-digit code and a login link. Both work for 10 minutes by default (5 to 30, your choice).
3. They type the code, or open the link on any device, and they're in.

Where the option shows:

* The WordPress login page (wp-login.php).
* WooCommerce My Account and the classic WooCommerce checkout, inline with no page reload.
* Any page, with the HappyAccess Login block or the `[happyaccess_login]` shortcode.

Built to be safe:

* The screen gives the same answer for an email that has an account and one that doesn't, so nobody can use it to find out who has an account.
* A code only works in the browser that asked for it, with 5 tries before it's cancelled.
* Login links can only be used once, and only from the confirm screen.
* Per role, keep "Password or email code" or switch a role to "Email code only".

= How to add two-factor authentication to WordPress and WooCommerce =

Two-step login (also called two-factor authentication or 2FA) asks for a second code after the password. A stolen password alone isn't enough to get in.

* **Authenticator app:** works with any app that supports standard time-based codes (TOTP), like Google Authenticator, Microsoft Authenticator, Authy, 1Password and Bitwarden. Setup shows a QR code, drawn on your own site.
* **Email codes:** a 6-digit code sent to the account's email.
* **Backup codes:** 10 one-time codes for when the phone isn't around.
* **Optional or required per role:** for example required for administrators and shop managers, optional for customers.
* **A grace period** for required roles: a few logins or days to set it up, then it becomes part of their next login.
* **Setup where people already are:** on their WordPress profile, or in WooCommerce My Account for customers.
* **Never locked out:** an admin can turn it off from a user's profile, you can run `wp happyaccess twostep reset <user>`, or add one line to wp-config.php.
* **Plays nicely with others:** if Two Factor, Wordfence Login Security, WP 2FA or Kadence Security already protects an account, HappyAccess skips that account, so nobody is asked twice.

= New device login alerts =

When an administrator's account logs in from a browser it hasn't used before, they get an email with the time, the browser, the system and the IP address, and a link to change their password if it wasn't them. You can turn alerts on for other roles in Login and security. New device alerts are part of two-step login, so they run while two-step login is on.

= Light, private and secure =

* **No outside services by default.** Optional Google reCAPTCHA v3 is the only one, and it's off until you add your own keys.
* **Hashed and encrypted:** access codes, login links and email codes are stored as hashes, never in plain text. Authenticator secrets are encrypted.
* **Rate limits:** wrong codes are limited per IP address and per account, and the owner gets an email if a site sees a lot of wrong codes.
* **Compatible:** works with hidden login URL plugins like WPS Hide Login, with page cache plugins, and with security plugins.
* **Multisite:** network activated or per site.
* **Accessible:** the admin screens follow your admin color scheme and are checked with automated accessibility tests.
* **Light:** the login form script needs no jQuery and loads only on pages that show a HappyAccess form.

= For support teams, agencies and developers =

If you support WordPress or WooCommerce sites, send this to a client when you need access. Copy it, change the names, and paste it into your ticket or email.

> Hi! To look into this, I need to log in to your site. You don't have to send me a password or make me an account.
>
> 1. Install the free HappyAccess plugin from your Plugins screen.
> 2. Go to Users > HappyAccess and create a pass. Three days is plenty.
> 3. Send me the link it shows you.
>
> My access ends by itself when the time is up, and you can end it earlier from the same screen. You'll also see what I changed while I was in.

== Installation ==

1. In your dashboard, go to Plugins > Add New Plugin and search for "HappyAccess".
2. Install and activate it.
3. Go to Users > HappyAccess. Temporary access is on from the start.
4. To add passwordless login or two-step login, turn them on in the Settings tab, then set them up in Login and security.

== Frequently Asked Questions ==

= How do I give someone temporary admin access to my WordPress site? =

Go to Users > HappyAccess, type who it's for, pick how long it lasts and what they can do, and create the pass. Send them the login link or the 8-digit code. Their access ends by itself at the time you picked.

= Can the support person see my password? =

No. They log in with their own link or code, and their account is separate from yours. They never see or need your password.

= What happens when temporary access ends? =

Their sessions end, they're logged out, and their temporary account is removed. Anything they wrote moves to your account and is never deleted. The activity log stays for as long as you keep logs (30 days by default).

= Can I end temporary access early? =

Yes. End or suspend any pass from the Temporary access tab. The Emergency lock button in the admin bar ends every pass at once.

= Does a support pass work on a copy of my site? =

No. A pass logs them in to your live site, and what they change is real. The access level sets what they can do, and the Activity tab shows what they did.

Your other plugins run as usual while they work. On Protected admin and Custom access passes, if a plugin tries to change role permissions on a page they load, outside a plugin activation or update, HappyAccess refuses the change and logs it as "Role change blocked".

= Is a login link the same as a magic link? =

Yes. A login link (often called a magic link) logs someone in without a password. HappyAccess login links open a confirm screen first and only work once, so a link scanner in Outlook, Gmail or Slack can't use them up before the person does.

= How does passwordless login work with WooCommerce? =

Turn on Passwordless login, and customers see "Email me a login code instead" under the login form on My Account and on the classic checkout. They type their email, get a 6-digit code, and log in without leaving the page. After logging in, they land back on My Account or the checkout.

= Can I add the email code option to the WooCommerce block checkout? =

The block checkout doesn't let plugins add to its sign-in prompt yet. Add the HappyAccess Login block to your checkout page instead. The classic checkout and My Account get the option on their own.

= Why does the login shortcode show as plain text on my page? =

Passwordless login is off. The `[happyaccess_login]` shortcode works only while it's on. Turn it back on in Settings, or take the shortcode off your pages. The HappyAccess Login block shows nothing while Passwordless login is off.

= Which authenticator apps work with two-step login? =

Any app that supports standard time-based one-time codes (TOTP, RFC 6238). That includes Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden and most password managers.

= Can I require two-factor authentication for administrators only? =

Yes. In Login and security, set each role to Off, Optional or Required. For example, Required for Administrator and Shop manager, and Optional for everyone else. Required roles get a grace period to set it up.

= Do customers who sign up at checkout have to set up two-step login right away? =

No. WooCommerce logs a new account in as soon as it's made, at sign-up on My Account or at checkout, without the login form. If the Customer role is Required, the setup screen and the grace period start at their next login.

= Does two-step login work with custom login forms? =

Yes, when the form logs people in through WordPress's own sign-in function, `wp_signon()`. That covers the WordPress login page, WooCommerce and any other login form built on it. A form that skips it can opt in with the `happyaccess_twostep_login_request` filter.

= Someone is locked out of two-step login. What do I do? =

Pick whichever fits:

* As an admin, open their profile and select "Turn off two-step login for this user".
* Run `wp happyaccess twostep reset <user>`.
* If you're locked out yourself, add `define( 'HAPPYACCESS_DISABLE_TWOSTEP', true );` to wp-config.php, log in, set it up again, then remove the line.

= Can I make a role log in only with an email code? =

Yes, in Login and security. A password login for that role gets the same message as a wrong password, so the message never shows which accounts use email codes. If email stops working on your site, add `define( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN', true );` to wp-config.php to get back in with a password.

= I changed the security keys in wp-config.php. Why do people need to set up their app again? =

Authenticator secrets are encrypted with your site's keys. New keys mean the old secrets can't be read, so app codes stop working. Two-step login stays on, and people can still log in with an email code or a backup code, then set up the app again from their profile.

New keys also stop current support passes. WordPress logs the support person out, and the pass's link and code stop working. Select New link and code on a pass to send working ones. Login codes and links already emailed stop working too, so people ask for a new one.

The same happens when the HappyAccess site key is lost, except WordPress doesn't log anyone out. The key is the `happyaccess_secret` option in the database, and a site move or a database cleanup plugin can leave it out. HappyAccess then makes a new key. Copy the whole options table when you move a site.

= Why did I get a new device alert from a browser I always use? =

HappyAccess knows a browser by a cookie with a random ID, and it stores that ID as a hash made with your site's keys. Clearing cookies makes the browser look new. So do new security keys in wp-config.php or a lost HappyAccess site key: every browser looks new at its next login, and each watched person gets an alert for it. Each person gets at most 3 alerts an hour, and after that login the browser is known again.

= Does it work with WPS Hide Login and other hidden login URL plugins? =

Yes. Every HappyAccess screen is a step of the normal WordPress login page, so a hidden login URL plugin moves it along with everything else.

= Does it work with Wordfence and other security plugins? =

Yes. HappyAccess codes don't count as wrong passwords, so a brute force lockout won't lock anyone out over them. If Wordfence Login Security, Two Factor, WP 2FA or Kadence Security already adds two-step login to an account, HappyAccess skips that account.

One thing to know: plugins that add their own checks to the password login, like user approval or country blocks, don't run on code and link logins.

= Does it work with page cache plugins? =

Yes. Every HappyAccess login screen tells caches not to store it.

= Does it work on WordPress multisite? =

Yes, network activated or per site. When it's network activated, two-step login follows the main site's settings on every site.

= Does HappyAccess slow down my site? =

No. Each feature loads only when it's on, and only on the pages that need it. Your other pages get no HappyAccess script or style.

= Is HappyAccess free? =

Yes. There's no paid version and no account to sign up for.

= Where do I report a security issue? =

Please report it privately through the security policy at https://github.com/shameemreza/happyaccess/security/policy, not in the support forum.

== Privacy ==

HappyAccess keeps everything on your site. With reCAPTCHA off, nothing is sent anywhere.

What it stores:

* The activity log: what temporary users did, with IP addresses and browser names. It's deleted after the number of days you set (30 by default).
* For temporary access: the name and email you enter, kept until the access ends, plus the same number of days.
* For two-step login: an encrypted authenticator secret, hashed backup codes and each user's settings.
* For new device alerts: a list of the browsers each watched user has logged in from (random IDs, stored as hashes) with the last time each was seen.

Cookies it sets, only during the login steps that need them:

* `happyaccess_pl_request`: ties a passwordless code to the browser that asked for it. 30 minutes at most.
* `happyaccess_pl_confirm`: protects the login link's confirm screen. 10 minutes.
* `happyaccess_ts`: holds a login between the password and the two-step code. 10 minutes.
* `happyaccess_dev`: a random ID that tells the site it has seen this browser before, for new device alerts. 1 year.

WordPress's Export Personal Data and Erase Personal Data tools include HappyAccess data. Suggested text for your privacy policy is in Settings > Privacy.

If you give admin access to people outside your business, your privacy policy should say so.

== External services ==

HappyAccess uses one outside service, and only when you turn it on.

**Google reCAPTCHA v3** checks that a person, not a script, is using the HappyAccess access code screen, the login link screen and the email code forms. When it's on, those screens load Google's script, and each time someone submits one, Google gets the visitor's IP address, browser details and how they used the page. Google's script hands back a token, and to check it HappyAccess sends that token, the visitor's IP address and your secret key to https://www.google.com/recaptcha/api/siteverify, once for each submit. When you save a new secret key, or turn reCAPTCHA on, HappyAccess also sends that secret key to the same address once, with no visitor data, to check that Google knows it. It's off by default, and it needs your own keys from Google. The two-step login screens don't use it, so a problem with Google never locks out someone who already entered their password.

* Terms: https://policies.google.com/terms
* Privacy: https://policies.google.com/privacy

== Screenshots ==

1. Temporary access: give access in under a minute, and see who has access with a live countdown.
2. The login link and 8-digit code to send, right after you create a pass.
3. Activity: what the support person changed while they were in.
4. What the support person sees: a confirm screen before the login link logs them in.
5. Login and security: passwordless and two-step settings with a live preview.
6. Passwordless login on WooCommerce My Account: "Email me a login code instead".
7. Setting up two-step login from WooCommerce My Account with an authenticator app.
8. The two-step code step after the password.

== Changelog ==

= 1.1.0 =
HappyAccess is rebuilt from the ground up in this release.

* New: Passwordless login with an email code or login link, on the login page, WooCommerce My Account and checkout, a block and a shortcode.
* New: Two-step login (2FA) with an authenticator app, email codes and backup codes, optional or required per role.
* New: New device login alerts for administrators, and any other role you pick.
* New: A new admin screen with a live pass preview, the Activity tab and the Login and security tab.
* New: Protected admin, Custom access and Full admin access levels.
* New: Login links open a confirm screen, so link scanners can't use them up.
* New: The Activity tab shows what temporary users changed, with CSV export.
* New: WP-CLI commands for passes and two-step resets.
* New: Multisite support, network activated or per site.
* Security: codes and keys are stored as hashes, with rate limits per IP address and per account.
* Security: the access code is now 8 digits.
* Changed: temporary users' posts move to your account when access ends. They're never deleted.
* Changed: HappyAccess needs WordPress 6.7 or later. 1.0.6 needed 6.0.

**Upgrading from 1.0.x:**

* Your active access codes keep working on the new code screen, for up to 7 days from the upgrade.
* Login links and share links made with 1.0.x stop working at the upgrade. Send the access code instead, or make a new pass.
* There's no way back to 1.0.6 after upgrading, because the database changes.

= 1.0.6 =
* Fixed: code login on hosts with mod_security that strip extra fields from wp-login.php.

= 1.0.5 =
* New: admin menu restrictions, hide the admin bar, protection for the site owner's account and suspend or reactivate access.

== Upgrade Notice ==

= 1.1.0 =
A full rebuild with passwordless login, two-step login and new device alerts. Needs WordPress 6.7 or later. Active access codes keep working for up to 7 days, but 1.0.6 login links and share links stop. You can't go back to 1.0.6 after upgrading.

== Credits ==

The QR code on the two-step setup screen is drawn by qrcode-generator by Kazuhiko Arase, under the MIT license.
