# Codex instructions for this repository

## Default mode

Work in patch-only mode.

Make the smallest code change that satisfies the request.
Do not refactor unrelated code.
Do not rename files.
Do not reorganize folders.
Do not change public behavior outside the requested scope.

Manual verification is performed by the repository owner.

## Allowed commands

You may use static inspection commands only:

- `rg`
- `grep`
- `find`
- `ls`
- `cat`
- `sed`
- `git status`
- `git diff`

## Forbidden commands

Never run these commands unless the user explicitly requests them in the current task:

- `composer`
- `composer install`
- `composer update`
- `composer dump-autoload`
- `composer run-script`
- `npm`
- `npm ci`
- `npm install`
- `npm run`
- `php artisan`
- `./vendor/bin/*`
- `vendor/bin/*`
- `./node_modules/.bin/*`
- `node_modules/.bin/*`

Do not run tests, linters, formatters, build commands, migrations, cache clear commands, package discovery, framework discovery, or asset publishing.

Do not install dependencies even if `vendor/` or `node_modules/` is missing.
Do not try to fix missing dependencies.
Do not verify changes by executing Laravel, Composer, npm, Pint, PHPUnit, Pest, PHPStan, Larastan, Vite, or Filament commands.

If a verification command cannot be run under these rules, skip it and report that it was skipped.

## Laravel rules

When modifying Laravel jobs, services, controllers, Filament pages, resources, models, or migrations:

- Check migrations and models before assuming a column is nullable or non-null.
- Validate nullable configuration fields before using them in provider calls, API calls, queue jobs, or persistence.
- If an optional setting is incomplete, fail safely with a domain status or fallback reason instead of throwing runtime errors.
- Do not create records that violate database constraints.
- Preserve tenant isolation in every query and mutation.

## WhatsApp / Meta rules

- Never call external providers synchronously from Meta webhook controllers.
- Do not send WhatsApp messages from AI suggested-reply flows unless the roadmap phase explicitly allows it.

## Final response format

At the end of each task, report:

1. Files changed.
2. Summary of changes.
3. Commands skipped because of these rules.
4. Risks or assumptions.
