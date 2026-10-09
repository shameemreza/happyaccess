# Contributing to HappyAccess

Thanks for wanting to help. Bug reports, fixes and ideas are all welcome. This guide covers the local setup, the checks every change has to pass, and what I look for in a pull request.

Security issues don't go here. Please follow the [security policy](SECURITY.md) and report them privately.

## Local setup

You need:

- PHP 7.4 or later.
- Composer.
- Node 22, the version in `.nvmrc`.
- MySQL or MariaDB for the PHPUnit suite.

### With Herd

I work in [Laravel Herd](https://herd.laravel.com/). Make a WordPress site, then clone this repo into its `wp-content/plugins/happyaccess` folder and install the packages:

```
composer install
npm ci
npm run build
```

The test bootstrap looks for WordPress four folders up from `tests/`, so a plugin inside a Herd site runs the suite against that site's WordPress files. It never touches the site's own database.

### With wp-env

[wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) works too, if you'd rather use Docker. Run it from the plugin folder, and it mounts the folder as a plugin on a local site:

```
npx @wordpress/env start
```

Run the PHPUnit suite on your own machine with the steps below, not inside the container.

## The test database

The PHPUnit suite installs WordPress into its own database on every run and empties it. Never point it at a database you care about.

The settings come from these environment variables, with these defaults:

| Variable | Default |
|---|---|
| `WP_CORE_DIR` | The WordPress install four folders up |
| `WP_TESTS_DB_NAME` | `happyaccess_tests` |
| `WP_TESTS_DB_USER` | `root` |
| `WP_TESTS_DB_PASSWORD` | Empty |
| `WP_TESTS_DB_HOST` | `127.0.0.1` |

Outside a WordPress install, `bin/install-wp-tests.sh` downloads WordPress and creates the database. It reads the password from `WP_TESTS_DB_PASSWORD`, never from an argument:

```
WP_CORE_DIR=/tmp/wordpress bin/install-wp-tests.sh happyaccess_tests root 127.0.0.1 7.1.1
WP_CORE_DIR=/tmp/wordpress composer test
```

The WooCommerce tests skip unless WooCommerce is in the plugins folder of that WordPress copy.

The WordPress test library itself comes from the `wp-phpunit/wp-phpunit` Composer package.

## Checks

Every change has to pass all of these. CI runs them on every push and pull request to `main`, with PHPUnit on PHP 7.4 and 8.4.

```
composer test
composer test:multisite
npm run build
composer test:release
vendor/bin/phpcs -q
composer lint:compat
npm run test:js
npm run lint:js
npm run lint:css
npm run size
```

- **`composer test`:** the PHPUnit suite on a single site.
- **`composer test:multisite`:** the same suite on multisite.
- **`composer test:release`:** checks the version in every file and what goes into the release zip. It fails when the build is missing, so run `npm run build` first.
- **`vendor/bin/phpcs -q`:** WordPress coding standards.
- **`composer lint:compat`:** PHPCompatibility, for PHP 7.4 and later.
- **`npm run size`:** fails when `build/index.js` is over 140,000 bytes.

The reCAPTCHA form tests run in jsdom, so they need `npm ci` first. On your machine they skip without it. In CI they fail.

Please don't run `phpcbf` or `lint --fix` across the whole repo. Fix the files you changed and nothing else.

## Coding standards

### PHP

- **PHP 7.4 syntax:** no `match`, nullsafe operator, named arguments, union types, enums or `readonly`. PHPCompatibility catches most of these.
- **WordPress style:** tabs, `array()` instead of `[]`, Yoda conditions, and snake_case names.
- **Direct access:** every PHP file the plugin loads starts with `defined( 'ABSPATH' ) || exit;`. `uninstall.php` checks `WP_UNINSTALL_PLUGIN` instead.
- **Prefixes:** every global hook, option, meta key, table and constant starts with `happyaccess` or `HAPPYACCESS`.
- **Hooks take named callbacks:** no closures in `add_action()` or `add_filter()`, so a class can register twice without adding a hook twice.
- **Database:** every query with a variable goes through `$wpdb->prepare()`, and dates are stored in UTC.
- **Secrets:** codes and keys are stored as hashes, never as plain text, and never logged.

### JavaScript and CSS

- The admin screen is React, built with `@wordpress/scripts`. WordPress packages stay externals.
- Every style sits under `.happyaccess-app`, with logical properties (`margin-inline-start`, not `margin-left`) so RTL works.
- Animations turn off under `prefers-reduced-motion: reduce`.

### Words people read

- Every string goes through the `happyaccess` text domain.
- Sentence case, plain words, and no em or en dashes.
- In the plugin's screens and emails, say "login code", "login link" and "two-step login", not "OTP", "magic link" or "2FA".

## Pull requests

1. Open an issue first for anything bigger than a small fix, so we can agree on the approach before you write the code.
2. Branch from `main` and keep each pull request to one change.
3. Add or update tests for any change in behavior.
4. Make sure every check above passes.
5. In the description, explain what changed, why, and how to test it by hand. Add screenshots for anything you can see.
6. Suggest one changelog line, starting with "New:", "Fixed:", "Changed:" or "Security:".

Don't bump the version or edit `readme.txt`. I do both when I make a release.

By sending a pull request, you agree to license your work under the GPL, version 2 or later, the same as HappyAccess.
