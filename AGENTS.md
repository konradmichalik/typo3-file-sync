# AGENTS.md

## Project overview

TYPO3 extension (`typo3_file_sync`) that synchronizes missing files on demand, either by fetching them from a remote TYPO3 instance or by generating local placeholder images. Typical use: staging or local systems refreshed from production without copying the full file storage.

- Package: `konradmichalik/typo3-file-sync`, namespace `KonradMichalik\Typo3FileSync` (PSR-4, `Classes/`)
- Requirements: PHP 8.2 to 8.5, TYPO3 13.4 and 14.x, `ext-gd`
- Conflicts with `ichhabrecht/filefill`

## Structure

- `Classes/Resource/` resource interfaces and collection, plus `Driver/` (`FileSyncDriver` storage driver), `Handler/` (remote instance and placeholder image resources) and `Preview/` (preview generation and storage)
- `Classes/Middleware/` PSR-15 middlewares for deferred image loading and materialization
- `Classes/Service/` materialization, rate limiting, preview, public URL and storage services
- `Classes/Command/` CLI commands to reset missing-file flags or delete synced files
- `Classes/Controller/`, `Classes/EventListener/`, `Classes/Form/`, `Classes/Repository/`, `Classes/Exception/`
- `Configuration/` `Commands.php`, `RequestMiddlewares.php`, `ContentSecurityPolicies.php`, `JavaScriptModules.php`, `Services.yaml`, `TCA/`
- `Resources/` templates, language files, JavaScript and assets
- `Tests/Unit/` and `Tests/Functional/` PHPUnit tests, mirror `Classes/`
- `Tests/CGL/` separate Composer project with code style, static analysis and migration tooling
- `docs/` user documentation (CLI commands, configuration, resource handlers, deferred image loading)
- `.ddev/` DDEV setup, including a fake remote server and commands to install TYPO3 13 and 14 test instances

## Development commands

The project uses DDEV. Prefix commands with `ddev`.

```bash
ddev start
ddev composer install
ddev install all      # or: ddev install 13
ddev 13 typo3 cache:flush
ddev reset-sync       # DDEV helper commands for the sync setup
ddev prepare-test
ddev stage-remote
```

Lint, fix, static analysis and migration run through `ddev cgl`, which executes the scripts of `Tests/CGL/composer.json`:

```bash
ddev cgl lint       # composer, editorconfig, php
ddev cgl fix        # composer, editorconfig, php
ddev cgl sca        # PHPStan
ddev cgl migration  # Rector
```

Single linters: `ddev cgl lint:composer`, `lint:editorconfig`, `lint:php`. Matching fixers: `fix:composer`, `fix:editorconfig`, `fix:php`.

## Testing

PHPUnit with unit tests (`phpunit.xml`) and functional tests (`phpunit.functional.xml`, uses a real TYPO3 instance and database via `typo3/testing-framework`).

```bash
ddev composer test              # unit and functional, no coverage
ddev composer test:unit
ddev composer test:functional
ddev composer test:coverage     # both suites with XDEBUG_MODE=coverage, merged via phpcov into .Build/coverage/
ddev exec vendor/bin/phpunit --filter testMethodName
```

CI runs the shared `tests-typo3` workflow on TYPO3 13.4 and 14.3, PHP 8.2 to 8.5, with `highest` and `lowest` dependencies. The CGL workflow runs the linters on every push.

## Code style and static analysis

- PHP CS Fixer with `konradmichalik/php-cs-fixer-preset`, config in `Tests/CGL/.php-cs-fixer.php`. The fixer generates the license header, run `ddev cgl fix:php`
- `declare(strict_types=1);` in every PHP file
- PHPStan level 8 with baseline, config in `Tests/CGL/phpstan.neon`
- Rector config in `Tests/CGL/rector.php`
- `composer-dependency-analyser` and `composer-require-checker` check dependencies
- EditorConfig is enforced via `.editorconfig`

## Git workflow

- Commit format: `<type>: <description>` with type one of `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`
- Single-line messages, no co-author trailers
- One commit per logical change, open a pull request against `main`
