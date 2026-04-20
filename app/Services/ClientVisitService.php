<?php

namespace App\Services;

use App\Models\ClientVisit;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class ClientVisitService
{
    /**
     * Registra una visita a un cliente para un usuario en una fecha específica.
     * Si ya existe un registro para ese cliente, usuario y fecha, lo actualiza (para evitar duplicados).
     *
     * @param int $clientId
     * @param int $userId
     * @param Carbon|string|null $visitDate
     * @param bool $visited
     * @return ClientVisit
     * @throws Exception
     */
    public function registerVisit(int $clientId, int $userId, $visitDate = null, bool $visited = true): ClientVisit
    {
        try {
            $date = $visitDate ? Carbon::parse($visitDate) : Carbon::today();

            return ClientVisit::updateOrCreate(
                [
                    'client_id' => $clientId,
                    'user_id' => $userId,
                    'visited_date' => $date->toDateString(),
                ],
                [
                    'visited' => $visited,
                ]
            );
        } catch (Exception $e) {
            Log::error('Error en ClientVisitService::registerVisit: ' . $e->getMessage());
            throw new Exception('Error al registrar la visita del cliente: ' . $e->getMessage());
        }
    }
}
