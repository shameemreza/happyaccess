# Security policy

HappyAccess hands out logins to WordPress sites, so a security bug in it could hand a login to the wrong person. I read and answer every report.

## Supported versions

| Version | Supported |
|---|---|
| 1.1.x | Yes |
| 1.0.x | No |

Security fixes go into the latest 1.1.x release. If you're on 1.0.x, please update to 1.1.x.

## Reporting a vulnerability

Please don't report a security issue in a public GitHub issue, a pull request or the WordPress.org support forum. Use one of these private channels instead:

- **GitHub:** open the [Security tab](https://github.com/shameemreza/happyaccess/security) of this repo and select "Report a vulnerability". Only you and I can see the report.
- **Patchstack:** report it through the [HappyAccess program on Patchstack](https://patchstack.com/database/vdp/happyaccess).

A good report has:

- The HappyAccess version, plus the WordPress and PHP versions.
- The steps to reproduce it, starting from a fresh install if you can.
- What an attacker gains, and what access they need first.
- A proof of concept, if you have one.

## What happens next

- **Within 3 business days:** I confirm I got your report.
- **Within 14 days of confirming the issue:** I ship a fix or send you a plan with a date for it.

I'll keep you posted while I work on it. Please give me the chance to release the fix before you publish anything about the issue.
