# Changelog

Every HappyAccess release, newest first. The WordPress.org readme keeps the last few; this file keeps them all.

## 1.1.0

HappyAccess is rebuilt from the ground up in this release.

- New: Passwordless login with an email code or link, on the login page, WooCommerce My Account and checkout, a block and a shortcode.
- New: Two-step login with an authenticator app, email codes and backup codes, optional or required per role.
- New: New device alerts for administrators, and any other role you pick.
- New: A new admin screen with a live pass preview, the Activity tab and the Login and security tab.
- New: Protected admin, Custom access and Full admin access levels.
- New: Login links open a confirm screen, so link scanners can't use them up.
- New: The Activity tab shows what temporary users changed, with CSV export.
- New: WP-CLI commands.
- New: Multisite support, network activated or per site.
- Security: codes and keys are stored as hashes, with rate limits per IP and per account.
- Security: the access code is now 8 digits.
- Changed: Temporary users' posts move to your account when access ends. They're never deleted.
- Changed: HappyAccess needs WordPress 6.7 or later. 1.0.6 needed 6.0.

### Upgrading from 1.0.x

- Your active access codes keep working on the new code screen, for up to 7 days from the upgrade.
- Login links and share links made with 1.0.x stop working at the upgrade. Send the access code instead, or make a new pass.
- There's no way back to 1.0.6 after upgrading, because the database changes.

## 1.0.6

- FIXED: OTP login failing on hosts with `mod_security` (Bluehost, HostGator, Newfold) that strip non-standard POST parameters from `wp-login.php`.
- IMPROVED: Login JS now sets fallback sentinel values in standard username/password fields so the OTP reaches the server even when custom POST parameters are blocked.
- IMPROVED: Server-side fallback detects sentinel username and extracts OTP from password field when primary POST parameter is unavailable.
- IMPROVED: Core WordPress auth handlers are removed during fallback to prevent confusing "invalid username" errors.
- IMPROVED: Login form sets `novalidate` when OTP is entered to prevent browser HTML5 validation from blocking submission with empty username/password.
- IMPROVED: Fallback usage is logged as `otp_fallback_used` event for diagnosing hosting compatibility issues.

## 1.0.5

- NEW: Admin Menu Restrictions: Block temp users from specific admin pages with a visual picker.
- NEW: Per-Plugin Submenu Restrictions: Granular control over sub-pages (WooCommerce tabs, EDD sections, any plugin).
- NEW: Direct URL Access Blocking: Restricted pages are blocked even when accessed by direct URL.
- NEW: Hide Admin Bar: Option to hide the WordPress admin bar for temporary users.
- NEW: Main Admin Protection: Temp users cannot see, edit, or delete the token creator.
- NEW: Plugin Self-Protection: HappyAccess is hidden from the plugins list for temp users.
- NEW: User Bulk Action Protection: Temp users cannot bulk-delete or bulk-modify real admin accounts.
- NEW: Activate/Deactivate Toggle: Suspend and reactivate temp user access without deleting.
- NEW: Deactivation blocks all login paths (OTP, magic link) and destroys active sessions.
- SECURITY: Magic link login now blocked for deactivated temp users.
- SECURITY: OTP login now blocked for deactivated temp users.
- SECURITY: Access guard enforces restrictions server-side with `current_screen` hook.
- SECURITY: Complex submenu slugs with query strings (e.g., `edit.php?post_type=product`) are properly blocked.
- SECURITY: All access denial attempts are logged with full URL for audit trail.
- IMPROVED: Menu picker shows nested parent/child structure with auto-check-all behavior.
- IMPROVED: Audit log auto-cleanup now also prunes old log entries (was only pruning tokens).
- IMPROVED: IP allowlist parsing now trims whitespace consistently across OTP and magic link paths.
- IMPROVED: All JS strings are fully localized for translation readiness.
- FIXED: Missing `access_deactivated` error message in magic link redirect handler.

## 1.0.4

