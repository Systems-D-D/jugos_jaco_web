# AGENTS.md — Project metadata for AI agents

## Tech Stack
- Runtime / Language: PHP 8.2+ 
- Framework: Laravel 11
- Admin Panel / UI: Filament v3
- Styling: Tailwind CSS + PostCSS
- Database + ORM: MySQL (relational) + Eloquent ORM
- Auth: Laravel Sanctum (API Tokens)
- Roles & Permissions: Spatie Laravel Permission
- Test runner: PestPHP
- Package manager: Composer (Backend) + NPM (Frontend Assets)

## Folder Structure
```text
app/                     # Core application code (Models, Filament Resources, Traits)
app/Http/Controllers/    # API and Web controllers
app/Models/              # Eloquent database models
database/migrations/     # Database schema definitions and migrations
database/seeders/        # Logic to seed the database with mock or initial data
resources/               # Frontend assets (views, Tailwind CSS js/css)
routes/                  # Route definitions (api.php for API routes)
tests/                   # Unit and Feature tests using Pest
```

## Naming Conventions
- Controllers: PascalCase ending in `Controller` (`ClientVisitController.php`)
- DB Models: PascalCase singular (`ClientVisit.php`, `Employee.php`)
- DB Tables: snake_case plural (`client_visits`, `employees`)
- API routes: kebab-case grouped by entities (`/api/clients/{client_id}/visit`)
- Migrations: `YYYY_MM_DD_HHMMSS_create_snake_case_plural_table.php`

## Architecture Patterns
- API responses standardized using the `ApiResponse` trait (`$this->successResponse()`, `$this->errorResponse()`)
- "Fat Models, Skinny Controllers": Leverage Eloquent relationships (`HasMany`, `BelongsTo`, `MorphOne`) and scopes (`scopeWithRouteData`, `scopeActiveToday`) to abstract complex DB queries out of controllers.
- Polimorphic relationships heavily used (e.g., `Location`, `ResourceMedia`).
- Soft Deletes and explicit state tracking through enums (`App\Enums\*`).
- Validation ideally happens through Laravel FormRequests (though some logic might exist in controllers).

## Test Conventions
- Tests are written in PestPHP (`tests/Feature/`, `tests/Unit/`)
- Pest naming: `it('can create a client visit', function () { ... })`
- Ensure the database state is fresh using proper testing traits.
- Run tests: `php artisan test` or `./vendor/bin/pest`

## Key Third-Party Abstractions
- `auth()->user()` or `$request->user()` from Laravel Sanctum for API user context.
- `Spatie\Permission` traits (`HasRoles`) on the User model.
- `ApiResponse` trait for standardizing JSON responses across all controllers.
- Filament components for the admin panel, rely on its internal table/form builder rather than raw HTML/Blade.
