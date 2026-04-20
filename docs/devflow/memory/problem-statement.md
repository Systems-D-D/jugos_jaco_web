# Problem Statement

## Goal
Implement a functional endpoint to delete/cancel a client's visit registered for the current day.

## Assumptions & Rules
- The endpoint should likely be `DELETE /api/clients/{client_id}/visit`.
- It should only delete a visit if it was recorded for *today*.
- If no visit exists for today, it should return a 404 response.
- Authentication is required (already handled by the route group).
- Deletion is physical (Hard Delete) as the `client_visits` table does not use SoftDeletes.

## Edge Cases
- What if the visit is from a past date? It should NOT be deleted.
- What if the client ID does not exist? Return 404.
