# Test Registry — Daily Reconciliation Improvement

| Test File | Test Name | Status | Task |
|-----------|-----------|--------|------|
| `tests/Feature/DailySalesReconciliationProductShortageTest.php` | it blocks closure when remaining products exist and no type price is selected | ✅ PASS | Task 1 |
| `tests/Feature/DailySalesReconciliationProductShortageTest.php` | it allows closure when remaining products exist and a type price is selected | ✅ PASS | Task 1 |
| `tests/Feature/DailySalesReconciliationProductShortageTest.php` | it allows closure when no remaining products exist and no type price is selected | ✅ PASS | Task 1 |
| `tests/Feature/DailySalesReconciliationProductShortageTest.php` | it renders incremental row numbers in the sales table instead of sale ids | ✅ PASS | Task 2 |

> All tests executed with `php artisan test tests/Feature/DailySalesReconciliationProductShortageTest.php`.
> Latest run: 12 passed (31 assertions) after addressing WARN findings.
