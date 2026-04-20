<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientVisit;
use App\Services\ClientVisitService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClientVisitController extends Controller
{
    use ApiResponse;

    /**
     * Store a newly created resource in storage.
     */
    public function createVisit(Request $request, $client_id)
    {
        try {
            $client = Client::findOrFail($client_id);
            $today = Carbon::now()->toDateString();

            // Check if a visit already exists for this client today
            $existingVisit = ClientVisit::where('client_id', $client->id)
                ->where('visited_date', $today)
                ->first();

            if ($existingVisit) {
                return $this->errorResponse(
                    new \Exception('Ya se ha registrado una visita para este cliente hoy.'),
                    422,
                    'Visita duplicada.'
                );
            }

            $visit = app(ClientVisitService::class)->registerVisit(
                clientId: $client->id,
                userId: Auth::user()->id,
                visitDate: $today,
                visited: true
            );

            return $this->successResponse($visit, 'Visita registrada correctamente.', 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse($e, 404, 'Cliente no encontrado.');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Error al registrar la visita.');
        }
    }

    /**
     * Delete today's visit for a client.
     */
    public function deleteVisit(Request $request, $client_id)
    {
        try {
            $client = Client::findOrFail($client_id);
            $today = Carbon::now()->toDateString();

            $visit = ClientVisit::where('client_id', $client->id)
                ->where('visited_date', $today)
                ->first();

            if (!$visit) {
                return $this->errorResponse(
                    new \Exception('No se encontró una visita para hoy.'),
                    404,
                    'No existe una visita registrada hoy para este cliente.'
                );
            }

            $visit->delete();

            return $this->successResponse(null, 'Visita eliminada correctamente.');

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse($e, 404, 'Cliente no encontrado.');
        } catch (\Exception $e) {
            return $this->errorResponse($e, 500, 'Error al eliminar la visita.');
        }
    }
}
