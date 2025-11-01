# Copilot Instructions for Contributors

These instructions describe how to contribute to the `laravel-lockout` package, how the project is structured, the development approach I expect you to follow, testing requirements, and the mandatory process for documenting any change. This file is the living source of developer expectations and must be updated for every contribution that affects workflow, tests, docs, or code structure.

---

## Project context (what this project does and structure)
- Purpose: `laravel-lockout` is a Laravel package that implements account lockout logic (lock accounts after repeated failed login attempts), creates lockout logs, provides middleware to block login attempts, and offers a `HasLockout` trait for Eloquent models.
- Core responsibilities:
  - Check if a user (or model) is locked out before allowing authentication.
  - Create `lockout_logs` entries when locks occur (optionally associated to an Eloquent model).
  - Provide helpers to lock/unlock programmatically.
- Key files and directories:
  - `src/Http/Middleware/EnsureUserIsNotLocked.php` — middleware that prevents locked users from logging in.
  - `src/Traits/HasLockout.php` — trait providing helpers like `isLockedOut()`, `lock()`, `unlock()`, `lockouts()` and `activeLock()`; backed by the `model_lockouts` polymorphic table for persistent locks and history.
  - `src/Lockout.php` (service) — core locking/unlocking logic, log creation and coordination with persistent lock records.
  - `src/Models/LockoutLog.php` — the audit log model, including a `morphTo` relation to linked models.
  - `src/Models/ModelLockout.php` — new model representing persistent lock records for any Eloquent model (polymorphic).
  - `database/migrations/*_create_lockout_logs_table.php` — migration stub creating the `lockout_logs` audit table.
  - `database/migrations/*_create_model_lockouts_table.php` — migration stub creating the `model_lockouts` table used for persistent locks and history.
  - `tests/` — Unit and Feature tests (Pest or PHPUnit with Orchestra/Testbench for package integration).
  - `.github/workflows/ci.yml` — CI pipeline; ensures tests, static analysis and compatibility across PHP/Laravel/Testbench versions.
  - `phpstan.neon.dist` — phpstan configuration (use sparingly and justify additions).

---

## Development mode (how to work)
- Style and conventions:
  - Follow Laravel and PSR-12 conventions.
  - Prefer explicit type hints and return types where appropriate.
  - Write clear, small, single-responsibility methods. If a function is growing complex, extract a private helper and add tests.
  - Use docblocks on public APIs and add `@property` annotations for Eloquent model dynamic properties if needed for static analysis.
- Be pragmatic about refactors:
  - You may refactor to remove duplication or clarify responsibilities, but keep changes small and focused.
  - Always include tests that assert the behavior before or alongside refactors.
  - If a static analysis rule produces noise and a mass-change would be required, prefer minimal, targeted fixes and, only where justified, narrowly-scoped phpstan exceptions with comments.
- Collaboration:
  - When you modify behavior, update docs and tests in the same branch/PR.
  - Keep commits focused and well-described. The PR description should explain the intent, scope, and any developer-facing changes.

---

## Testing (mandatory)
- Everything you change must be tested. No exceptions.
  - New features: add Feature tests (integration) or Unit tests as appropriate.
  - Bug fixes: include a regression test demonstrating the bug and the fix.
- Test framework and environment:
  - Use the repository's test runner (Pest preferred if present; otherwise PHPUnit).
  - Use Orchestra/Testbench for Laravel integration tests.
  - Use an in-memory SQLite database for fast tests unless a specific database is required.
- Test placement and naming:
  - Put tests under `tests/Feature` or `tests/Unit` following existing conventions.
  - Prefer descriptive test names and assertions that validate public behavior, not implementation details.
- Example test scenario you must include when changing lockout logging:
  - Create a dummy model that uses `HasLockout`.
  - Trigger a lock via the `Lockout` service or trait.
  - Assert that a `lockout_logs` record exists with `model_type` and `model_id` populated.
  - Assert the `HasLockout::lockoutLogs()` relation returns the created log.
- Run tests locally before pushing:
  - `composer test` or `vendor/bin/pest`
  - Run a single test file: `vendor/bin/pest tests/Feature/LockoutModelAssociationTest.php`

---

## Documentation & traceability (must do on every change)
For any public feature, migration, config change, or behavior modification you must:
1. Update `README.md` with usage examples, configuration options, and default environment values.
   - Document ENV variables from `config/lockout.php` in a table with default values and descriptions.
2. If the database schema changes (including adding `model_type`/`model_id`), add a migration and include a migration note in the README instructing users how to update published migrations (e.g., add `nullableMorphs('model')`).
3. Add tests that verify the new behavior.
4. Update this file: `.github/copilot-istructions.md` with a short summary of the change, why it was made, and where the tests & docs live.
   - This file acts as the canonical contributor guidance and must always reflect the current workflow and known gotchas.

---

## CI and static analysis
- CI should run:
  - All tests (Pest/PHPUnit).
  - Static analysis: `vendor/bin/phpstan analyse` using `phpstan.neon.dist`.
  - Any linters or formatters configured in the repo.
