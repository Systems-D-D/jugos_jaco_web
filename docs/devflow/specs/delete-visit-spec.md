# System Spec: Delete Client Visit

## 1. Requirement Overview
Allow employees to undo or cancel a visit registration for a client if it was made on the current day.

## 2. Technical Architecture

### Endpoint
- **Method:** DELETE
- **Endpoint:** `/api/clients/{client_id}/visit`
- **Middleware:** `auth:sanctum` (inherited from group)

### Controller Action: `ClientVisitController@deleteVisit`
1.  Verify if the client exists (`Client::findOrFail`).
2.  Search for a `ClientVisit` where:
    - `client_id == $client_id`
    - `visited_date == Carbon::now()->toDateString()`
3.  If a visit exists:
    - Perform `$visit->delete()`.
    - Return `$this->successResponse(null, 'Visita eliminada correctamente.')`.
4.  If no visit exists for today:
    - Return `$this->errorResponse(new Exception('No se encontró una visita para hoy.'), 404, 'No existe una visita registrada hoy para este cliente.')`.

## 3. Data Integrity
- No soft deletes used.
- Deletion only affects the current date. Past records are immutable via this endpoint.
