# Debug Log: AssignedProductMovement Sale Linking — Test Failures

**Date:** 2026-05-12
**Slug:** assigned-product-movement-sale-linking
**Attempts:** 3

## Root Cause Summary

3 categories of failures, all in test infrastructure (not production code):

| # | Error | Root Cause | Fix |
|---|-------|-----------|-----|
| 1 | `Call to a member function connection() on null` | Unit tests instanciaban `new Sale()` y `new AssignedProductMovement()` sin Laravel booteado | Eliminado archivo de test unitario |
| 2 | `Field 'employee_id' doesn't have a default value` | `UserFactory` no incluía `employee_id` (requerido en tabla `users`) | Agregado `employee_id => EmployeeFactory::new()` a UserFactory + factories encadenados (Branch, Employee) |
| 3 | `Field 'address' / 'phone_number' doesn't have a default value` | Factories incompletos: `BranchFactory`, `EmployeeFactory`, `ProductFactory`, `ClientFactory` no incluían todos los campos requeridos por las migraciones | Completados todos los factories con campos requeridos |
| 4 | `Column 'type_price_id' cannot be null` | `SaleDetail` requiere `type_price_id` (FK not null); el test no lo incluía | Agregado `type_price_id` al productData del test |
| 5 | FK constraint fails en `type_price_id` con transacciones anidadas | `TypePrice::factory()->create()` + transacción anidada de `SaleService` causaba que la FK no viera el registro en ciertos casos con `DatabaseTransactions` | Usado `DB::table('types_prices')->insertGetId()` en vez de Eloquent ORM |

## Files Fixed
- `tests/Unit/AssignedProductMovementModelTest.php` — eliminado
- `database/factories/UserFactory.php` — agregado `employee_id`
- `database/factories/EmployeeFactory.php` — campos completos
- `database/factories/BranchFactory.php` — creado + campos completos
- `database/factories/CategoryFactory.php` — creado
- `database/factories/ProductFactory.php` — campos completos
- `database/factories/ClientFactory.php` — campos completos
- `database/factories/SaleFactory.php` — `created_by`/`updated_by` desde UserFactory
- `database/factories/TypePriceFactory.php` — creado
- `tests/Feature/AssignedProductMovementSaleLinkingTest.php` — removido `use Exception;`, cambiado a `DatabaseTransactions`, datos de test completos
