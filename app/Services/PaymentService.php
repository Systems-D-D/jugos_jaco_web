<?php

namespace App\Services;

use App\Enums\AccountReceivableStatusEnum;
use App\Models\AccountReceivable;
use App\Models\Payment;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PaymentService
{
    /**
     * Procesa un nuevo pago para una cuenta por cobrar
     */
    public static function processPayment(AccountReceivable $accountReceivable, array $paymentData): array
    {
        try {
            $payment = DB::transaction(function () use ($accountReceivable, $paymentData) {
                // Se relee y bloquea dentro de la transacción: el objeto
                // recibido por parámetro puede estar desactualizado (p. ej.
                // SaleCancellationService pudo anular la cuenta justo
                // después de que el controlador la cargara, fuera de esta
                // transacción). lockForUpdate() serializa contra esa misma
                // fila que SaleCancellationService también bloquea, así que
                // exactamente una de las dos operaciones gana la carrera y
                // la otra ve el estado ya actualizado.
                $locked = AccountReceivable::whereKey($accountReceivable->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                self::validatePaymentAmount($locked, $paymentData['amount']);

                $newBalance = $locked->remaining_balance - $paymentData['amount'];

                $payment = $locked->payments()->create([
                    'amount' => $paymentData['amount'],
                    'balance_after_payment' => max(0, $newBalance),
                    'payment_date' => $paymentData['payment_date'],
                    'payment_method' => $paymentData['payment_method'],
                    'notes' => $paymentData['notes'] ?? null,
                ]);

                // Actualizar el saldo de la cuenta por cobrar
                $locked->update([
                    'remaining_balance' => max(0, $newBalance),
                    'status' => $newBalance <= 0 ? AccountReceivableStatusEnum::PAID : AccountReceivableStatusEnum::PENDING,
                    'paid_at' => $newBalance <= 0 ? now() : null,
                ]);

                return $payment;
            });

            return [
                'success' => true,
                'message' => 'Pago registrado exitosamente',
                'data' => [
                    'payment' => $payment,
                    'account_receivable' => $accountReceivable->fresh(),
                    'payment_amount' => $payment->amount,
                    'new_balance' => $payment->balance_after_payment,
                    'is_fully_paid' => $payment->balance_after_payment == 0,
                ]
            ];
        } catch (\Exception $e) {
            throw $e;
            return [
                'success' => false,
                'message' => 'Error al procesar el pago: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Marca una cuenta por cobrar como pagada completamente
     */
    public static function markAsPaid(AccountReceivable $accountReceivable, string $paymentMethod = 'cash', ?string $notes = null): array
    {
        try {
            $payment = DB::transaction(function () use ($accountReceivable, $paymentMethod, $notes) {
                // Calcular el monto restante
                $totalPaid = $accountReceivable->payments()->sum('amount');
                $remainingAmount = $accountReceivable->total_amount - $totalPaid;

                $payment = null;

                // Solo crear un pago si hay un monto restante
                if ($remainingAmount > 0) {
                    $payment = $accountReceivable->payments()->create([
                        'amount' => $remainingAmount,
                        'balance_after_payment' => 0,
                        'payment_date' => now(),
                        'payment_method' => $paymentMethod,
                        'notes' => $notes ?? 'Pago automático al marcar como pagado',
                    ]);
                }

                // Actualizar el estado
                $accountReceivable->update([
                    'remaining_balance' => 0,
                    'status' => AccountReceivableStatusEnum::PAID,
                    'paid_at' => now(),
                ]);

                return $payment;
            });

            return [
                'success' => true,
                'message' => 'Cuenta marcada como pagada exitosamente',
                'data' => [
                    'payment' => $payment,
                    'account_receivable' => $accountReceivable->fresh(),
                    'final_payment_amount' => $payment ? $payment->amount : 0,
                    'was_already_paid' => $payment === null,
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al marcar la cuenta como pagada: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Cancela una cuenta por cobrar
     */
    public static function cancelAccount(AccountReceivable $accountReceivable, string $cancellationReason): array
    {
        try {
            DB::transaction(function () use ($accountReceivable, $cancellationReason) {
                $accountReceivable->update([
                    'status' => AccountReceivableStatusEnum::CANCELLED,
                    'cancelled_at' => now(),
                    'notes' => ($accountReceivable->notes ? $accountReceivable->notes . ' | ' : '') . 'Cancelada: ' . $cancellationReason,
                ]);
            });

            return [
                'success' => true,
                'message' => 'Cuenta cancelada exitosamente',
                'data' => [
                    'account_receivable' => $accountReceivable->fresh(),
                    'cancellation_reason' => $cancellationReason,
                    'total_paid_before_cancellation' => $accountReceivable->payments()->sum('amount'),
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al cancelar la cuenta: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Recalcula los saldos de una cuenta por cobrar después de modificar pagos
     */
    public static function recalculateBalances(AccountReceivable $accountReceivable): array
    {
        try {
            DB::transaction(function () use ($accountReceivable) {
                $payments = $accountReceivable->payments()->orderBy('payment_date')->get();
                
                $runningBalance = $accountReceivable->total_amount;
                
                foreach ($payments as $payment) {
                    $runningBalance -= $payment->amount;
                    $payment->update(['balance_after_payment' => max(0, $runningBalance)]);
                }

                // Actualizar el estado de la cuenta por cobrar
                $accountReceivable->update([
                    'remaining_balance' => max(0, $runningBalance),
                    'status' => $runningBalance <= 0 ? AccountReceivableStatusEnum::PAID : AccountReceivableStatusEnum::PENDING,
                ]);
            });

            return [
                'success' => true,
                'message' => 'Saldos recalculados exitosamente',
                'data' => [
                    'account_receivable' => $accountReceivable->fresh(),
                    'payments_count' => $accountReceivable->payments()->count(),
                    'new_balance' => $accountReceivable->fresh()->remaining_balance,
                    'status' => $accountReceivable->fresh()->status,
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al recalcular los saldos: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Valida si un monto de pago es válido
     */
    public static function validatePaymentAmount(AccountReceivable $accountReceivable, float $amount): void
    {
        $errors = [];

        if ($amount <= 0) {
            $errors[] = 'El monto del pago debe ser mayor a cero.';
        }

        if ($amount > $accountReceivable->remaining_balance) {
            $errors[] = "El monto del pago no puede ser mayor al saldo pendiente (L. " . $accountReceivable->remaining_balance . ").";
        }

        // Validación adicional: verificar que la cuenta esté pendiente
        if ($accountReceivable->status !== AccountReceivableStatusEnum::PENDING) {
            $errors[] = 'Solo se pueden registrar pagos en cuentas pendientes.';
        }

        // Validación adicional: saldo restante debe ser mayor a 0
        if ($accountReceivable->remaining_balance <= 0) {
            $errors[] = 'Esta cuenta ya está completamente pagada.';
        }

        if (!empty($errors)) {
            throw new Exception(implode(", ", $errors), 422);
        }
    }

    /**
     * Obtiene estadísticas de pagos para una cuenta por cobrar
     */
    public static function getPaymentStats(AccountReceivable $accountReceivable): array
    {
        $payments = $accountReceivable->payments;
        
        return [
            'total_payments' => $payments->count(),
            'total_paid' => $payments->sum('amount'),
            'average_payment' => $payments->count() > 0 ? $payments->avg('amount') : 0,
            'last_payment_date' => $payments->max('payment_date'),
            'payment_methods' => $payments->groupBy('payment_method')->map->count(),
            'remaining_balance' => $accountReceivable->remaining_balance,
            'completion_percentage' => $accountReceivable->total_amount > 0 
                ? round(($payments->sum('amount') / $accountReceivable->total_amount) * 100, 2) 
                : 0,
        ];
    }

    /**
     * Convierte el resultado del servicio a JsonResponse para API
     */
    public static function toJsonResponse(array $result, int $successStatus = 200, int $errorStatus = 400): JsonResponse
    {
        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'data' => $result['data'],
            ], $successStatus);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['data'] ?? [],
            ], $errorStatus);
        }
    }
}
