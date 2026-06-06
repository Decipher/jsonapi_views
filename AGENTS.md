# AGENTS.md

Contribution workspace for jsonapi_views (Drupal module).

## Overview
Provides a JSON:API resource for each view display using the JSON:API Resources module.

## Commands
- `make build` / `make test` — build and test with `DRUPAL_VERSION=10` or `11`
- `make lint` — run all linting (phpcs, phpstan, rector, twig-cs-fixer)
- `make lint-fix` — auto-fix coding standards
- `make info` — show environment info

## Key Source Files
- `src/Routing/Routes.php` — Route callback; known perf issue (N+1 queries per bundle)
- `src/Resource/ViewsResource.php` — JSON:API resource plugin
- `src/Plugin/views/display_extender/JsonapiViews.php` — Views display extender
- `config/schema/jsonapi_views.views.schema.yml` — config schema

## Tests
- `tests/src/Functional/JsonapiViewsResourceTest.php` — functional test
- `tests/modules/jsonapi_views_test/` — test helper module

## Known Issues
- **#3580102**: `Routes::routes()` calls `ResourceTypeRepository::get()` per bundle per view, causing slow route rebuilds. Fix: cache per entity type, same approach as #3484714.

## CI
- GitHub Actions: `.github/workflows/test.yml` — matrix (PHP 8.2-8.5, D10-D11)
- Branch: `8.x-1.x`

## Drupal.org
- **Project**: https://www.drupal.org/project/jsonapi_views
- **Issues**: https://www.drupal.org/project/issues/jsonapi_views
- **Repo**: https://git.drupalcode.org/project/jsonapi_views
- **Maintainer**: deciphered (UID 103796)

## Updating scaffold
Fetch upstream skill and follow its steps:
```bash
curl -sSL https://raw.githubusercontent.com/AlexSkrypnyk/drupal_extension_scaffold/1.x/.scaffold/skills/update-consumer-drupal-extension-scaffold/SKILL.md -o .claude/skills/update-consumer-drupal-extension-scaffold/SKILL.md
```
