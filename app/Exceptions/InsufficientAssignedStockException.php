<?php

namespace App\Exceptions;

use Exception;

/**
 * Error de negocio (no transitorio): la cantidad solicitada supera el sobrante
 * del producto asignado. Se traduce a 422 para que la app móvil no reintente.
 */
class InsufficientAssignedStockException extends Exception
{
}
