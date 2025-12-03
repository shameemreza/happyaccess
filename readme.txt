=== HappyAccess ===
Contributors: shameemreza
Tags: admin, temporary access, support, security, otp
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure temporary admin access for WordPress support engineers. Generate OTP-based access without sharing passwords.

== Description ==

HappyAccess simplifies the process of granting **temporary admin access** to support engineers, developers, and agencies - securely, transparently, and GDPR-compliantly.

It removes the need for merchants to manually create/delete admin users or share passwords, while maintaining full control and audit visibility.

= Key Features =

* **OTP-Based Authentication** - Generate secure 6-digit codes instead of sharing passwords.
* **Reusable Access Codes** - Support engineers can log in multiple times with the same code until it expires.
* **Time-Limited Access** - Automatically expires after the set duration (1 hour to 30 days).
* **Automatic Cleanup** - Temporary users are deleted automatically when access expires.
* **Full Audit Log** - Track all access and actions with CSV export for compliance.
* **IP Allowlist** - Optionally restrict access codes to specific IP addresses.
* **Email Notifications** - Send access codes to admin email for secure sharing.
* **Emergency Lock** - One-click button to instantly revoke all active tokens.
* **Session Management** - Logout all temp sessions without revoking tokens.
* **GDPR Compliant** - Built-in consent workflow and data protection features.
* **Native WordPress UI** - Clean interface matching WordPress and WooCommerce admin styles.
* **Advanced Security** - Rate limiting, IP tracking, and failed attempt lockouts.
* **Active Token Management** - View all active codes, see usage status, and revoke anytime.

= How It Works =

1. Go to **Users → HappyAccess** in your WordPress admin.
2. Click **Generate Access** tab.
3. Choose duration (1 hour to 30 days) and role.
4. Optionally enable email notification.
5. Accept GDPR terms and click **Generate Access Code**.
6. Share the 6-digit code with your support engineer.
7. They enter the code at your login page - no username/password needed.
8. Access automatically expires and user is deleted.

= Perfect For =

* **Support Engineers** - Quick access without password hassles.
* **Agencies** - Manage client access professionally.
* **Store Owners** - Maintain security while getting help.
* **Developers** - Troubleshoot without credential sharing.

= GDPR & Security =

* All access must be disclosed in your Terms & Conditions.
* Complete audit trail of all actions.
* Data stored locally, not sent to third parties.
* Automatic data cleanup after 30 days.
* Rate limiting prevents brute force attacks.

== Installation ==

1. Upload the `happyaccess` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Users → HappyAccess** in your admin menu.
4. Configure settings (optional) in the Settings tab.
5. Start generating secure access codes!

= Requirements =

* WordPress 6.0 or higher.
* PHP 7.4 or higher.
* Administrator access to generate codes.

== Frequently Asked Questions ==

= Is this secure? =

Yes! HappyAccess uses cryptographically secure token generation, rate limiting, and automatic cleanup to ensure maximum security. Unlike traditional methods (creating user accounts and sharing passwords), OTP codes cannot be reused after expiry and don't expose any real credentials.

= Do I need to use tools like QuickForget to share passwords? =

No! That's the beauty of HappyAccess. You generate a 6-digit code and share it directly with your support engineer. No passwords, no QuickForget, no complicated steps. The code is temporary and auto-expires.

= Can support engineers log in multiple times with the same code? =

Yes! Access codes can be reused unlimited times until they expire. This means your support engineer can log in, log out, and log in again without needing a new code.

= Can the support person see my password? =

No. They never see or set any passwords. Authentication is handled entirely through the OTP system.

= What happens when access expires? =

The temporary user is automatically deleted and can no longer log in. All audit logs are retained for your records.

= Can I revoke access early? =

Yes! You can revoke any active token from the Active Tokens page at any time. You can also use "Logout All Temp Sessions" to force logout without revoking the code.

= Can I restrict access to specific IPs? =

Yes! When generating an access code, you can optionally specify an IP allowlist. Only connections from those IP addresses will be able to use the code.

= Is this GDPR compliant? =

Yes, but you must disclose in your Privacy Policy or Terms & Conditions that you grant admin access to third parties for support purposes. The plugin includes a consent checkbox to remind you of this requirement.

= What roles can I grant? =

Any WordPress role including Administrator, Editor, Author, Subscriber, and custom roles like Shop Manager.

= How long are logs kept? =

By default, logs are kept for 30 days. You can configure this in Settings.

== Screenshots ==

1. Generate Access - Simple form with duration, role, and email options.
2. OTP Display - Clear 6-digit code with copy button and instructions.
3. Active Tokens - Table showing all tokens with status and revoke options.
4. Audit Logs - Filterable event log with CSV export button.
5. Settings - Configure security and log retention options.
6. Login Form - Clean OTP field integration with WordPress login.
7. Emergency Lock - Admin bar button for instant revocation.

== Changelog ==

= 1.0.1 =
* NEW: Plugin action links - Quick access to Settings and Support from plugins page.
* NEW: Logout All Temp Sessions - Terminate active sessions without revoking tokens.
* NEW: IP Allowlist - Restrict access codes to specific IP addresses.
* NEW: Temp user logout link - Dropdown menu in admin bar with logout option.
* NEW: Live countdown timer - Real-time updating with auto-logout on expiry.
* NEW: Session duration tracking - Shows current session time in admin bar.
* NEW: Temp user logout auditing - Logs logout events with session duration.
* NEW: Login count tracking - Shows "First Login" vs "Login #2, #3" etc in audit log.
* IMPROVED: Tooltips now positioned BEFORE fields (matching WooCommerce style).
* IMPROVED: GDPR consent message is clearer with link to GDPR documentation.
* IMPROVED: Audit logs show temp_username for OTP Verified events.
* IMPROVED: Token Created logs now show masked OTP code (e.g., "12****").
* IMPROVED: Login Failed events now show masked attempted code.
* IMPROVED: Duration now displays as human-readable (e.g., "7 days" instead of "604800").
* IMPROVED: OTP codes can now be reused unlimited times until expiry.
* FIXED: OTP reuse bug - existing valid OTPs now work for multiple logins.
* FIXED: Audit log was reading wrong column (details vs metadata).
* FIXED: Plugin Check security warning - escaped table names in SQL queries.
* FIXED: Emergency Lock button now hidden from temporary users.
* FIXED: Duplicate HappyAccess_Admin class instantiation.
* ACCESSIBILITY: Enhanced OTP field with `inputmode="numeric"` and `autocomplete="one-time-code"`.
* ACCESSIBILITY: Added proper scope attributes to table headers.
* ACCESSIBILITY: Better screen reader support throughout the plugin.

= 1.0.0 =
* Initial release
* OTP-based authentication system (6-digit codes)
* Automatic user cleanup on expiry
* Full audit logging with date/event filters
* CSV export for audit logs
* Email notifications to admin (optional)
* Emergency Lock button in admin bar
* Active tokens management dashboard
* GDPR compliance with consent workflow
* Rate limiting and IP lockout for security
* WordPress native UI with helpful tooltips
* WooCommerce HPOS compatibility declared
* Support for all WordPress roles
* Configurable token expiry (1 hour to 30 days)
* Configurable log retention period

== Privacy Policy ==

HappyAccess stores access logs locally on your WordPress site. No data is sent to external services. 

The plugin collects:
* IP addresses of users accessing with temporary codes.
* Browser information (user agent).
* Access times and durations.
* Actions performed (audit log).

This data is automatically deleted after 30 days unless configured otherwise.

You must disclose in your Terms & Conditions that you may grant admin access to third parties for support purposes.
