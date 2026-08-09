# AGENTS.md

This file provides guidance to AI agents when working with code in this repository.

## Overview

JSON:API Views is a Drupal module that exposes a JSON:API resource for each
Views display, so a view's results (filters, pagination, sorts included) can
be consumed outside of Drupal without hand-writing a custom resource. See
[README.md](README.md) for the full feature summary.

## Development Commands

**HARD RULE - use the provided command wrappers, never the tool binaries directly.** When `make` exposes a command for a task, use that command; do not call the underlying binary directly. Each wrapper `chdir`s into `build/` and runs the tool with the config, plugins, and environment that CI uses, so a raw invocation from the repository root silently diverges from CI - it can pass locally while CI fails (or vice versa), or crash outright when a relative path resolves against the wrong directory. If no wrapped command covers what you need, extend the `make` target rather than making a one-off raw call; if that is not feasible, stop and ask.

Run each tool through its `make` wrapper, never the binary directly:

- **PHPCS / PHPCBF**: `make lint` / `make lint-fix` - never `vendor/bin/phpcs` or `vendor/bin/phpcbf`.
- **PHPStan**: `make lint` - never `vendor/bin/phpstan`.
- **Rector**: `make lint` (dry-run) / `make lint-fix` - never `vendor/bin/rector`.
- **ESLint / Stylelint**: `make lint` / `make lint-fix` - never `npx eslint` or `npx stylelint`.
- **CSpell**: `make lint` - never `npx cspell`.
- **PHPUnit**: `make test` / `make test-unit` / `make test-kernel` / `make test-functional` - never `vendor/bin/phpunit`.
- **Drush**: `make drush <command>` - never `build/vendor/bin/drush` directly.

### Build and Environment Management

- `make build` - Complete build (stop → assemble → start → provision)
- `make assemble` - Assemble codebase with dependencies
- `make start` - Start PHP development server
- `make stop` - Stop development server
- `make provision` - Install/provision Drupal site
- `make reset` - Clean build directory and logs (aliases: `make delete`, `make destroy`)

### Code Quality

**Linting:**
- `make lint` - Run all linting tools
- `make lint-fix` - Auto-fix coding standards violations

**Testing:**
- `make test` - Run all tests
- `make test-unit` - Run unit tests only
- `make test-kernel` - Run kernel tests only
- `make test-functional` - Run functional tests only (includes `JsonapiViewsResourceTest`)
- `make test-functional-javascript` - Run FunctionalJavascript tests (requires Selenium)

### Drupal Commands

- `make drush <command>` - Run Drush commands
- `make login` - Get one-time login link

### Diagnostics

- `make info` - Print a read-only summary of PHP/Drupal/Composer/Drush/Node versions, webserver host/port (with source), XDebug state, build directory, database path, and active profile. (alias: `make describe`)

## Project Structure

**Key Directories:**

- `src/Routing/Routes.php` - Builds the dynamic JSON:API route for each
  exposed view display.
- `src/Resource/ViewsResource.php` - The JSON:API resource controller:
  executes the view, applies pagination/filter/sort query args, and returns
  a cacheable JSON:API response.
- `src/Plugin/views/display_extender/JsonapiViews.php` - Views display
  extender plugin; adds the "Expose via JSON:API" checkbox to a display's
  settings.
- `tests/src/Functional/JsonapiViewsResourceTest.php` - Functional coverage
  for the resource (pagination, filters, sorts, cache contexts, access).
- `tests/modules/jsonapi_views_test/` - Fixture module (content types,
  fields, a test view) used by the functional test.
- `config/schema/jsonapi_views.views.schema.yml` - Config schema for the
  display extender's `enabled` option.
- `build/` - Assembled Drupal codebase (gitignored, symlinked module).
- `.devtools/` - Build and deployment scripts used by CI.
- `scripts/` - Custom post-assemble (`assemble-*.sh`), post-start
  (`start-*.sh`) and post-provision (`provision-*.sh`) hooks, run
  automatically at the end of each phase in lexicographic order; non-zero
  exit aborts the parent. Excluded from distribution archives via
  `.gitattributes`.

## Architecture

- **Dynamic routing**: `Routes::routes()` iterates enabled View displays and
  generates one JSON:API route per display that has the `jsonapi_views`
  display extender enabled.
- **Resource type route default**: each route always carries the plural
  `_jsonapi_resource_types` default (every bundle name the view's entity
  type has). It carries the singular `resource_type` default only when
  the entity type has exactly one bundle. Tools such as the OpenAPI
  module's JSON:API discovery read `resource_type` to describe a route.
  A view with several bundles has no single correct resource type. Its
  route leaves `resource_type` unset.
- **Display extender opt-out**: exposure is per-display, not per-view - each
  display's "Expose via JSON:API" checkbox is stored via
  `JsonapiViews::defineOptions()`/`submitOptionsForm()` and read back via
  `JsonapiViews::isExposed()`.
- **Resource execution**: `ViewsResource::process()` (dependency-injected
  `PagerManagerInterface`/`RendererInterface`) executes the view, renders it
  in a render context to capture bubbled cacheability, and maps result rows
  to JSON:API entities with pagination links.

## Environment Variables

- `DRUPAL_VERSION` - Target Drupal version (e.g., `10`, `11`, `11@alpha`)
- `WEBSERVER_HOST` - Development server host (default: localhost)
- `WEBSERVER_PORT` - Development server port. Auto-discovered from range 8000-8099 and written to `.env` if not already set
- `GITHUB_TOKEN` - GitHub API token to avoid rate limits

## Development Workflow

1. Build environment: `make build`
2. Develop JSON:API Views code in `src/`
3. Check standards: `make lint`
4. Run tests: `make test`
5. Access site at http://localhost:8000

## Code Quality Tools

- **CSpell**: Spell checking across the codebase (config at `.cspell.json`)
- **PHPCS**: Drupal and DrupalPractice standards
- **PHPStan**: Static analysis with Drupal extensions
- **Rector**: Automated refactoring and deprecation fixes

## CI/CD Support

- **Drupal.org GitLab CI**: `.gitlab-ci.yml`, using Drupal Association's
  shared `gitlab_templates` pipeline.
- **GitHub Actions**: `.github/workflows/test.yml` (lint + version matrix)
  and `.github/workflows/deploy.yml` (mirrors to Drupal.org on release,
  gated by a `DEPLOY_PROCEED` secret).
- **Matrix testing**: PHP 8.2-8.5, Drupal 10-11.

## Important Notes

- The `build/` directory contains the assembled Drupal site
- Module files are symlinked from root into `build/web/modules/custom/`
- SQLite database created in `/tmp/site_jsonapi_views.sqlite`
- All quality tools run from within `build/` directory

## Updating the scaffold

When the user asks to update this project's scaffold (e.g. "update scaffold"), fetch the update skill from GitHub into the local `.claude/skills/` directory, then invoke it:

1. Create the target directory if it does not exist:

   ```bash
   mkdir -p .claude/skills/update-consumer-drupal-extension-scaffold
   ```

2. Download the skill:

   ```bash
   curl -sSL https://raw.githubusercontent.com/AlexSkrypnyk/drupal_extension_scaffold/1.x/.scaffold/skills/update-consumer-drupal-extension-scaffold/SKILL.md -o .claude/skills/update-consumer-drupal-extension-scaffold/SKILL.md
   ```

3. Invoke the `update-consumer-drupal-extension-scaffold` skill and follow its steps.

The skill directory is git-ignored - it is fetched on demand and not committed to the project.
