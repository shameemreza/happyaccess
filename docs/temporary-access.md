# Temporary access

A support pass gives someone a login link and an 8-digit code instead of a password. Their access ends at the time you pick, and their account goes with it.

Temporary access is on from the start. You'll find it in Users > HappyAccess > Temporary access.

## Create a pass

1. Go to Users > HappyAccess > Temporary access.
2. In Who is it for, type a name you'll recognize, like "Acme support" or a ticket number. It shows in the list, the emails and the Activity tab.
3. In Their email, add their address if you want HappyAccess to email the access to them. Leave it empty to share it yourself.
4. Under What they can do, pick an access level. See [access levels](#access-levels).
5. Under Access ends after, pick 1 day, 3 days, 7 days or Custom.
6. Open More options if you need any of the [extra limits](#more-options).
7. Select Create support pass.

The preview under the form shows the pass as you build it. The Create support pass button stays off until the pass is complete, and the line under it says what's missing.

## Access levels

### Protected admin

The default, and the right pick for most support work. They get an admin account that can fix almost anything, with the risky actions blocked.

A Protected admin pass can never:

- Change any account's password or email address, or start a password reset.
- Edit or delete administrators, or delete users.
- Create users or change anyone's role.
- Install, update or delete plugins and themes, or update WordPress.
- Use the plugin and theme file editors.
- Make WooCommerce API keys.
- Erase the activity log, change HappyAccess settings or turn HappyAccess off.

HappyAccess is also hidden from their plugin list. Their account can't see or edit yours.

To give a role other than administrator, open More options and pick it in "Or give a different role". The same protections apply.

### Custom access

Pick exactly what they can do, permission by permission. Start from a preset, then tick or untick single permissions.

You can only give permissions your own account has. If you tick an admin-level permission, like changing site settings, installing code or managing users, a red box asks you to confirm that you trust this person with full access.

### Full admin

For teams you fully trust, like your host or your developer. They can do anything an admin can, including installing code and creating other admin accounts. You confirm that you trust them before you can create the pass.

A few things stay off even here. The plugin and theme file editors and application passwords are blocked, and the account can't change HappyAccess settings or turn HappyAccess off. When a Full admin pass creates or changes an administrator account, you get an email and the Activity tab flags it.

### It's your live site

A pass logs them in to your live site, not a copy, so what they change is real. Your other plugins run as usual while they work.

On Protected admin and Custom access passes, HappyAccess refuses role permission changes that a plugin tries on a page they load, unless it happens during a plugin activation or update. The Activity tab logs each refusal as "Role change blocked". On a Full admin pass, role changes go through and the Activity tab lists them.

## How long it lasts

The form starts at your Default pass length setting, 3 days unless you changed it. Pick 1 day, 3 days or 7 days. Custom opens a date and time picker for anything from 1 hour to 30 days ahead. Times use your site's timezone, and the form names the timezone when it differs from your browser's.

30 days is the limit for every pass, including when you extend one later.

## More options

Select More options under Access ends after.

- **One-time use:** the link and the code work for one login. That session can carry on until the access ends.
- **Or give a different role:** Protected admin only. Pick another role instead of administrator.
- **Login alerts:** First login (the default), Every login or Off. The alert goes to you, with the time, the IP address and whether they used the link or the code. A Full admin pass always alerts on every login, so the choice is fixed there.
- **Hide admin screens:** pick menu items they shouldn't see. HappyAccess hides them and refuses their URLs.
- **Only allow these IP addresses:** the link and the code only work from these addresses. Separate several with commas. Leave it empty to allow any address.
- **After login, open:** a path like `/wp-admin/edit.php`. Leave it empty to open the dashboard.
- **Email it to them now:** shows once you add their email. HappyAccess sends the link and the code as soon as you create the pass.

The IP allowlist is checked when they log in. Someone on another address gets the same answer as a wrong code or an old link, so the screen gives nothing away.

## Send the link or the code

![A new pass showing the login link and the 8-digit access code with Copy buttons, plus buttons to copy a message with instructions or send it by email](../.github/assets/screenshots/access-link-and-code.png)

After you create the pass, HappyAccess shows the login link and the access code. Either one works, so send whichever fits.

- **Copy:** copies the link or the code.
- **See the message they get:** shows the ready-to-paste message.
- **Copy message with instructions:** copies a message with the site name, the login link, the code screen address with the code, and when the access ends.
- **Send by email:** makes a new link and code and emails them to the address on the pass. The ones on screen stop working.

You won't see the code again after you leave this screen. If you lose it, use New link and code on the pass. Anyone with the link or the code can log in, so for extra safety send them through different channels.

When you're done, select "Done, give access to someone else" to go back to the form.

## Ask a client for access

If you're the support person, you can send this to a client. Copy it, change the names, and paste it into your ticket or email.

> Hi! To look into this, I need to log in to your site. You don't have to send me a password or make me an account.
>
> 1. Install the free HappyAccess plugin from your Plugins screen.
> 2. Go to Users > HappyAccess and create a pass. Three days is plenty.
> 3. Send me the link it shows you.
>
> My access ends by itself when the time is up, and you can end it earlier from the same screen. You'll also see what I changed while I was in.

## What the support person sees

![The confirm screen a support person sees after opening a login link, asking to log in as the pass name with a Log in button](../.github/assets/screenshots/support-login-confirm.png)

With the link:

1. They open the link.
2. A confirm screen asks "Log in to [your site name] as [the pass name]?".
3. They select Log in.

The link never logs anyone in by itself. Only the button on the confirm screen does, so a link scanner in an email or chat app can't use it up.

With the code:

1. They open your login page and select "Log in with an access code" under the password field, above the Log in button.
2. They type the 8-digit code and select Log in.

They land on the dashboard, or on the page you set in After login, open. The admin bar shows "Temporary access ends in" with the time left, and an End session link that logs them out. Ending their session doesn't end the pass. They can log in again until it ends.

When the pass ends, they're logged out and see a "Temporary access ended" screen that says whether it expired, was revoked or is paused.

## See what they changed

![The Activity tab with a session summary for a pass and a list of the product, page and settings changes made today](../.github/assets/screenshots/activity-log.png)

The Activity tab lists what temporary users changed: posts, pages and products, order status changes, settings, plugins, themes and users, with times and IP addresses. It stores titles and names only, never post content, setting values or passwords.

1. Go to Users > HappyAccess > Activity, or select View activity on a pass.
2. Pick a feature (All, Temporary access, Admin and the others that are on), who, and when (Last 7 days, Last 30 days or a custom range).
3. Type in the search box to narrow it down.

View activity on a pass opens a session summary with its logins, changes, time in total and IP addresses.

To export, select Export CSV. The file holds the rows that match your filters, up to 5,000, with these columns: time, feature, event, summary, user, pass and ip.

Log entries are kept for 30 days by default. Change it in Settings > Keep activity for.

## Manage a pass

Each pass in "Who has access" shows a countdown, its status, its level and its last login. Select an action on the row.

- **Extend:** add 1, 3 or 7 days. The new end is never more than 30 days from now. If they're logged in, their session carries on.
- **Suspend:** logs them out and stops the link and the code until you select Resume.
- **New link and code:** makes a new link and code. The old ones stop working, and anyone using the pass is logged out. A suspended pass stays suspended. Use it to give a used one-time pass another login.
- **View activity:** opens the Activity tab for this pass.
- **Revoke:** ends the pass now. They're logged out right away and the account is deleted.

Revoke all, at the top of the list, ends every pass in it.

## Emergency lock

Emergency lock ends every pass at once. Everyone using a pass is logged out and their accounts are deleted.

You'll find it in two places:

- **On the HappyAccess screen:** the Emergency lock button in the header. It asks "End every support pass now?" before it does anything.
- **In the admin bar:** an Emergency lock link, for administrators, while any pass is current. It asks you to confirm, then a notice says how many passes it ended.

## When access ends

A pass ends when its time runs out, or when someone:

- Revokes it, or uses Revoke all or Emergency lock.
- Turns Temporary access off in Settings.
- Deactivates or deletes HappyAccess.
- Erases that person's data with the WordPress Erase Personal Data tool.

Then HappyAccess:

1. Ends their sessions and logs them out.
2. Moves anything they wrote to the person who created the pass, or to the first administrator account if that account is gone. Nothing they made is deleted.
3. Deletes their WooCommerce API keys, if they made any.
4. Deletes their temporary account.
5. Emails the person who created the pass the reason, how many times they logged in and what they did.

The Activity tab keeps their entries for as long as you keep logs.
