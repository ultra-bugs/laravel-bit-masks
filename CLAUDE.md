# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`zuko/laravel-bit-masks` — a bitmask toolkit for Laravel (PHP 8.2+, Laravel 11–13): flag class generator (`make:bitmask`), Eloquent integration, fluent query scopes, and bit-level value objects. The README.md is the full API reference; keep it (and its Vietnamese mirror `README.vi.md`) in sync when behavior changes.

## Commands

### Standalone development (this repo checked out on its own)

```bash
composer install          # installs illuminate/* + phpunit into the package's own vendor/
composer test             # runs the whole suite (= vendor/bin/phpunit)

# Single file / single test:
vendor/bin/phpunit tests/BitMaskTest.php
vendor/bin/phpunit --filter it_casts_mask_columns_to_bitmask_instances tests/HasBitMasksTest.php
```

There is no linter or build step configured in this repo.

### Inside a host Laravel application (e.g. `7pvd/fitly`)

This package is embedded in host apps at `packages/zuko/laravel-bit-masks` as a **git submodule**, and picked up by the host's `wikimedia/composer-merge-plugin` (`packages/*/*/composer.json` in the host `composer.json`). The host's phpunit.xml declares a `BitMasks` testsuite pointing at the package's `tests/` directory. From the **host app root**:

```bash
git submodule update --init packages/zuko/laravel-bit-masks   # after fresh clone
composer install                                              # host vendor/ serves the package too

vendor/bin/phpunit --testsuite BitMasks                       # package suite via host phpunit
vendor/bin/phpunit --testsuite BitMasks --filter it_casts_mask_columns_to_bitmask_instances
```

Both modes work because of two deliberate design decisions — preserve them when touching tests:

1. `tests/bootstrap.php` tries the package's own `vendor/autoload.php` first, then falls back to `../../../../vendor/autoload.php` (the host's autoloader when the package sits at `packages/zuko/laravel-bit-masks`).
2. Tests do **not** use Orchestra Testbench or a Laravel app container. `tests/TestCase.php` statically calls `BitMasksServiceProvider::registerCollectionMacros()` / `registerBlueprintMacros()` (they are public statics precisely so no container is needed), and Eloquent tests boot their own `Illuminate\Database\Capsule\Manager` with in-memory SQLite in `setUp()`.

The suite must keep passing under both the package's own phpunit (`^11.5|^12|^13`) and whatever the host app pins.

## Architecture

Three storage strategies, one API. A model declares mask attributes in `$bitMasks`, and the declaration shape selects the strategy:

```php
protected $bitMasks = [
    'networks' => Network::class,                                  // single BIGINT column → BitMask
    'toggles',                                                     // single column, no enum bound
    'wide'     => ['columns' => 2, 'enum' => Network::class],      // N BIGINT columns → WideBitMask
    'tags'     => ['pivot' => 'email_tags', 'enum' => Tag::class], // junction table → FlagSet
];
```

Whatever the strategy, the model surface is identical: attribute access returns the value object, `hasMask/addMask/removeMask/toggleMask/setMask/clearMask` mutate in memory, `->save()` persists, and the `whereMaskHas/whereMaskHasAny/whereMaskMissing/whereMaskEquals` scopes (plus `orWhere*` twins) query it.

### Core pieces

- **`src/Concerns/HasBitMasks.php`** — the heart of the package. Parses `$bitMasks` once per class into three buckets (`single` / `wide` / `pivot`, cached in `static::$bitMaskDefinitions`), auto-registers casts in `initializeHasBitMasks()`, dispatches every helper and scope per strategy. Scopes emit portable bitwise SQL (`(col & m) = m` etc.) for single columns, grouped per-column predicates for wide masks, and correlated `EXISTS`/`NOT EXISTS` subqueries for pivots. It overrides `save()`/`delete()` (rather than model events) to flush buffered pivot rows / purge junction rows — this keeps the contract correct under `saveQuietly()`.
- **`src/BitMask.php`** — immutable value object over one integer mask (63 usable bits; bit 63 is the sign bit — `BitMask::MAX_BIT`). `BitMask::resolve(mixed): int` is the universal normalizer every entry point funnels through: it accepts ints, int-backed enum cases, `BitMask` instances, and (nested) iterables of those.
- **`src/WideBitMask.php` + `src/WideMaskDefinition.php`** — one logical mask spanning N BIGINT columns (`name_1..name_N`). Flags are addressed by **global index** (`0..N*63-1`), not bit value: index `n` routes to column `n / 63`, bit `n % 63`. Mirrors the `BitMask` API.
- **`src/FlagSet.php` + `src/PivotDefinition.php`** — flag membership as `(owner, flag_id)` junction-table rows; flag ids are arbitrary non-negative enum values, not bit positions. Mutations buffer on the model and flush on `->save()`.
- **`src/Casts/AsBitMask.php`** — the Eloquent cast (`AsBitMask::class` plain, `AsBitMask::using(Enum::class)` bound). Usable standalone without the trait.
- **`src/Concerns/BitMaskFlags.php`** — trait adopted by flag enums: `Enum::mask(...)`, `none()`, `all()`, `fromMask()`, `fromName()/tryFromName()/valueOf()`, `$case->in()/notIn()`.
- **`src/Support/FlagName.php`** — resolves flag NAMES (strings) to cases/values with forgiving matching (case-insensitive, separators ignored: `'yahoo mail'` → `YahooMail`); understands flag enums and `--type=constants` classes. Every `resolve`-style entry point (BitMask, WideMaskDefinition, FlagSet, cast, scopes, macros) accepts names whenever an enum is bound — numeric strings always stay numeric values.
- **`src/BitMasksServiceProvider.php`** — registers the `make:bitmask` command, `Collection` macros (`whereMask*` in-memory twins of the query scopes, working on Eloquent and plain collections via `data_get`), and `Blueprint` macros (`bitMask`, `wideBitMask`, `flagPivot`). Macro registrars are public statics so tests can call them containerless.
- **`src/Console/MakeBitMaskCommand.php` + `src/Support/BitMaskClassBuilder.php`** — the generator. The builder normalizes arbitrary names into identifiers, rejects duplicates and >63-bit overflow, and renders either an int-backed enum (default) or a constants class. Defaults for `--namespace`/`--path`/`--type` come from `config/bit-masks.php` (`generator.*`, merged in `register()`, publishable via tag `bit-masks-config`); the builder itself stays config-free and pure. The command supports `--module=<Name>` for nwidart/laravel-modules: it reads `modules.namespace` and `modules.paths.app_folder` from the nwidart config to derive the module-local namespace and path (soft dependency — nwidart is only required when `--module` is actually used).
- **`src/helpers.php`** — global `bitmask()` and `bitmask_value()` helpers (autoloaded via composer `files`).

### Conventions

- Everything flag-ish goes through `BitMask::resolve()` — never hand-roll int coercion for flag input.
- Value objects (`BitMask`/`WideBitMask`/`FlagSet`) are immutable: mutations return new instances. Only the model helpers mutate state, and only in memory until `->save()`.
- Respect the 63-bits-per-column limit everywhere (generator validation, wide-mask routing, blueprint docs).
- Source files carry the project's ASCII-art copyright header — keep it on new files.
