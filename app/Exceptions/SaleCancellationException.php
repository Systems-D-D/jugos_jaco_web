<?php

namespace App\Exceptions;

use Exception;

/**
 * Error de negocio (no transitorio) al intentar anular una venta: precondición
 * no cumplida (R1-R5, factura emitida) o evidencia insuficiente para revertir
 * con seguridad. El código pasado al constructor es el status HTTP sugerido
 * para las capas que consuman el servicio (Filament, API), siguiendo el mismo
 * patrón que el resto del código (`new Exception($msg, 422)`).
 */
class SaleCancellationException extends Exception
{
}
