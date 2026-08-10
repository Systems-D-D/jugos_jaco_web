<div class="w-full mx-auto">
    <!-- Include global toasts component -->
    @include('livewire.partials.global-toasts')

    <style scoped>
        .custom-container {
            width: 100%;
            padding-left: 15px;
            padding-right: 15px;
        }
        .custom-row {
            display: flex !important;
            flex-wrap: wrap;
            margin-left: -15px;
            margin-right: -15px;
            gap: 15px;
            align-items: flex-start;
        }
        .custom-col-8 {
            flex: 0 0 auto;
            width: calc(66.66666667% - 15px);
            padding-left: 15px;
            padding-right: 15px;
            min-height: 100px;
        }
        .custom-col-4 {
            flex: 0 0 auto;
            width: calc(33.33333333% - 15px);
            padding-left: 15px;
            padding-right: 15px;
            min-height: 100px;
        }
        .custom-bg-light {
            background-color: #f8f9fa;
        }
        @media (max-width: 768px) {
            .custom-col-8, .custom-col-4 {
                width: 100% !important;
            }
            .custom-row {
                flex-direction: column;
            }
        }
    </style>

    <div class="bg-white dark:bg-gray-800 shadow-xl rounded-lg">
        <!-- Grid personalizado sin Bootstrap -->
        <div class="custom-container p-3">
            <div class="custom-row">
                <div class="custom-col-8">
                    <div class="custom-bg-light p-4 rounded-lg">
                        <form wire:submit.prevent="save" class="p-6 space-y-6">
                            <!-- Información básica -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Fila 1 -->
                                <!-- Cliente -->
                                <div>
                                    <label for="client_id"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Cliente
                                    </label>
                                    <select wire:model="client_id" id="client_id"
                                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="{{ null }}">Seleccione un cliente</option>
                                        @foreach ($clients as $client)
                                            <option value="{{ $client->id }}">
                                                {{ trim($client->first_name . ' ' . $client->last_name) . ' (' . $client->business_name . ')' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('client_id')
                                        <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Empleado -->
                                <div>
                                    <label for="employee_id"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Empleado <span class="text-red-500">*</span>
                                    </label>
                                    <select wire:model="employee_id" id="employee_id" required @if(Auth::user()->role !== 'admin') disabled @endif
                                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="">Seleccione un empleado</option>
                                        @foreach ($employees as $employee)
                                            <option value="{{ $employee->id }}">
                                                {{ trim($employee->first_name . ' ' . $employee->last_name) }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('employee_id')
                                        <span class="text-red-500 text-xs">{{ $message }}</span>
                                    @enderror
                                </div>

                                <!-- Fecha: sólo lectura, la fija el servidor al guardar (no editable) -->
                                <div>
                                    <label for="sale_date"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Fecha
                                    </label>
                                    <input type="date" wire:model="sale_date" id="sale_date" readonly disabled
                                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm bg-gray-100 dark:bg-gray-800 cursor-not-allowed">
                                </div>
                            </div>

                            <!-- Búsqueda de productos - Segunda fila -->
                            <div>
                                <label for="product_search"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Buscar producto por código o nombre (Presiona Enter para agregar, F2 para enfocar)
                                </label>
                                <div class="relative">
                                    <input type="text" wire:model="product_search"
                                        wire:keydown.enter.prevent="addFirstProduct" id="product_search"
                                        placeholder="Buscar producto por código o nombre y presiona Enter para agregar"
                                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 pl-10">
                                    <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                                        <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                        </svg>
                                    </div>
                                </div>
                                @error('product_search')
                                    <span class="text-red-500 text-xs mt-1 block">{{ $message }}</span>
                                @enderror
                            </div>

                            <!-- Tabla de productos -->
                            <div class=" border border-gray-200 dark:border-gray-700 rounded-lg">
                                <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th
                                                class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">
                                                Código</th>
                                            <th
                                                class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                                Descripción</th>
                                            <th
                                                class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-32">
                                                Precio</th>
                                            <th
                                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-24">
                                                Unidad</th>
                                            <th
                                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-32">
                                                Cantidad</th>
                                            <th
                                                class="px-4 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-32">
                                                Subtotal</th>
                                            <th
                                                class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider w-20">
                                                Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody
                                        class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($products as $index => $product)
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                                <td
                                                    class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                                    {{ $product['code'] }}
                                                </td>
                                                <td class="px-4 py-4 text-sm text-gray-900 dark:text-gray-100">
                                                    <div class="font-medium text-gray-900 dark:text-gray-100">
                                                        {{ $product['name'] }}</div>
                                                    @if (isset($product['tax_category_name']) && $product['tax_rate'] > 0)
                                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                            <span
                                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                                {{ $product['tax_category_name'] }}
                                                                ({{ $product['tax_rate'] }}%)
                                                            </span>
                                                        </div>
                                                    @else
                                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                            <span
                                                                class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                                Sin impuesto
                                                            </span>
                                                        </div>
                                                    @endif
                                                </td>
                                                <td
                                                    class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100 text-right">
                                                    <div class="font-medium text-gray-900 dark:text-gray-100">L
                                                        {{ number_format($product['unit_price_without_tax'], 2) }}</div>
                                                    @if (isset($product['unit_tax_amount']) && $product['unit_tax_amount'] > 0)
                                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                                            +L {{ number_format($product['unit_tax_amount'], 2) }} imp.
                                                        </div>
                                                    @endif
                                                </td>
                                                <td
                                                    class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100 text-center">
                                                    {{ $product['unit_abbreviation'] ?? $product['unit_name'] }}
                                                </td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-center">
                                                    <input type="number"
                                                        wire:change="updateQuantity({{ $index }}, $event.target.value)"
                                                        value="{{ $product['quantity'] }}" min="0.01"
                                                        max="{{ $product['stock'] }}" step="0.01"
                                                        class="w-20 text-center rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                                    <div class="text-xs text-gray-500 mt-1">
                                                        Máx: {{ $product['stock'] }}
                                                    </div>
                                                </td>
                                                <td
                                                    class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100 text-right">
                                                    L {{ number_format($product['line_subtotal'], 2) }}
                                                </td>
                                                <td class="px-4 py-4 whitespace-nowrap text-sm text-center">
                                                    <button type="button"
                                                        wire:click="removeProduct({{ $index }})"
                                                        class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300 p-1 rounded-full hover:bg-red-100 dark:hover:bg-red-900/20 transition-colors">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor"
                                                            viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                                            </path>
                                                        </svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="7"
                                                    class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                                    <div class="flex flex-col items-center">
                                                        <svg class="w-16 h-16 mb-4 text-gray-300 dark:text-gray-600"
                                                            fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                                                        </svg>
                                                        <span
                                                            class="text-lg font-medium text-gray-500 dark:text-gray-400">No
                                                            hay productos agregados</span>
                                                        <span class="text-sm text-gray-400 dark:text-gray-500 mt-1">Use
                                                            el
                                                            campo de búsqueda para agregar productos</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </form>
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Notas y botones - Lado izquierdo (2 columnas en desktop) -->
                            <div class="lg:col-span-2 space-y-6">
                                <!-- Notas -->
                                <div>
                                    <label for="notes"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Notas adicionales
                                    </label>
                                    <textarea wire:model="notes" id="notes" rows="4" placeholder="Comentarios adicionales sobre la venta..."
                                        class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                                </div>

                                <!-- Botones de acción -->
                                <div class="flex justify-start space-x-4 gap-4">
                                    <button type="button" wire:click="openPaymentModal" wire:loading.attr="disabled"
                                        class="flex items-center justify-center px-8 py-3 bg-green-600 hover:bg-green-700 dark:bg-green-600 dark:hover:bg-green-700 text-black font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 transform hover:scale-105 disabled:opacity-50 disabled:cursor-not-allowed">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1">
                                            </path>
                                        </svg>
                                        Procesar pago (F3)
                                    </button>

                                    <a href="{{ \App\Filament\Resources\SaleResource::getUrl('index') }}"
                                        class="flex items-center justify-center px-8 py-3 bg-gray-500 hover:bg-gray-600 dark:bg-gray-600 dark:hover:bg-gray-700 text-black font-semibold rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 transform hover:scale-105">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                        Cancelar
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="custom-col-4">
                    <div class="custom-bg-light p-4 rounded-lg">
                        <div
                            class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-2xl shadow-xl border border-blue-200 dark:border-blue-800 p-6 sticky top-6">
                            <!-- Header del card -->
                            <div
                                class="flex items-center justify-between mb-4 pb-3 border-b border-blue-200 dark:border-blue-700">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 flex items-center">
                                    <svg class="w-5 h-5 mr-2 text-blue-600 dark:text-blue-400" fill="none"
                                        stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z">
                                        </path>
                                    </svg>
                                    Resumen de Venta
                                </h3>
                                <div
                                    class="text-xs text-blue-600 dark:text-blue-400 bg-blue-100 dark:bg-blue-900/50 px-2 py-1 rounded-full font-medium">
                                    {{ count($products) }} {{ count($products) === 1 ? 'producto' : 'productos' }}
                                </div>
                            </div>

                            <!-- Totales detallados -->
                            <div class="space-y-4">
                                <!-- Subtotal -->
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Subtotal:</span>
                                    <span class="text-sm font-bold text-gray-900 dark:text-gray-100">L
                                        {{ number_format($subtotal, 2) }}</span>
                                </div>

                                <!-- Impuestos -->
                                @if (count($tax_totals) > 0)
                                    @foreach ($tax_totals as $tax)
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">
                                                {{ $tax['tax_category_name'] }} ({{ $tax['tax_rate'] }}%):
                                            </span>
                                            <span class="text-xs font-semibold text-gray-800 dark:text-gray-200">L
                                                {{ number_format($tax['tax_amount'], 2) }}</span>
                                        </div>
                                    @endforeach
                                    <div class="border-t border-blue-200 dark:border-blue-700 pt-3 mt-3"></div>
                                @endif

                                <!-- Total final -->
                                <div
                                    class="bg-white dark:bg-gray-800 rounded-xl p-4 border-2 border-blue-300 dark:border-blue-600 shadow-inner">
                                    <div class="flex justify-between items-center">
                                        <span class="text-lg font-bold text-blue-900 dark:text-blue-100">Total a
                                            Pagar:</span>
                                        <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">L
                                            {{ number_format($final_total, 2) }}</span>
                                    </div>
                                </div>

                                <!-- Información de pago (si hay) -->
                                @if (in_array($payment_type, ['cash', 'deposit']) && $cash_amount > 0)
                                    <div class="border-t border-blue-200 dark:border-blue-700 pt-3 space-y-2">
                                        <div class="flex justify-between items-center text-sm">
                                            <span class="font-medium text-gray-600 dark:text-gray-400">
                                                Monto {{ $payment_type === 'cash' ? 'efectivo' : 'depósito' }}:
                                            </span>
                                            <span class="font-semibold text-gray-800 dark:text-gray-200">L
                                                {{ number_format($cash_amount, 2) }}</span>
                                        </div>
                                        <div class="flex justify-between items-center text-sm">
                                            <span class="font-medium text-gray-600 dark:text-gray-400">Cambio:</span>
                                            <span
                                                class="font-semibold {{ $cash_amount >= $final_total ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                                L {{ number_format(max(0, $cash_amount - $final_total), 2) }}
                                            </span>
                                        </div>
                                    </div>
                                @endif

                                <!-- Estado de la venta -->
                                <div class="pt-3 border-t border-blue-200 dark:border-blue-700">
                                    <div class="flex items-center justify-center">
                                        @if (count($products) > 0 && $final_total > 0)
                                            <span
                                                class="inline-flex items-center px-3 py-2 rounded-full text-sm font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 border border-green-300 dark:border-green-700">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                Lista para procesar
                                            </span>
                                        @elseif(count($products) > 0)
                                            <span
                                                class="inline-flex items-center px-3 py-2 rounded-full text-sm font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 border border-yellow-300 dark:border-yellow-700">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                Verificar totales
                                            </span>
                                        @else
                                            <span
                                                class="inline-flex items-center px-3 py-2 rounded-full text-sm font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300 border border-gray-300 dark:border-gray-600">
                                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9"></path>
                                                </svg>
                                                Agregue productos
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de pago -->
    @if ($showPaymentModal)
        <div class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-lg w-full max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <!-- Header del modal -->
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                            Procesar pago
                        </h3>
                        <button wire:click="closePaymentModal"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Resumen de la venta -->
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-6">
                        <h4 class="font-semibold text-gray-900 dark:text-white mb-3">Resumen de la venta</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-300">Productos:</span>
                                <span
                                    class="font-medium text-gray-900 dark:text-gray-100">{{ count($products) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-300">Subtotal:</span>
                                <span class="font-medium text-gray-900 dark:text-gray-100">L
                                    {{ number_format($subtotal, 2) }}</span>
                            </div>
                            @if (count($tax_totals) > 0)
                                @foreach ($tax_totals as $tax)
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-300">{{ $tax['tax_category_name'] }}
                                            ({{ $tax['tax_rate'] }}%)
                                            :</span>
                                        <span class="font-medium text-gray-900 dark:text-gray-100">L
                                            {{ number_format($tax['tax_amount'], 2) }}</span>
                                    </div>
                                @endforeach
                            @endif
                            <div class="border-t pt-2 border-gray-300 dark:border-gray-600">
                                <div class="flex justify-between text-lg font-bold">
                                    <span class="text-gray-900 dark:text-gray-100">Total:</span>
                                    <span class="text-blue-600 dark:text-blue-400">L
                                        {{ number_format($final_total, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario de pago -->
                    <form wire:submit.prevent="save" class="space-y-4">
                        <!-- Método de pago -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Método de pago <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="payment_method" required
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Seleccionar método</option>
                                <option value="efectivo" selected>Efectivo</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="transferencia">Transferencia</option>
                                <option value="credito">Crédito</option>
                            </select>
                            @error('payment_method')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Término de pago -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Término de pago <span class="text-red-500">*</span>
                            </label>
                            <select wire:model="payment_term" required
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Seleccionar término</option>
                                @foreach ($this->paymentTermOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('payment_term')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Monto pagado -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Monto pagado <span class="text-red-500">*</span>
                            </label>
                            <input type="number" wire:model.live="amount_paid" step="0.01" min="0"
                                placeholder="0.00"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('amount_paid')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror

                            <!-- Indicador de estado automático -->
                            <div class="mt-2 text-sm">
                                @if (abs(floatval($amount_paid) - $final_total) < 0.01)
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        Pago completo
                                    </span>
                                @elseif($amount_paid > 0)
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Pago parcial
                                    </span>
                                @else
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Sin pago (crédito)
                                    </span>
                                @endif

                                @if ($amount_paid > $final_total)
                                    <div class="mt-1 text-xs text-blue-600 dark:text-blue-400">
                                        Cambio: L {{ number_format($amount_paid - $final_total, 2) }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Referencia -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Referencia (opcional)
                            </label>
                            <input type="text" wire:model="payment_reference"
                                placeholder="Número de autorización, cheque, etc."
                                class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('payment_reference')
                                <span class="text-red-500 text-xs">{{ $message }}</span>
                            @enderror
                        </div>

                        <!-- Botones del modal -->
                        <div class="flex space-x-3 pt-4">
                            <button type="button" wire:click="closePaymentModal"
                                class="flex-1 px-4 py-2 text-gray-700 dark:text-gray-300 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 rounded-md transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" wire:loading.attr="disabled"
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-black font-semibold rounded-md shadow-lg hover:shadow-xl transition-all duration-200 transform hover:scale-105">
                                <span wire:loading.remove>Procesar venta</span>
                                <span wire:loading>Procesando...</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal de Confirmación de Venta -->
    @if ($showConfirmationModal && $createdSale)
        <div
            class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-50 dark:bg-opacity-75 overflow-y-auto h-full w-full z-50">
            <div
                class="relative top-10 mx-auto p-5 border w-11/12 max-w-2xl shadow-lg rounded-md bg-white dark:bg-gray-800">
                <div class="mt-3">
                    <!-- Header -->
                    <div class="flex items-center justify-between mb-6">
                        <div class="flex items-center">
                            <div
                                class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-green-100 dark:bg-green-900/30">
                                <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M5 13l4 4L19 7"></path>
                                </svg>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100">¡Venta Creada
                                    Exitosamente!</h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Venta #{{ $createdSale->id }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Información de la venta -->
                    <div class="space-y-4 p-6">
                        <!-- Cliente y Fecha -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cliente</label>
                                <p class="text-sm text-gray-900 dark:text-gray-100">
                                    @if ($createdSale->client)
                                        {{ trim($createdSale->client->first_name . ' ' . $createdSale->client->last_name) ?: $createdSale->client->business_name }}
                                    @else
                                        Cliente General
                                    @endif
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Fecha</label>
                                <p class="text-sm text-gray-900 dark:text-gray-100">
                                    {{ \Carbon\Carbon::parse($createdSale->sale_date)->format('d/m/Y') }}</p>
                            </div>
                        </div>

                        <!-- Totales -->
                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <div class="grid grid-cols-3 gap-4 text-center">
                                <div>
                                    <p class="text-xs text-gray-900 dark:text-gray-400">Subtotal</p>
                                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">L
                                        {{ number_format($createdSale->subtotal, 2) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-900 dark:text-gray-400">Impuestos</p>
                                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">L
                                        {{ number_format($createdSale->total_amount - $createdSale->subtotal, 2) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-900 dark:text-gray-400">Total</p>
                                    <p class="text-xl font-bold text-blue-600 dark:text-blue-400">L
                                        {{ number_format($createdSale->total_amount, 2) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Información de Pago -->
                        <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg">
                            <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-3">Información de Pago</h4>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Método de
                                        Pago</label>
                                    <p class="text-sm text-gray-900 dark:text-gray-100 capitalize">
                                        @if ($createdSale->payment_type)
                                            {{ $createdSale->payment_type->getLabel() }}
                                        @else
                                            No especificado
                                        @endif
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Monto
                                        Pagado</label>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">L
                                        {{ number_format($createdSale->cash_amount, 2) }}</p>
                                </div>
                            </div>

                            <!-- Estado de Pago -->
                            <div class="mt-3">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Estado del
                                    Pago</label>
                                @if ($createdSale->cash_amount >= $createdSale->total_amount)
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        Pago Completo
                                    </span>
                                    @if ($createdSale->cash_amount > $createdSale->total_amount)
                                        <div class="mt-2 p-2 bg-yellow-100 dark:bg-yellow-900/30 rounded">
                                            <p class="text-sm text-yellow-800 dark:text-yellow-200">
                                                <strong>Cambio a entregar:</strong> L
                                                {{ number_format($createdSale->cash_amount - $createdSale->total_amount, 2) }}
                                            </p>
                                        </div>
                                    @endif
                                @elseif($createdSale->cash_amount > 0)
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Pago Parcial
                                    </span>
                                    <div class="mt-2 p-2 bg-orange-100 dark:bg-orange-900/30 rounded">
                                        <p class="text-sm text-orange-800 dark:text-orange-200">
                                            <strong>Saldo pendiente:</strong> L
                                            {{ number_format($createdSale->total_amount - $createdSale->cash_amount, 2) }}
                                        </p>
                                    </div>
                                @else
                                    <span
                                        class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Venta a Crédito
                                    </span>
                                @endif
                            </div>

                            @if ($createdSale->payment_reference)
                                <div class="mt-3">
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300">Referencia</label>
                                    <p class="text-sm text-gray-900 dark:text-gray-100">
                                        {{ $createdSale->payment_reference }}</p>
                                </div>
                            @endif
                        </div>

                        <!-- Botones de acción -->
                        <div class="flex space-x-3 pt-4">
                            <button type="button" wire:click="createNewSale"
                                class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md transition-colors">
                                <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Nueva Venta
                            </button>
                            <button type="button" wire:click="closeConfirmationModal"
                                class="flex-1 px-4 py-2 text-gray-700 dark:text-gray-300 bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 rounded-md transition-colors">
                                <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7"></path>
                                </svg>
                                Ver Ventas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>

<!-- JavaScript para mejoras de UX -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Atajos de teclado
        document.addEventListener('keydown', function(e) {
            // F2 para enfocar en búsqueda de productos
            if (e.key === 'F2') {
                e.preventDefault();
                document.getElementById('product_search').focus();
            }

            // F3 para abrir modal de pago
            if (e.key === 'F3') {
                e.preventDefault();
                @this.call('openPaymentModal');
            }

            // Escape para cerrar modal
            if (e.key === 'Escape') {
                @this.call('closePaymentModal');
            }
        });
    });
</script>
