# Implementation Plan: Delete Client Visit

## 1. Test Design (tests/Feature/ClientVisitDeleteTest.php)
We will use PestPHP to create the following tests:
- [ ] Test 1: Successful deletion (Visit exists today).
- [ ] Test 2: 404 Error (Visit exists but on a different date).
- [ ] Test 3: 404 Error (No visit exists at all for the client).
- [ ] Test 4: Auth Protection (Fails without Sanctum token).

## 2. Tasks
### Task 1: Create Test Suite
- **File:** `tests/Feature/ClientVisitDeleteTest.php`
- **Command:** Create the file and add Pest tests using `RefreshDatabase` trait.

### Task 2: Register Route
- **File:** `routes/api.php`
- **Action:** Add `Route::delete('/{client_id}/visit', [ClientVisitController::class, 'deleteVisit']);` inside the `clients` group.

### Task 3: Implement Controller Method
- **File:** `app/Http/Controllers/ClientVisitController.php`
- **Method:** `deleteVisit(Request $request, $client_id)`
- **Logic:**
    - Find client or fail.
    - Find visit for `$client_id` and `Carbon::now()->toDateString()`.
    - Delete if found.
    - Return success or error response using `ApiResponse` trait.

### Task 4: Verify
- **Command:** `php artisan test --filter ClientVisitDeleteTest`

## 3. Commit Messages
- `feat: add DELETE route for client visits`
- `feat: implement deleteVisit in ClientVisitController`
- `test: verify client visit deletion logic`