- Compatibility:
  - Keep CI’s dependency matrix aligned so that Testbench, PHPUnit, and Pest are version-compatible for each Laravel version target. If you change composer requirements for tests, update the CI workflow to install the matching versions.
- If CI or phpstan flags an issue:
  - Try to fix the root cause in code and tests.
  - If you must add an exception to `phpstan.neon.dist`, make the exception as narrow as possible and document the reason right above the change with a comment.

---

## PR checklist (must be satisfied before merging)
- [ ] Tests added/updated and pass locally.
- [ ] README updated with examples and ENV documentation if behavior/config changed.
- [ ] Migrations provided if DB schema changes; a README migration note exists for users with published migrations.
- [ ] Static analysis (`phpstan`) passes or a narrowly-justified exception is included.
- [ ] CI workflow updated if tool or dependency versions changed.
- [ ] This instruction file (`.github/copilot-istructions.md`) updated to record the change and any new developer-facing steps.
- [ ] Clear PR description, including links to relevant tests and issues.

---

## Common files you will edit
- `config/lockout.php`
- `src/Http/Middleware/EnsureUserIsNotLocked.php`
- `src/Traits/HasLockout.php`
- `src/Lockout.php` (service)
- `src/Models/LockoutLog.php`
- `database/migrations/*_create_lockout_logs_table.php`
- `tests/Feature/*` and `tests/Unit/*`
- `phpstan.neon.dist`
- `.github/workflows/ci.yml`
- `README.md`
- `.github/copilot-istructions.md` (this file)

Use quoted file names exactly as above (backticks when referenced in code or docs).

---

## Working with migrations & model associations
- Recommended approach (current):
  - The package now uses a dedicated polymorphic `model_lockouts` table to store persistent locks and their history. This is the preferred, non-invasive approach for persistent lock state.
  - The `model_lockouts` migration stub creates the following useful columns: `model_type`, `model_id`, `locked_at`, `unlocked_at`, `expires_at`, `reason`, `meta`, plus timestamps and appropriate indexes.
  - Use the `HasLockout` trait (or the `lockouts()` relation) to create, query and clear locks. The trait exposes `lock()`, `unlock()`, `activeLock()` and `isLockedOut()` helpers to operate against `model_lockouts`.
- Backwards compatibility:
  - If your application previously relied on a `locked_at` column on the auth table, migrate those values into the polymorphic `model_lockouts` table and remove the legacy column. New integrations should implement persistent locks using the `model_lockouts` table (preferred, non-invasive).
  - Recommended migration approach: add a migration that copies existing `locked_at` values into `model_lockouts` records for the corresponding model rows, verify consumers have switched to `model_lockouts`, and then drop the legacy `locked_at` column. Document these steps in your application's migration notes or README.
- Tests (must include):
  - Unit/feature tests that verify creation of `model_lockouts` records when threshold is reached (via the Lockout service).
  - Tests that assert `HasLockout::isLockedOut()` returns true when an active `model_lockouts` record exists and false otherwise.
  - Tests that verify `HasLockout::lock()` creates an active `model_lockouts` record, and `unlock()` marks it unlocked (`unlocked_at` populated).
  - Tests that ensure `lockout_logs` audit entries are still created for each failed attempt and that logs can optionally be associated to the model (morph).
- Migration notes:
  - The `model_lockouts` table is polymorphic and therefore works with any model. It is indexed on `model_type, model_id` for efficient lookups.
  - When publishing and running migrations in consumer apps, recommend publishing the config (if you need to adjust `model_table` or other options) before publishing migrations. Document any manual steps in the README.

---

## When to modify phpstan settings
- Try to resolve issues by improving typing, adding docblocks, or refactoring.
- If unavoidable, add a minimally scoped ignore in `phpstan.neon.dist` with a comment that explains the reason and references the PR number or issue.
- Avoid global suppressions.

---

## Updating these instructions (MANDATORY)
- Every time you change developer workflow, test strategy, CI, file layout, or add/remove key features, update this file.
  - Add a short summary of the change, why it was made, and the files impacted.
  - List new commands or steps contributors must run locally or in CI.
  - Link to the tests and README sections that verify/describe the change.
- This file is the living developer handbook for the repository and must be current prior to merging related PRs.

---

## Example commands (local dev)
- Run tests: `composer test` or `vendor/bin/pest`
- Run phpstan: `vendor/bin/phpstan analyse`
- Run a single test file: `vendor/bin/pest tests/Feature/YourTest.php`
- Run PHPUnit directly (if used): `vendor/bin/phpunit`

---

## Final notes from me
- I expect you to run tests and static analysis locally before opening a PR.
- I will review PRs looking for focused commits, tests that demonstrate behavior, clear README updates, and that this file (`.github/copilot-istructions.md`) has been updated when required.
- If you want, on your first change you can add a small entry at the bottom of this file summarizing the change so future contributors can quickly understand why the update was made.

---
