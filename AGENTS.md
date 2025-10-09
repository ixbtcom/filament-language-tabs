# Repository Guidelines

## Project Structure & Module Organization
- `src/` holds the Filament service provider and `Forms/Components/LanguageTabs.php`; use this directory for runtime code.
- `resources/views/` delivers the Blade views rendered by the component, while `resources/views/tests` contains fixtures used in integration tests.
- `config/filament-language-tabs.php` exposes default and required locale settings; mirror its keys when publishing config to host apps.
- `tests/` is a Pest suite with architecture checks (`Architecture/`), reusable form fixtures (`Fixtures/`), and feature coverage (`LanguageTabsTest.php`); keep new tests close to their feature area.

## Build, Test, and Development Commands
- `composer test` runs the default CI stack (Pest in parallel followed by PHPStan) and should be green before every push.
- `composer test:pest` executes only the Pest suite; prefer this during TDD loops.
- `composer test:phpstan` inspects static analysis rules configured in `phpstan.neon.dist`.
- `composer pint` formats PHP files to the shared Laravel Pint profile; run it before committing style-heavy changes.

## Coding Style & Naming Conventions
- Adhere to PSR-12 with four-space indentation; Pint enforces brace placement and spacing automatically.
- Use descriptive class names under the `Pixelpeter\FilamentLanguageTabs` namespace, mirroring directory structure (e.g., `Forms/Components/*`).
- Name view files with kebab-case Blade conventions (e.g., `language-tabs.blade.php`) and translation arrays by locale code.
- Follow Filament’s component method naming (e.g., `setUp`, `make`) and prefer early returns for guard clauses.

## Testing Guidelines
- Write Pest tests with descriptive `it()` blocks; keep helpers in `tests/Fixtures` or `tests/Pest.php`.
- Type coverage is enforced via `pest-plugin-type-coverage`; add typed properties or return types when extending features.
- Architecture rules in `tests/Architecture/ArchitectureTest.php` guard namespace boundaries—update them if you add new top-level folders.
- Run `composer test` before opening a PR and document any intentionally skipped checks.

## Commit & Pull Request Guidelines
- Craft concise, imperative commit messages; optional prefixes (`feature:`, `fix:`) reflect the existing history.
- Group related functional and test changes together, and include formatted output from `composer test` when pushing branches.
- Pull requests should link to tracked issues, describe the change, list manual verification steps, and attach screenshots or GIFs when UI output changes.
