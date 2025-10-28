=== HappyAccess ===
Contributors: shameemreza
Tags: admin, temporary access, support, security, otp
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure temporary admin access for WordPress support engineers. Generate OTP-based access without sharing passwords.

== Description ==

HappyAccess simplifies the process of granting **temporary admin access** to support engineers, developers, and agencies - securely, transparently, and GDPR-compliantly.

It removes the need for merchants to manually create/delete admin users or share passwords, while maintaining full control and audit visibility.

= Key Features =

* **🔐 OTP-Based Authentication** - Generate secure 6-digit codes instead of sharing passwords.
* **⏱ Time-Limited Access** - Automatically expires after the set duration (1 hour to 30 days).
* **🧹 Automatic Cleanup** - Temporary users are deleted automatically when access expires.
* **📝 Full Audit Log** - Track all access and actions with CSV export for compliance.
* **✉️ Email Notifications** - Optionally send access codes to admin email for secure sharing.
* **🚨 Emergency Lock** - One-click button to instantly revoke all active tokens.
* **✅ GDPR Compliant** - Built-in consent workflow and data protection features.
* **🎨 Native WordPress UI** - Uses WordPress admin styles with helpful tooltips.
* **🛡️ Advanced Security** - Rate limiting, IP tracking, and failed attempt lockouts.
* **📊 Active Token Management** - View all active codes, see usage status, and revoke anytime.

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

Yes! HappyAccess uses cryptographically secure token generation, rate limiting, and automatic cleanup to ensure maximum security.

= Can the support person see my password? =

No. They never see or set any passwords. Authentication is handled entirely through the OTP system.

= What happens when access expires? =

The temporary user is automatically deleted and can no longer log in. All audit logs are retained for your records.

= Can I revoke access early? =

Yes! You can revoke any active token from the Active Tokens page at any time.

= Is this GDPR compliant? =

Yes, but you must disclose in your Terms & Conditions that you grant admin access to third parties for support purposes.

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
