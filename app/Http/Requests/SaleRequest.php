<?php

namespace App\Http\Requests;

use App\Enums\AssignedProductMovementTypeEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\PaymentTermEnum;
use App\Enums\SaleStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'client_id' => 'required|exists:clients,id',
            'employee_id' => 'required|exists:employees,id',
            'payment_term' => ['required', new Enum(PaymentTermEnum::class)],
            'payment_method' => ['required', new Enum(PaymentTypeEnum::class)],
            'cash_amount' => 'required|numeric|min:0',
            'payment_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
            'client_request_uuid' => 'nullable|uuid',

            // Detalle de venta
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric|gt:0',
            'products.*.product_price_id' => 'required|exists:products_prices,id',
            'products.*.movement_type' => ['nullable', 'string', new Enum(AssignedProductMovementTypeEnum::class)],
        ];
    }
}
