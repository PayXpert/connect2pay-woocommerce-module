=== PayXpert payment gateway ===
Contributors: payxpert
Tags: woocommerce, payment, gateway, payxpert, credit card, instalments
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.6.0
WC tested up to: 10.1.4

Accept card payments and instalments via PayXpert (Connect2Pay) in WooCommerce.

== Description ==

= Important note =

This module is not an update of the previous version.
It is a new standalone version (v2).
Before installing this module, the old version (v1) must be uninstalled and removed.

= Features =

* Choice of capture mode (automatic or manual)
* Email notification before authorization expiration
* Payment display modes: redirection or iFrame (seamless)
* PayByLink option
* Instalment payments
* Customization of payment methods
* Customizable notification system:
  * Enable/disable
  * Notification email address
  * Notification language

== Installation ==

= Method 1: From the WordPress back office =

* Go to Plugins > Add New
* Click Upload Plugin
* Select or drag & drop the module ZIP file
* Click Install Now then Activate

= Method 2: Manually =

* Unzip the module archive
* Copy the module folder into `/wp-content/plugins/`
* Then go to Plugins > Installed Plugins, search for "PayXpert" and click Activate

== Module Update ==

1. (Optional) Deactivate and remove the old version via Plugins > Installed Plugins
   If you want to keep the configuration, do not delete the plugin data
2. Install the new version:
   * Either by uploading the new ZIP via the plugin manager
   * Or by manually replacing the folder in `/wp-content/plugins/`, then activate the plugin

== Configuration ==

After activation, go to:

WooCommerce > Settings > Payments > PayXpert, then enter:

* API Public Key
* API Private Key

If your keys are valid, you will gain access to the module's advanced settings.

== Dependencies ==

* WooCommerce >= 7.6.0
* PHP >= 7.4
* A valid PayXpert account

== Quality / QA ==

Commits are gated by a mandatory git hook (QA + production-only `vendor/`).
Everything uses PHP 7.4 (`php7.4`) to match the plugin minimum runtime (PHP 7.4+).

= Prerequisites (once per clone) =

`php7.4 /usr/bin/composer install`
`php7.4 /usr/bin/composer qa:hooks`

This installs dev tooling (PHPCS, PHPStan, Slevomat PHP 8.4 nullable rule, etc.) and enables the pre-commit hook.

= Development (dev dependencies) =

Work with dev dependencies installed. To iterate on quality without touching Composer:

`bash .weplus/qa-loop.sh`
or: `php7.4 composer qa:run`
or: `php7.4 composer qa`

Useful commands:

`php7.4 /usr/bin/composer lint`          # PHP syntax (php7.4, php8.2, php8.4)
`php7.4 /usr/bin/composer phpcs`         # coding standards
`php7.4 /usr/bin/composer phpcs:fix`     # PHPCS auto-fix
`php7.4 /usr/bin/composer phpstan`       # static analysis

See `.weplus/quality-loop.md` for the fix → rerun → green loop.

= Commit (mandatory gate) =

On every `git commit`, the hook automatically runs:

1. `composer update` (with dev deps)
2. lint + PHPCS + PHPStan
3. `composer update --no-dev` when QA passes (production autoload only in `vendor/composer/`)

The commit is blocked if QA fails. One-off bypass: `SKIP_QA=1 git commit` or `git commit --no-verify`.

After every successful commit, restore dev tools:

`php7.4 /usr/bin/composer install`

= AI review (recommended) =

Before an important commit or MR: run Bugbot on the diff (`review-bugbot` in Cursor).

= WooCommerce Marketplace =

QIT tests are run by the partner client on submission. The release zip is built with an external tool, not in this repository.

Workflow: dev (`composer install`) → QA iteration (`qa-loop`) → AI review (optional) → `git commit` (hook) → `composer install` → external release → client QIT.

== License ==

This module is distributed under the GNU General Public License v2 or later. See the LICENSE file for more information.

== Support ==

A contact form is available on the module configuration page.
If you encounter any installation issues, please contact assistance@payxpert.com.