- SECURITY: Added nonce verification to CSV export to prevent CSRF attacks.
- SECURITY: Hardened IP detection to prefer REMOTE_ADDR over spoofable proxy headers.
- SECURITY: Added `happyaccess_client_ip` filter for sites behind load balancers.
- SECURITY: Elevated capability requirement from `list_users` to `manage_options`.
- SECURITY: Removed raw token hash from generate response.
- SECURITY: Replaced inline JavaScript with `wp_add_inline_script()` for Emergency Lock.
- SECURITY: Escaped all AJAX response messages with `esc_html__()` and `esc_html()`.
- SECURITY: Rate limiter now keyed on IP only, which prevents brute-force via per-OTP bypass.
- SECURITY: Atomic UPDATE for token use_count prevents race condition on single-use tokens.
- SECURITY: Fixed time()/current_time() timezone mismatch in magic link and share link hashes.
- SECURITY: reCAPTCHA now fails closed when misconfigured or on network errors (no fake score 1.0).
- SECURITY: Added rate limiting to OTP share link verification.
- SECURITY: Atomic single-view marking on share links prevents double-view race condition.
- IMPROVED: Centralized IP detection into single method (no more duplicate code).
- IMPROVED: Added recursion depth limits to username and OTP generation.
- IMPROVED: Refactored logger `get_logs()` with proper date range filters.
- IMPROVED: Removed stale 1-hour cache on admin log display for real-time data.
- IMPROVED: Database version check replaces `SHOW TABLES` on every request.
- IMPROVED: OTP shares table creation uses version flag to avoid redundant checks.
- IMPROVED: Added `index.php` to all subdirectories to prevent directory listing.
- IMPROVED: Sanitized `settings-updated` GET parameter properly.
- IMPROVED: Added Third-Party Services disclosure for reCAPTCHA in readme.
- IMPROVED: OTP shares table now created during plugin activation (not lazy).
- IMPROVED: GDPR eraser now anonymizes `created_by` in tokens table.
- IMPROVED: Added missing `happyaccess_enable_email` and `happyaccess_gdpr_consent_text` to uninstall cleanup.
- IMPROVED: AJAX error responses use `wp_send_json_error()` consistently (no more `wp_die()`).
- FIXED: Hooked missing `happyaccess_cleanup_attempts` cron event with init fallback.
- FIXED: Added `happyaccess_attempts` table to uninstall cleanup.
- FIXED: Removed unnecessary `flush_rewrite_rules()` from activation/deactivation.
- FIXED: Removed unused `happyaccess_activated` option.
- SECURITY: All `$_POST`/`$_GET` superglobals now sanitized with `wp_unslash()` + `sanitize_text_field()`.
- SECURITY: Role parameter validated against registered `wp_roles()` whitelist.
- SECURITY: All `sprintf()` in AJAX responses wrapped with `esc_html()`.
- SECURITY: Replaced all `current_time('mysql')` with `gmdate('Y-m-d H:i:s')` for UTC-consistent timestamps across 8 files.
- IMPROVED: All JavaScript strings localized via `wp_localize_script()` for translation readiness.
- IMPROVED: All conditions follow WordPress Yoda coding standards.
- IMPROVED: Added `@return` PHPDoc tags to the methods in the main plugin file that return nothing.
- IMPROVED: Audit log table rows now highlight on hover (WooCommerce-style).
- COMPATIBILITY: Tested with WordPress 7.0-beta5.

## 1.0.3

- NEW: Magic Link Authentication - Generate secure one-click login links that expire in 1-10 minutes.
- NEW: OTP Share Links - Generate secure links to view OTP codes (single-view, auto-expires).
- NEW: Email Magic Link - Send magic links directly via email to support engineers.
- NEW: reCAPTCHA v3 Integration - Optional invisible bot protection for OTP login.
- NEW: Magic Link option in Generate Access form - Create OTP + magic link together.
- NEW: Beautiful OTP reveal page - Branded, secure page for viewing shared OTP codes.
- SECURITY: Magic links are single-use and automatically invalidated after access.
- SECURITY: Only ONE magic link OR share link active per token at a time (previous links auto-invalidated).
- SECURITY: reCAPTCHA prevents automated brute-force attacks on OTP field.
- SECURITY: All links use HMAC-SHA256 with time-based validation.
- IMPROVED: Active Tokens page now includes "Magic Link" button for quick link generation.
- IMPROVED: Database tables auto-created on plugin update (no deactivation needed).
- IMPROVED: Audit logs track magic link, share link creation, viewing, and email sending.
- IMPROVED: reCAPTCHA scores are logged for security analysis.
- FIXED: Rate limiter method calls in magic link verification.

