# JSON:API Views

[![Pipeline](https://git.drupalcode.org/project/jsonapi_views/badges/8.x-1.x/pipeline.svg)](https://git.drupalcode.org/project/jsonapi_views/-/pipelines)
[![Test](https://github.com/Decipher/jsonapi_views/actions/workflows/test.yml/badge.svg?branch=8.x-1.x)](https://github.com/Decipher/jsonapi_views/actions/workflows/test.yml?query=branch%3A8.x-1.x)
[![Coverage](https://codecov.io/gh/Decipher/jsonapi_views/branch/8.x-1.x/graph/badge.svg)](https://codecov.io/gh/Decipher/jsonapi_views/branch/8.x-1.x)

It creates a [JSON:API Resource](https://www.drupal.org/project/jsonapi_resources)
for each [Views](https://www.drupal.org/docs/8/core/modules/views) display,
allowing for easy consumption of that data outside of Drupal.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/jsonapi_views).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/jsonapi_views).

## Table of contents

- Requirements
- Installation
- How it works
- Local development
- Building website
- Coding standards
- Testing
- Maintainers

## Requirements

- Drupal 10 or 11
- [Views](https://www.drupal.org/docs/8/core/modules/views) (Drupal core)
- [JSON:API Resources](https://www.drupal.org/project/jsonapi_resources)

## Installation

Install as you would normally install a contributed Drupal module. Visit
<https://www.drupal.org/node/1897420> for further information.

## How it works

When installed, the module activates a JSON:API resource for every enabled
View display. The resource can be disabled per-display by editing the view
and unchecking "Expose via JSON:API" in the display's JSON:API settings
(added by the `jsonapi_views` display extender plugin).

The URL of the JSON:API Views resource is shown while editing a view, based
on the view's current preview state (filters, pagination and sorts included).

### Summary of current features

- JSON:API resource per View display: `/jsonapi/views/{{ viewId }}/{{ displayId }}`
- Pagination: `?page=#`
- Exposed filters: `?views-filter[{{ filter }}]={{ value }}`
- Contextual filters: `?views-argument[]={{ value }}`
  - Multiple arguments as such: `?views-argument[]={{ value }}&views-argument[]={{ value2 }}`
- Exposed sorts: `?views-sort[sort_by]={{ sortId }}`

## Local development

1. Install PHP with SQLite support and Composer
2. Clone this repository
3. Run `make build`

## Building website

`make build` assembles the codebase, starts the PHP server and provisions
the Drupal website with this module enabled. These operations are executed
using scripts within the [`.devtools`](.devtools) directory. CI uses the
same scripts to build and test this module.

The resulting codebase is then placed in the `build` directory. The module's
files are symlinked into the Drupal site structure.

The `build` command is a wrapper for more granular commands:

```bash
make assemble     # Assemble the codebase
make start        # Start the PHP server
make provision    # Provision the Drupal website
```

The `provision` command is useful for re-installing the Drupal website
without re-assembling the codebase.

### Drupal versions

The Drupal version used for the codebase assembly is determined by the
`DRUPAL_VERSION` variable and defaults to the latest stable version.

You can specify a different version by setting the `DRUPAL_VERSION`
environment variable before running the `make build` command:

```bash
DRUPAL_VERSION=11 make build        # Drupal 11
DRUPAL_VERSION=11@alpha make build  # Drupal 11 alpha
DRUPAL_VERSION=10@beta make build   # Drupal 10 beta
DRUPAL_VERSION=11.1 make build      # Drupal 11.1
```

The `minimum-stability` setting in the `composer.json` file is
automatically adjusted to match the specified Drupal version's stability.

### Providing `GITHUB_TOKEN`

To overcome GitHub API rate limits, you may provide a `GITHUB_TOKEN`
environment variable with a personal access token.

### Provisioning the website

The `provision` command installs the Drupal website from the `standard`
profile with the module enabled. The website will be available at
http://localhost:8000 by default. The hostname can be changed by setting
the `WEBSERVER_HOST` environment variable.

The `WEBSERVER_PORT` is resolved with the following precedence:

1. **`WEBSERVER_PORT` exported in the shell** - used as-is. Useful for one-off
   runs: `WEBSERVER_PORT=9000 make build`.
2. **`WEBSERVER_PORT` line in the project-root `.env` file** - used as-is.
   The `start` script does not modify `.env` when this entry is already
   present, so the same port is reused across `start`, `stop`, `provision`,
   `drush` and `login` commands.
3. **Neither is set** - the `start` script discovers the first free port in
   the range `8000-8099` and writes it to `.env` as `WEBSERVER_PORT=NNNN`.
   Subsequent commands read this value from `.env`.

To force re-discovery, delete `.env` (or just the `WEBSERVER_PORT` line in
it) and re-run `make start`.

An SQLite database is created in `/tmp/site_jsonapi_views.sqlite` file.
You can browse the contents of the created SQLite database using
[DB Browser for SQLite](https://sqlitebrowser.org/).

A one-time login link will be printed to the console.

### Step-debugging with XDebug

PHP step-debugging is supported via [XDebug](https://xdebug.org/docs/install).
Install the XDebug PHP extension on your host (`php -v` should mention
`with Xdebug`), then toggle it on the development server:

```bash
make debug      # restart with XDebug enabled (aliases: debug-on, xdebug, xdebug-on)
make start      # restart without XDebug (aliases: debug-off, xdebug-off)
```

The `debug` command probes the running PHP server's command line for `xdebug.mode=debug` and skips the restart if XDebug is already enabled. Code coverage stays on [pcov](https://github.com/krakjoe/pcov) because `xdebug.mode=debug` does not include `coverage`.

To start and stop debug sessions from the browser, install the Xdebug Helper extension: [Chrome](https://chromewebstore.google.com/detail/xdebug-helper-by-jetbrain/aoelhdemabeimdhedkidlnbkfhnhgnhm) / [Firefox](https://addons.mozilla.org/en-US/firefox/addon/xdebug-helper-by-jetbrains/).

## Coding standards

The `make lint` command checks the codebase using multiple tools:
- PHP code standards checking against `Drupal` and `DrupalPractice` standards.
- PHP code static analysis with PHPStan.
- PHP deprecated code analysis and auto-fixing with Drupal Rector.
- JavaScript code analysis with ESLint.
- CSS code analysis with Stylelint.
- Spell checking with CSpell.

The configuration files for these tools are located in the root of the codebase.

### Fixing coding standards issues

To fix coding standards issues automatically, run the `make lint-fix`
command. This runs the same tools as `lint` but with the `--fix` option
(for the tools that support it).

## Testing

The `make test` command runs the tests for this module.

The tests are located in the `tests/src` directory. The `phpunit.xml` file
configures PHPUnit to run the tests. It uses Drupal core's bootstrap file
`core/tests/bootstrap.php` to bootstrap the Drupal environment before running
the tests.

The `test` command is a wrapper for multiple test commands:
```bash
make test-unit                    # Run Unit tests
make test-kernel                  # Run Kernel tests
make test-functional              # Run Functional tests
```

### Running specific tests

You can run specific tests by passing a path to the test file or PHPUnit CLI
option (`--filter`, `--group`, etc.) to the `make test` command:

```bash
make test-functional tests/src/Functional/JsonapiViewsResourceTest.php
make test-functional -- --group=wip
```

You may also run tests using the `phpunit` command directly:

```bash
cd build
php -d pcov.directory=.. vendor/bin/phpunit tests/src/Functional/JsonapiViewsResourceTest.php
```

## Maintainers

- Stuart Clark - [deciphered](https://www.drupal.org/u/deciphered)

---
_The local development and CI infrastructure for this repository is provided
by the [Drupal Extension Scaffold](https://github.com/AlexSkrypnyk/drupal_extension_scaffold) project template._
