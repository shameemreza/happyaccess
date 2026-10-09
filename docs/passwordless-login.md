# Passwordless login

Passwordless login lets people skip the password. They ask for a code, get one email with a 6-digit code and a login link, and use either one to log in.

## Turn it on

1. Go to Users > HappyAccess > Settings.
2. Switch on Passwordless login. It saves right away.
3. Open the Login and security tab to pick where the option shows and how each role logs in.

![The Login and security tab with passwordless settings for code lifetime, button style, where the email code option shows and how each role logs in, next to a live preview of the login form](../.github/assets/screenshots/login-and-security.png)

The settings on this tab wait for Save changes. The preview next to them follows your changes before you save.

## How people log in

1. They select "Email me a login code" and type their email address or username.
2. They get one email with a 6-digit code and a login link.
3. They type the code in the same browser, or open the link on any device and select Log in on the confirm screen.

A few rules keep this safe:

- **No account lookups:** the screen gives the same answer for an email with an account and one without, so nobody can use it to find out who has an account.
- **One browser:** a code only works in the browser that asked for it. After 5 wrong tries the code is cancelled and they ask for a new one.
- **One use:** the login link works once, and only through the Log in button on its confirm screen. Link scanners in email apps can't use it up.
- **A limit on emails:** one account can ask for 3 codes every 15 minutes, and one IP address for 10 an hour.

Temporary support accounts can't use passwordless login. They log in with their own link or code.

## Where the option shows

In the Login and security tab, under "Show the email code option on", switch each place on or off. All of them are on by default.

- **WordPress login page:** an "Email me a login code" link under the form on wp-login.php. It opens the "Log in with a code" screen.
- **WooCommerce My Account:** "Email me a login code instead" under the login form. The code form opens in place, with no page reload, and they land back on My Account.
- **WooCommerce checkout:** the same option on the classic checkout's login form. They land back on the checkout.

The two WooCommerce places only show while WooCommerce is active.

![The WooCommerce My Account login form with an Email me a login code instead link, opened to an Email or username field and a Send login code button](../.github/assets/screenshots/woocommerce-passwordless.png)

### The block

Add the HappyAccess Login block to any page or template. You'll find it in the Widgets category. Logged-in visitors see nothing.

In the block settings:

- **Redirect after login:** a page on this site. Leave it empty to stay on the same page.
- **Toggle style:** Site default, Text link or Full button.

### The shortcode

Put `[happyaccess_login]` on any page. Logged-in visitors see nothing.

It takes two attributes:

- **redirect_to:** where to send people after login. It must be an address on your site, or it's ignored.
- **style:** `link` or `button`. Leave it out to use the Button style setting.

```
[happyaccess_login redirect_to="https://example.com/members/" style="button"]
```

The block and the shortcode work while Passwordless login is on, whatever the "Show the email code option on" switches say. If you turn Passwordless login off, take the shortcode off your pages too, or WordPress shows it as plain text.

### The block checkout

The WooCommerce block checkout doesn't let plugins add to its sign-in prompt yet. Add the HappyAccess Login block to your checkout page instead. The classic checkout and My Account get the option on their own.

## Code lifetime

Code lifetime sets how long the code and the link work: 5, 10, 15 or 30 minutes. The default is 10 minutes.

## Button style

Button style sets how "Email me a login code instead" looks on the WooCommerce forms, the block and the shortcode:

- **Text link:** the default.
- **Full button:** a button in place of the link.

The WordPress login page always shows a text link.

## How each role logs in

Under "How each role logs in", pick one of two choices for each role:

- **Password or email code:** the default. People can use either.
- **Email code only:** a password login is refused, and the login page points them to "Email me a login code".

When a person has several roles, Email code only wins if any of their roles has it.

A few accounts keep their password even in an Email code only role:

- **Super admins on multisite:** they can always use a password.
- **Accounts with another plugin's two-step login:** HappyAccess can't run that plugin's check on a code login, so these accounts keep the password and that plugin's check. They can't use email codes.
- **Application passwords:** keys someone made on purpose for an app or a script keep working.

### Getting back in with a password

Email code only depends on your site sending email. If an administrator role is set to it, HappyAccess warns that anyone who can read that inbox can log in as that role.

If email stops working and you're locked out, add this line to wp-config.php:

```
define( 'HAPPYACCESS_ALLOW_PASSWORD_LOGIN', true );
```

Every role can log in with a password again while the line is there. Fix email, then remove the line. [Locked out](locked-out.md#email-stopped-working-for-an-email-code-only-role) has the steps.

## With two-step login

When Two-step login is on too, an email code or link login works like this:

- **People with the authenticator app on:** they still enter a code from the app, or a backup code, after the email code.
- **Everyone else:** they log in right away, since the email code already proved they can read the inbox.
- **People whose role requires two-step login but who haven't set it up:** they go to the setup screen.

## Things to know

- Plugins that add their own checks to the password login, like user approval or country blocks, don't run on code and link logins.
- Every HappyAccess screen is a step of the normal WordPress login page, so hidden login URL plugins like WPS Hide Login move it along with everything else.
- HappyAccess login screens tell page caches not to store them.
- A login form script loads only on pages that show a HappyAccess form, and it needs no jQuery.