## 1.0.2

- NEW: One-Time Use option - Generate codes that automatically revoke after first successful login.
- NEW: Clear All Logs button in Audit Logs tab for manual log management.
- NEW: "Delete Data on Uninstall" option in Settings to control data cleanup.
- SECURITY: All active tokens are now revoked when the plugin is deactivated.
- SECURITY: All temporary users are cleaned up on plugin deactivation (no leftovers).
- SECURITY: Single-use tokens auto-revoke immediately after login, preventing code reuse.
- IMPROVED: Active Tokens page now shows status as "Unused (One-Time)" or "Active (One-Time)" for single-use codes.
- IMPROVED: Email notifications now include one-time use warning when applicable.
- IMPROVED: Audit logs now track single-use token creation and auto-revocation.
- IMPROVED: Get Support link moved to plugin description row (next to author/plugin site).
- FIXED: Complete cleanup on deactivation - no leftover tokens or users remain.
- FIXED: Plugin deactivation now properly logs the action with count of revoked tokens.

## 1.0.1

- NEW: Plugin action links - Quick access to Settings and Support from plugins page.
- NEW: Logout All Temp Sessions - Terminate active sessions without revoking tokens.
- NEW: IP Allowlist - Restrict access codes to specific IP addresses.
- NEW: Temp user logout link - Dropdown menu in admin bar with logout option.
- NEW: Live countdown timer - Real-time updating with auto-logout on expiry.
- NEW: Session duration tracking - Shows current session time in admin bar.
- NEW: Temp user logout auditing - Logs logout events with session duration.
- NEW: Login count tracking - Shows "First Login" vs "Login #2, #3" etc in audit log.
- IMPROVED: Tooltips now positioned BEFORE fields (matching WooCommerce style).
- IMPROVED: GDPR consent message is clearer with link to GDPR documentation.
- IMPROVED: Audit logs show temp_username for OTP Verified events.
- IMPROVED: Token Created logs now show masked OTP code (e.g., "12****").
- IMPROVED: OTP Verified logs now show masked OTP code for traceability.
- IMPROVED: Login Failed events now show masked attempted code.
- FIXED: Reference Note not displaying in Active Tokens table.
- IMPROVED: Duration now displays as human-readable (e.g., "7 days" instead of "604800").
- IMPROVED: OTP codes can now be reused unlimited times until expiry.
- FIXED: OTP reuse bug - existing valid OTPs now work for multiple logins.
- FIXED: Audit log was reading wrong column (details vs metadata).
- FIXED: Plugin Check security warning - escaped table names in SQL queries.
- FIXED: Emergency Lock button now hidden from temporary users.
- FIXED: Duplicate HappyAccess_Admin class instantiation.
- ACCESSIBILITY: Enhanced OTP field with `inputmode="numeric"` and `autocomplete="one-time-code"`.
- ACCESSIBILITY: Added proper scope attributes to table headers.
- ACCESSIBILITY: Better screen reader support throughout the plugin.

## 1.0.0

- Initial release
- OTP-based authentication system (6-digit codes)
- Automatic user cleanup on expiry
- Full audit logging with date/event filters
- CSV export for audit logs
- Email notifications to admin (optional)
- Emergency Lock button in admin bar
- Active tokens management dashboard
- GDPR compliance with consent workflow
- Rate limiting and IP lockout for security
- WordPress native UI with helpful tooltips
- WooCommerce HPOS compatibility declared
- Support for all WordPress roles
- Configurable token expiry (1 hour to 30 days)
- Configurable log retention period
