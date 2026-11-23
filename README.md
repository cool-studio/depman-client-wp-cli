# depman/wp-cli-client

> **This repository is a read-only mirror.**
> The client is developed in [`cool-studio/depman`](https://github.com/cool-studio/depman) under
> `packages/wp-cli-client`, and split out here so that WP-CLI can install it. **Open issues and
> pull requests there.** This repository is force-pushed from the monorepo and anything committed
> directly is overwritten without warning.

Reports the plugins, themes and WordPress core **actually installed** on a WordPress site to
[DepMan](https://depman.io), which matches them against published security advisories and raises a
Finding when one of your environments is running a vulnerable version.

It is a WP-CLI command, `wp depman`, for sites installed the ordinary way, through the admin screen
and without Composer. A Composer-managed site (Bedrock and friends) is covered by
[`depman/client`](https://packagist.org/packages/depman/client) instead.

## Install

As the user that runs WP-CLI on the server. Packages are per user, in `~/.wp-cli/packages`:

<!-- snippet:install-wp -->
```bash
wp package install depman/wp-cli-client
```
<!-- /snippet -->

## Configure

`depman.json` goes in the WordPress root, the directory holding `wp-load.php`. It holds no secrets:
the client refuses a `token` key loudly and carries on without it.

<!-- snippet:depman-json -->
```jsonc
{
  "$schema": "https://schema.depman.io/depman.schema.v1.json",
  "configVersion": 1,
  "endpoint": "https://depman.acme-internal.com",
  "project": "acme/storefront",

  "environment": {
    "from": ["DEPMAN_ENV", "APP_ENV"],
    "fallback": "local",                                   // a laptop is not production
    "map": { "prod": "production", "stage": "staging" },
    "allowed": ["local", "ci", "staging", "production"]
  },

  "ecosystems": "auto",
  "include": { "dev": true, "optional": true },

  "failOnError": false,                                    // never break composer install
  "timeoutMs": 10000,
  "offline": { "mode": "spool", "spoolDir": ".depman/spool" },
  "logLevel": "warn"
}
```
<!-- /snippet -->

`include`, `failOnError` and the rest are the same keys every DepMan client reads; a WordPress site
has no dev dependencies, so `include` changes nothing here. Every setting can be overridden per run:
the `.env` in the WordPress root is read first, then the process environment, then `depman.json`,
then the built-in default.

**A missing `depman.json` is a silent skip.**

## Schedule it

WordPress has no install hook. A plugin updates through the admin screen, through
`wp plugin update`, or by itself at 3am, and none of those runs anything of ours. So report from the
server's own crontab:

<!-- snippet:schedule-wp -->
```bash
# crontab -e, as the user that installed the package
17 */6 * * * cd /var/www/example.com && DEPMAN_ENV=production DEPMAN_TOKEN_FILE=$HOME/.depman/token wp depman report --quiet
```
<!-- /snippet -->

Each run reports the whole site, and an unchanged site costs almost nothing. A site that stops
reporting goes stale in DepMan, and DepMan alerts you, so a broken cron line does not fail silently.

## The token

`DEPMAN_TOKEN`, found in the first of these that has it:

1. `DEPMAN_TOKEN` in a `.env` in the WordPress root.
2. `DEPMAN_TOKEN` in the process environment.
3. `DEPMAN_TOKEN_FILE` pointing at a file.
4. `~/.depman/credentials`, JSON keyed by endpoint host.

**Prefer 3 or 4 on a WordPress server.** The WordPress root is usually the web server's document
root, and a `.env` there is a file it will serve unless told not to. `wp depman doctor` warns you
when that is where the token came from. The value is never printed, and never written to a payload
or a log line.

## What it reports

| Installed | Reported as |
|---|---|
| WordPress core | `johnpbloch/wordpress-core` |
| A plugin or theme wordpress.org recognises | `wpackagist-plugin/{slug}` / `wpackagist-theme/{slug}` |
| Any other plugin or theme, must-use plugins and drop-ins | a `--local` name that can never match an advisory |

**A slug is only used where WordPress itself has been told it.** That means an `Update URI` header
naming wordpress.org, or wordpress.org's own id for the plugin in WordPress's update data. A
directory name is never taken for a slug: a premium plugin can share its directory name with an
unrelated free one, and a wrong name would produce confident alerts about somebody else's plugin.
Anything that cannot be identified is still reported, and DepMan shows it as a gap in coverage
rather than as "no vulnerabilities".

The update data is WordPress's own and is read as it stands. A plugin installed since WordPress last
checked for updates is identified after its next check, twice a day.

## Commands

| Command | Does |
|---|---|
| `wp depman report` | Report the site now |
| `wp depman push` | Send anything left in the offline spool |
| `wp depman doctor` | Print the resolved config, where each setting came from, which mechanism supplied the token (never the value), and how much of the site could be identified |

```
Project:      example-blog (from depman.json)
Endpoint:     https://depman.io (from the default)
Environment:  production (from DEPMAN_ENV)
Token:        found via DEPMAN_TOKEN_FILE
Spool:        /home/deploy/.depman/spool/3f9c1a2b7d4e
WordPress:    6.8.3
Identified:   23 of 26 packages on wordpress.org; 3 reported as inventory only
```

## When it cannot reach us

A report that cannot be delivered is written to the spool rather than lost, and `wp depman push`
sends it later. The spool defaults to `~/.depman/spool/<site>`, **outside the WordPress root**,
because a queued report lists every plugin and version on the site. It holds twenty and discards
the oldest first.

## Failing a build on purpose

From a deploy pipeline, and never from cron:

```bash
wp depman report --wait --fail-on=high
```

It waits for DepMan to scan the report and exits `1` if any open finding for that environment is
`high` or worse. Thresholds are `critical`, `high`, `medium`, `low` and `unknown`. **It fails
closed:** exit `2` means DepMan could not say, and is never a pass.

## Requirements

PHP 7.4 or newer, WP-CLI 2, WordPress 5.8 or newer for `Update URI`. It has no dependencies of its
own.

## Development

This package is self-contained: clone it on its own, and its whole toolchain is here.

```bash
composer install
composer check     # pint --test, phpstan, phpunit, and an install of the packed archive
```

Changes belong in the [monorepo](https://github.com/cool-studio/depman), not here.

## Licence

[MIT](LICENSE). © 2026 Cool Studio.
