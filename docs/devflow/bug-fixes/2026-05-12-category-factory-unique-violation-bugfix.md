# Bug-Fix Report: CategoryFactory Unique Constraint Violation

**Date:** 2026-05-12
**Status:** ✅ Fixed — awaiting verification
**Reporter:** Test suite (`php artisan test`)

---

## Understanding Summary

- **Error type:** `UniqueConstraintViolationException` — SQLSTATE[23000]: Duplicate entry on `categories.categories_name_unique`
- **Affected test:** `tests/Feature/AssignedProductMovementSaleLinkingTest.php:349`
- **Affected code:** `Product::factory()->create(...)` → `CategoryFactory::new()` → `fake()->unique()->word()`
- **Steps to reproduce:** Run full test suite. After 30+ `Product::factory()->create()` calls, Faker's unique word pool (~200 words) is exhausted.
- **Expected behavior:** All tests pass.
- **Real behavior:** Within a single test, two `Product::factory()->create()` calls generate the same category name, triggering duplicate key error.

---

## Root Cause

`database/factories/CategoryFactory.php:18` used `fake()->unique()->word()`. Faker's word pool is ~200 words. When more than ~200 categories are created across the test suite (32+ `Product::factory()->create()` calls across affected test files), the `unique()` modifier exhausts its available pool, resets its internal tracking, and starts generating duplicates. Since each test runs in its own database transaction, two duplicate names within the same test trigger the unique constraint violation on `categories.categories_name_unique`.

---

## Fix Applied

**File:** `database/factories/CategoryFactory.php:18`

```diff
- 'name' => fake()->unique()->word(),
+ 'name' => fake()->word() . '_' . uniqid(),
```

Guarantees unique category names regardless of how many are created, by appending a unique ID suffix.

---

## Reproduction Test

**File:** `tests/Feature/CategoryFactoryUniqueTest.php`

Creates 300 products (and thus 300 categories) in a loop and verifies no unique constraint violation occurs.

---

## Collateral Discovery

The fix exposed a pending migration `2026_05_12_124133_add_sale_id_to_assigned_product_movements_table` that was not applied. Run `php artisan migrate` to apply it before running the full test suite.

---

## Verification

```bash
# Reproduction test
./vendor/bin/pest tests/Feature/CategoryFactoryUniqueTest.php

# Original failing test
./vendor/bin/pest tests/Feature/AssignedProductMovementSaleLinkingTest.php --filter="creates SaleDetail for normal products"

# Full suite
php artisan test
```
