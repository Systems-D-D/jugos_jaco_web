<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentRequest;
use App\Http\Resources\AccountReceivableDetailResource;
use App\Http\Resources\AccountReceivableResource;
use App\Http\Resources\PaymentResource;
use App\Models\AccountReceivable;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AccountReceivableController extends Controller
{
    use ApiResponse;

    private $paymentService;

    public function __construct()
    {
        $this->paymentService = new PaymentService();
    }

    /**
     * Get all accountReceivable by employee
     * 
     * @return JsonResponse
     */
    public function getAccountReceivable(): JsonResponse
    {
        try {
            $accountReceivable = AccountReceivable::byEmployee(Auth::user()->employee_id)
                ->notCancelled()
                ->where(function($query) {
                    $query->whereNull('paid_at')
                        ->orWhere(function($sub) {
                            $sub->whereNotNull('paid_at')
                                ->whereDate('paid_at', '>=', Carbon::now()->subDays(7));
                        });
                })
                ->orderBy('due_date')
                ->get();

            return $this->successResponse(
                AccountReceivableResource::collection($accountReceivable),
                "Cuentas por cobrar obtenidas exitosamente."
            );
        } catch (Exception $exc) {
            return $this->errorResponse(
                $exc,
                $exc->getCode(),
                "Courrio un error al obtener las cuentas por cobrar."
            );
        }
    }

    /**
     * Get account receivable by id
     * 
     * @param int $accountReceivableId
     * @return JsonResponse
     */
    public function getAccountReceivableById(int $accountReceivableId): JsonResponse
    {
        try {
            $accountReceivable = AccountReceivable::findOrFail($accountReceivableId);

            return $this->successResponse(
                new AccountReceivableDetailResource($accountReceivable->load('payments')),
                "Pagos obtenidos exitosamente."
            );
        } catch (Exception $exc) {
            return $this->errorResponse(
                $exc,
                $exc->getCode(),
                "Ocurrió un error al obtener la cuenta por cobrar"
            );
        }
    }

    /**
     * Process a payment for an account receivable
     * * @param int $accountReceivableId
     * @param PaymentRequest $request
     * @return JsonResponse
     */
    public function processPayment(int $accountReceivableId, PaymentRequest $request): JsonResponse
    {
        try {
            $accountReceivable = AccountReceivable::findOrFail($accountReceivableId);
            $result = $this->paymentService->processPayment(
                $accountReceivable,
                [
                    'amount' => $request['amount'],
                    'payment_date' => now(),
                    'payment_method' => $request['payment_method'],
                    'notes' => $request['notes'],
                ]
            );

            return $this->successResponse(
                new PaymentResource($result['data']['payment']),
                $result['message']
            );
        } catch (Exception  $exc) {
            return $this->errorResponse(
                $exc,
                $exc->getCode(),
                "Ocurrió un error al realizar el pago."
            );
        }
    }

    /**
     * Get payments to day
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getPaymentsToDay(Request $request): JsonResponse
    {
        try {
            $date = $request->query('date', Carbon::now()->toDateString());

            $accountsWithPayments = AccountReceivable::byEmployee(Auth::user()->employee_id)
                ->notCancelled()
                ->with(['payments' => function($query) use ($date) {
                    $query->whereDate('payment_date', $date);
                }])
                ->get();

            $payments = $accountsWithPayments->pluck('payments')->flatten();

            return $this->successResponse(
                PaymentResource::collection($payments),
                "Pagos obtenidos exitosamente."
            );
        } catch (Exception $exc) {
            return $this->errorResponse(
                $exc,
                500,
                "Ocurrió un error al obtener los pagos."
            );
        }
    }
}
