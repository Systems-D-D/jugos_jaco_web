<?php

namespace App\Exceptions;

use Exception;

/**
 * Error de negocio del conteo de sobrantes. El mensaje es seguro para
 * mostrarse al usuario tal cual; el detalle técnico (si lo hay) viaja en
 * $previous y se registra en el log, nunca en pantalla.
 */
class RemainingProductCountException extends Exception
{
    public static function reconciliationClosed(): self
    {
        return new self('El cuadre ya fue cerrado. Pide a un administrador que lo reabra para corregir.', 409);
    }

    public static function detailNotInAssignment(): self
    {
        return new self('El producto no pertenece a la asignación seleccionada.', 422);
    }

    public static function invalidQuantity(float $max): self
    {
        return new self('La cantidad debe estar entre 0 y ' . self::formatQuantity($max) . '.', 422);
    }

    public static function cannotUndoBelowZero(): self
    {
        return new self('No se puede eliminar: dejaría la cantidad devuelta en negativo.', 422);
    }

    public static function inventoryFailed(?\Throwable $previous = null): self
    {
        return new self('No se pudo actualizar el inventario. No se guardó ningún cambio.', 500, $previous);
    }

    private static function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
