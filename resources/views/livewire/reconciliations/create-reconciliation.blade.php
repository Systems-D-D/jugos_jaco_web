<div class="w-full mx-auto" x-data="{
    showEmployeeDropdown: false,
    selectedEmployee: @entangle('employee_id'),
    reconciliationCreated: @entangle('reconciliation_created')
}">
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

        .custom-col-6 {
            flex: 0 0 auto;
            width: calc(50% - 15px);
            padding-left: 15px;
            padding-right: 15px;
            min-height: 100px;
        }

        .custom-col-65 {
            flex: 0 0 auto;
            width: calc(65% - 15px);
            padding-left: 15px;
            padding-right: 15px;
            min-height: 100px;
        }

        .custom-col-35 {
            flex: 0 0 auto;
            width: calc(35% - 15px);
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

            .custom-col-6,
            .custom-col-4,
            .custom-col-65,
            .custom-col-35 {
                width: 100% !important;
            }

            .custom-row {
                flex-direction: column;
            }
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-contado {
            background-color: #10b981;
            color: white;
        }

        .status-credito {
            background-color: #f59e0b;
            color: white;
        }

        .status-efectivo {
            background-color: #3b82f6;
            color: white;
        }

        .status-deposito {
            background-color: #8b5cf6;
            color: white;
        }
    </style>

    <div class="fi-section-content-ctn">
        
        <!-- Header -->
        <div class="fi-section-header-ctn flex px-6 flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    📊 Cuadre Diario de Ventas
                </h2>
                <p class="fi-section-header-description text-sm text-gray-500 dark:text-gray-400">
                    Gestión y seguimiento de ventas por empleado
                </p>
            </div>
            <div class="flex items-center gap-3">
                <!-- Selector de Fecha -->
                <div class="fi-fo-field-wrp">
                    <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-2">
                        <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                            📅 Fecha de Cuadre:
                        </span>
                    </label>
                    <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 ring-gray-950/10 dark:ring-white/20 focus-within:ring-2 focus-within:ring-primary-600 dark:focus-within:ring-primary-500">
                        <input
                            type="date"
                            wire:model.live="reconciliation_date"
                            class="fi-input block w-full border-none bg-transparent py-2 px-3 text-sm text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white dark:placeholder:text-gray-500 rounded-lg"
                            max="{{ now()->format('Y-m-d') }}"
                        />
                    </div>
                </div>
            </div>
        </div>

        <div class="custom-container p-6">
            @if($current_reconciliation && $current_reconciliation->status->value === 'completed')
                <div class="text-center py-8 bg-yellow-50 rounded-lg border border-yellow-200 dark:bg-yellow-900/30 dark:border-yellow-700">
                    <div class="text-yellow-600 dark:text-yellow-400 text-4xl mb-4">⚠️</div>
                    <h3 class="text-lg font-medium text-yellow-700 dark:text-yellow-300 mb-2">Cuadre Diario Completado</h3>
                    <p class="text-yellow-600 dark:text-yellow-400">Ya existe un cuadre completado para este empleado en el día de hoy.</p>
                    <p class="text-yellow-600 dark:text-yellow-400 text-sm mt-2">No se pueden realizar más modificaciones.</p>
                </div>
            @else
            <div class="custom-row">
                <!-- Left Column - Sales by Employee -->
                <div class="custom-col-65">
                    <div class="custom-bg-light p-4 rounded-lg border border-gray-200">
                        <!-- Employee Selection -->
                        <div class="fi-section-content p-6">
                            <div class="fi-fo-field-wrp">
                                <div class="grid gap-y-2">
                                    <div class="flex items-center gap-x-3 justify-between">
                                        <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                                            <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                                👤 Seleccionar Empleado
                                            </span>
                                        </label>
                                        <div>
                                            <button 
                                                type="button"
                                                wire:click="{{ $reconciliation_created ? 'saveReconciliation' : 'initializeReconciliation' }}"
                                                class="fi-btn fi-btn-size-sm relative inline-flex items-center justify-center font-semibold outline-none transition duration-75 focus:ring-2 rounded-lg fi-btn-color-primary fi-btn-color-primary-600 enabled:hover:bg-primary-500 enabled:active:bg-primary-700 dark:fi-btn-color-primary-400 dark:enabled:hover:bg-primary-300 dark:enabled:active:bg-primary-500 focus:ring-primary-600/50 dark:focus:ring-primary-400/50 bg-primary-600 text-white px-2 py-1 text-xs"
                                                :class="{'opacity-50 cursor-not-allowed': !selectedEmployee || $wire.reconciliation_created && ($wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed')}"
                                                :disabled="!selectedEmployee || $wire.reconciliation_created && ($wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed')}"
                                            >
                                                <span class="fi-btn-label">{{ $reconciliation_created ? 'Guardar Cuadre' : 'Iniciar Cuadre' }}</span>
                                            </button>
                                        </div>
                                      </div>

                                    <div class="grid gap-y-2">
                                        <div class="fi-input-wrp flex rounded-lg shadow-sm ring-1 transition duration-75 bg-white dark:bg-white/5 ring-gray-950/10 dark:ring-white/20 focus-within:ring-2 focus-within:ring-primary-600 dark:focus-within:ring-primary-500">
                                            <div class="relative w-full" x-data="{ 
                                                open: false, 
                                                search: '', 
                                                selectedEmployee: null,
                                                isSearching: false,
                                                noResults: false,
                                                showMinCharsMessage: false,
                                                employees: {{ $employees->map(function($emp) { return ['id' => $emp->id, 'name' => $emp->first_name . ' ' . $emp->last_name, 'position' => $emp->position ?? 'Empleado' ]; })->toJson() }},
                                                get filteredEmployees() {
                                                    if (this.search.length < 4) return [];
                                                    const results = this.employees.filter(emp => 
                                                        emp.name.toLowerCase().includes(this.search.toLowerCase())
                                                    );
                                                    this.noResults = results.length === 0;
                                                    return results;
                                                },
                                                init() {
                                                    if ($wire.employee_id) {
                                                        const employeeId = parseInt($wire.employee_id);
                                                        const employee = this.employees.find(emp => emp.id === employeeId);
                                                        if (employee) {
                                                            this.selectedEmployee = employee;
                                                            this.search = employee.name;
                                                        }
                                                    }
                                                    
                                                    this.$watch('search', (value) => {
                                                        if (!this.selectedEmployee) {
                                                            this.isSearching = value.length > 0;
                                                            this.showMinCharsMessage = value.length > 0 && value.length < 4;
                                                            this.open = value.length >= 4;
                                                        }
                                                    });
                                                },
                                                selectEmployee(employee) {
                                                    this.selectedEmployee = employee;
                                                    this.search = employee.name;
                                                    this.open = false;
                                                    this.isSearching = false;
                                                    this.showMinCharsMessage = false;
                                                    $wire.set('employee_id', employee.id);
                                                },
                                                clearSelection() {
                                                    this.selectedEmployee = null;
                                                    this.search = '';
                                                    this.open = false;
                                                    this.isSearching = false;
                                                    this.showMinCharsMessage = false;
                                                    $wire.set('employee_id', null);
                                                }
                                            }">
                                                <!-- Filament-style select -->
                                                <div class="fi-select-input-wrapper relative w-full">
                                                    <!-- Icono del lado izquierdo (lupa o usuario) -->
                                                    <div class="absolute top-0 left-0 h-full flex items-center px-1 pointer-events-none">
                                                        <!-- Icono de lupa cuando no hay empleado seleccionado -->
                                                        <template x-if="!selectedEmployee">
                                                            <svg class="h-5 w-5 text-gray-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                                                            </svg>
                                                        </template>
                                                        <!-- Icono de usuario cuando hay empleado seleccionado -->
                                                        <template x-if="selectedEmployee">
                                                            <svg class="h-5 w-5 text-gray-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd" />
                                                            </svg>
                                                        </template>
                                                    </div>

                                                    <!-- Input con estilo Filament -->
                                                    <input
                                                        x-model="selectedEmployee ? selectedEmployee.name : search"
                                                        @focus="if (!selectedEmployee) { $nextTick(() => { $el.select(); }) }"
                                                        @input="if (!selectedEmployee && search.length >= 4) open = true"
                                                        @keydown.escape="open = false"
                                                        @keydown.arrow-down.prevent="
                                                            if (filteredEmployees.length > 0) {
                                                                $el.blur();
                                                                $refs.employeesList.querySelector('button').focus();
                                                            }
                                                        "
                                                        @click="if (selectedEmployee) clearSelection();"
                                                        type="text"
                                                        placeholder="Escriba al menos 4 caracteres para buscar..."
                                                        :readonly="selectedEmployee"
                                                        class="fi-input block w-full border-none bg-transparent py-2 px-6 pl-10 text-sm text-gray-950 transition duration-75 placeholder:text-gray-400 focus:ring-0 dark:text-white dark:placeholder:text-gray-500 rounded-lg"
                                                        :class="{
                                                            'cursor-pointer bg-gray-50 dark:bg-gray-700/50 pr-16': selectedEmployee,
                                                            'hover:bg-gray-50 dark:hover:bg-gray-700/30 pr-10': !selectedEmployee,
                                                            'fi-search-highlighting': isSearching && !selectedEmployee
                                                        }"
                                                        x-ref="searchInput" />

                                                    <!-- Icono del lado derecho -->
                                                    <div class="absolute top-0 right-0 pt-2 px-2 flex items-center justify-end" style="width: 100%;">
                                                        <!-- Botón X cuando hay selección -->
                                                        <template x-if="selectedEmployee">
                                                            <button
                                                                @click.stop="clearSelection()"
                                                                type="button"
                                                                class="flex items-center justify-center h-5 w-5 text-gray-400 hover:text-red-500
                                                                        dark:text-gray-500 dark:hover:text-red-400 transition-colors duration-200 
                                                                        rounded-full hover:bg-red-50 dark:hover:bg-red-900/20"
                                                                title="Limpiar selección">
                                                                <svg fill="currentColor" viewBox="0 0 20 20" class="h-4 w-4">
                                                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                                </svg>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </div>

                                                <!-- Dropdown de resultados estilo Filament -->
                                                <div x-show="open || showMinCharsMessage"
                                                     x-transition:enter="transition ease-out duration-100"
                                                     x-transition:enter-start="transform opacity-0 scale-95"
                                                     x-transition:enter-end="transform opacity-100 scale-100"
                                                     x-transition:leave="transition ease-in duration-75"
                                                     x-transition:leave-start="transform opacity-100 scale-100"
                                                     x-transition:leave-end="transform opacity-0 scale-95"
                                                     @click.away="open = false; showMinCharsMessage = false"
                                                     @keydown.escape.window="open = false; showMinCharsMessage = false"
                                                     class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-lg overflow-hidden">
                                                    
                                                    <!-- Resultados de búsqueda -->
                                                    <ul x-show="filteredEmployees.length > 0" 
                                                        x-ref="employeesList"
                                                        role="listbox"
                                                        class="fi-select-list max-h-64 overflow-y-auto overscroll-contain p-1">
                                                        <template x-for="(employee, index) in filteredEmployees" :key="employee.id">
                                                            <li>
                                                                <button 
                                                                    @click="selectEmployee(employee)"
                                                                    @keydown.enter.stop.prevent="selectEmployee(employee)"
                                                                    @keydown.space.stop.prevent="selectEmployee(employee)"
                                                                    @keydown.arrow-up.prevent="$el.previousElementSibling?.querySelector('button')?.focus() || $refs.searchInput?.focus()"
                                                                    @keydown.arrow-down.prevent="$el.nextElementSibling?.querySelector('button')?.focus()"
                                                                    role="option"
                                                                    :tabindex="index === 0 ? 0 : -1"
                                                                    :aria-selected="selectedEmployee && selectedEmployee.id === employee.id"
                                                                    class="fi-select-option flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors duration-75 outline-none"
                                                                    :class="{
                                                                        'bg-primary-500/10 text-primary-600 dark:bg-primary-500/20 dark:text-primary-400': selectedEmployee && selectedEmployee.id === employee.id,
                                                                        'hover:bg-gray-50 dark:hover:bg-white/5 focus:bg-gray-50 dark:focus:bg-white/5': !(selectedEmployee && selectedEmployee.id === employee.id)
                                                                    }">
                                                                    <div class="flex flex-1 flex-col">
                                                                        <span class="truncate" x-text="employee.name"></span>
                                                                        <span class="truncate text-xs text-gray-500 dark:text-gray-400" x-text="employee.position"></span>
                                                                    </div>
                                                                    <span class="text-xs text-gray-400 dark:text-gray-500" x-text="'ID: ' + employee.id"></span>
                                                                </button>
                                                            </li>
                                                        </template>
                                                    </ul>
                                                    
                                                    <!-- Mensaje de no resultados -->
                                                    <div x-show="noResults && search.length >= 4" class="fi-select-empty-state px-3 py-2 text-sm text-gray-500 dark:text-gray-400">
                                                        <div class="flex items-center justify-center gap-x-3 py-2">
                                                            <svg class="h-5 w-5 text-gray-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                                                            </svg>
                                                            <span>No se encontraron empleados</span>
                                                        </div>
                                                    </div>
                                                    
                                                    <!-- Mensaje de caracteres mínimos -->
                                                    <div x-show="showMinCharsMessage" class="fi-select-empty-state px-3 py-2 text-sm text-blue-600 dark:text-blue-400">
                                                        <div class="flex items-center justify-center gap-x-3 py-2">
                                                            <svg class="h-5 w-5 text-blue-500 dark:text-blue-400" viewBox="0 0 20 20" fill="currentColor">
                                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                                                            </svg>
                                                            <span>Escriba al menos 4 caracteres para ver resultados</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @error('employee_id')
                                <div class="fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400">
                                    {{ $message }}
                                </div>
                                @enderror
                            </div>
                        </div>

                        <!-- Sales Table -->
                        @if($employee_id && count($sales) > 0)
                        <div class="mb-6">
                            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">💰 Ventas del Día</h4>
                                    </div>
                                    <div class="inline-flex items-center justify-center rounded-full bg-primary-50 px-2.5 py-0.5 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                                        <span class="text-xs font-medium">{{ count($sales) }} {{ count($sales) == 1 ? 'venta' : 'ventas' }}</span>
                                    </div>
                                </div>
                                <div class="fi-section-content">
                                    <div class="overflow-hidden">
                                        <div class="overflow-x-auto" style="max-height: 225px;">
                                        <div class="fi-ta-content relative divide-y divide-gray-200 overflow-x-auto dark:divide-white/10 dark:border-t-white/10">
                                            <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                                                <thead class="fi-ta-header-ctn divide-y divide-gray-200 dark:divide-white/5">
                                                    <tr class="bg-gray-50 dark:bg-white/5">
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">ID</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Cliente</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Termino</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Tipo Pago</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Subtotal</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Total</span>
                                                            </span>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody class="fi-ta-body divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                                                    @foreach($sales as $sale)
                                                    <tr class="fi-ta-row [@media(hover:hover)]:transition [@media(hover:hover)]:duration-75 hover:bg-gray-50 dark:hover:bg-white/5">
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                    {{ $sale['id'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                    {{ $sale['client'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $sale['type'] === 'Contado' ? 'bg-success-50 text-success-700 ring-success-600/10 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30' : 'bg-warning-50 text-warning-700 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30' }}">
                                                                    {{ $sale['type'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-700 ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30">
                                                                    {{ $sale['payment_method'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                    L {{ number_format($sale['subtotal'] ?? 0, 2) }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-ta-text text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                                                    L {{ number_format($sale['total'], 2) }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @elseif($employee_id && count($sales) === 0)
                        <div class="mb-6">
                            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">💰 Ventas del Día</h4>
                                    </div>
                                </div>
                                <div class="fi-section-content p-6 pt-0">
                                    <div class="text-center py-6">
                                        <div class="text-gray-400 text-3xl mb-2">💰</div>
                                        <p class="text-gray-500 text-sm">No hay ventas registradas para hoy</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Collections Table -->
                        @if($employee_id && count($payments) > 0)
                        <div class="mb-6 pt-4">
                            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">💰 Cobros Recibidos</h4>
                                    </div>
                                    <div class="inline-flex items-center justify-center rounded-full bg-primary-50 px-2.5 py-0.5 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                                        <span class="text-xs font-medium">{{ count($payments) }} {{ count($payments) == 1 ? 'cobro' : 'cobros' }}</span>
                                    </div>
                                </div>
                                <div class="fi-section-content">
                                    <div class="overflow-hidden">
                                        <div class="overflow-x-auto" style="max-height: 225px;">
                                            <div class="fi-ta-content relative divide-y divide-gray-200 overflow-x-auto dark:divide-white/10 dark:border-t-white/10">
                                            <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                                                <thead class="fi-ta-header-ctn divide-y divide-gray-200 dark:divide-white/5">
                                                    <tr class="bg-gray-50 dark:bg-white/5">
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Cliente</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Monto</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Método</span>
                                                            </span>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody class="fi-ta-body divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                                                    @foreach($payments as $payment)
                                                    <tr class="fi-ta-row [@media(hover:hover)]:transition [@media(hover:hover)]:duration-75 hover:bg-gray-50 dark:hover:bg-white/5">
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                    {{ $payment['client'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-ta-text text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                                                    L {{ number_format($payment['amount'], 2) }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-primary-50 text-primary-700 ring-primary-600/10 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/30">
                                                                    {{ $payment['method'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @elseif($employee_id && count($payments) === 0)
                        <div class="mb-6 pt-4">
                            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div>
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">💳 Cobros Recibidos</h4>
                                    </div>
                                </div>
                                <div class="fi-section-content p-6 pt-0">
                                    <div class="text-center py-6">
                                        <div class="text-gray-400 text-3xl mb-2">💳</div>
                                        <p class="text-gray-500 text-sm">No hay cobros registrados para hoy</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Remaining Products Section -->
                        @if($employee_id && count($remaining_products) > 0)
                        <div class="mb-6 mt-3">
                            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-x-3">
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">📦 Productos Sobrantes</h4>
                                        <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/30">
                                            {{ count($remaining_products) }} producto(s)
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-x-3">
                                        <div class="flex items-center gap-x-2">
                                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">Precios:</label>
                                            <select wire:model.live="type_price_id"
                                                class="fi-select-input block rounded-md border-gray-300 shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200">
                                                <option value="">Sin escala</option>
                                                @foreach($type_prices as $tp)
                                                    <option value="{{ $tp['id'] }}">{{ $tp['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        
                                        <button type="button" 
                                            wire:click="returnAllRemainingProducts"
                                            wire:loading.attr="disabled"
                                            wire:target="returnAllRemainingProducts"
                                            class="fi-btn fi-btn-size-sm relative inline-grid grid-flow-col items-center justify-center gap-1 rounded-md border-0 font-semibold outline-none transition duration-75 focus:ring-1 fi-color-success bg-success-50 text-success-600 hover:bg-success-100 dark:bg-success-400/10 dark:text-success-400 dark:hover:bg-success-400/20 focus:ring-success-500/50 dark:focus:ring-success-400/50 text-sm py-2 px-3"
                                            :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                            :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                            <span wire:loading.remove wire:target="returnAllRemainingProducts" class="text-sm">🔄</span>
                                            <span wire:loading wire:target="returnAllRemainingProducts" class="animate-spin text-sm">⏳</span>
                                            <span class="text-sm ml-1">Retornar Todos</span>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="fi-section-content p-6 pt-0">
                                    <div class="overflow-hidden" style="max-height: 225px;">
                                        <div class="fi-ta-ctn divide-y divide-gray-200 overflow-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/5 dark:bg-gray-900 dark:ring-white/10">
                                            <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                                                <thead class="fi-ta-header divide-y divide-gray-200 dark:divide-white/5">
                                                    <tr class="bg-gray-50 dark:bg-white/5">
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Producto</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-center">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-center">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Asignado</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-center">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-center">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Vendido</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-center">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-center">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Retornado</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-center">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-center">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Sobrante</span>
                                                            </span>
                                                        </th>
                                                        <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-center">
                                                            <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-center">
                                                                <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Efectivo Prod. Falt.</span>
                                                            </span>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody class="fi-ta-body divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                                                    @foreach($remaining_products as $product)
                                                    <tr class="fi-ta-row [@media(hover:hover)]:transition [@media(hover:hover)]:duration-75 hover:bg-gray-50 dark:hover:bg-white/5">
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4">
                                                                <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white font-medium flex items-center gap-x-2">
                                                                    {{ $product['product_name'] }}
                                                                    <span class="fi-badge inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                                                                        {{ $product['product_code'] }}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4 text-center">
                                                                <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white font-semibold">
                                                                    {{ $product['quantity_assigned'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4 text-center">
                                                                <div class="fi-ta-text text-sm leading-6 text-blue-600 dark:text-blue-400 font-semibold">
                                                                    {{ $product['quantity_sold'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4 text-center">
                                                                @if($product['returned_quantity'] > 0)
                                                                    <!-- Mostrar etiqueta cuando el producto tiene cantidad retornada -->
                                                                    <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-3 py-2 text-sm font-semibold ring-1 ring-inset bg-blue-50 text-blue-700 ring-blue-600/10 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">
                                                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                                        </svg>
                                                                        {{ $product['returned_quantity'] }}
                                                                        <span class="text-xs opacity-75 ml-1">(Registrado)</span>
                                                                    </div>
                                                                @else
                                                                    <!-- Mostrar input editable cuando no hay cantidad retornada -->
                                                                    <input 
                                                                        type="number" 
                                                                        min="0" 
                                                                        step="0.01"
                                                                        wire:model.live="remaining_products.{{ $loop->index }}.returned_quantity"
                                                                        wire:change="updateReturnedQuantity({{ $product['id'] }}, $event.target.value)"
                                                                        class="fi-input block w-20 mx-auto border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm text-center"
                                                                        placeholder="0"
                                                                    />
                                                                @endif
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4 text-center">
                                                                <div class="fi-badge inline-flex items-center gap-x-1 rounded-md px-2 py-1 text-sm font-bold ring-1 ring-inset {{ $product['remaining'] > 0 ? 'bg-amber-50 text-amber-700 ring-amber-600/10 dark:bg-amber-400/10 dark:text-amber-400 dark:ring-amber-400/30' : 'bg-green-50 text-green-700 ring-green-600/10 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30' }}">
                                                                    {{ $product['remaining'] }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                            <div class="fi-ta-col-wrp px-3 py-4 text-center">
                                                                <div class="fi-ta-text text-sm font-semibold leading-6 {{ ($product['shortage_cash'] ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-gray-400 dark:text-gray-500' }}">
                                                                    L {{ number_format($product['shortage_cash'] ?? 0, 2) }}
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @elseif($employee_id && count($remaining_products) === 0)
                        <div class="mb-6 mt-3">
                            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                <div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-x-3">
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">📦 Productos Sobrantes</h4>
                                    </div>
                                </div>
                                <div class="fi-section-content p-6 pt-0">
                                    <div class="text-center py-6">
                                        <div class="text-gray-400 text-3xl mb-2">✅</div>
                                        <p class="text-gray-500 text-sm">No hay productos sobrantes - Todo vendido</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <!-- Product Returns Section -->
                        @if($employee_id)
                        <div class="mb-6 mt-3">
                            <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" x-data="{ showReturnForm: false }">
                                <div class="fi-section-header-ctn flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="flex items-center gap-x-3">
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">🔄 Devoluciones de Productos</h4>
                                        <button type="button" @click="showReturnForm = !showReturnForm"
                                            class="fi-btn fi-btn-size-xs relative inline-grid grid-flow-col items-center justify-center gap-0.5 rounded-md border-0 font-semibold outline-none transition duration-75 focus:ring-1 fi-color-primary bg-primary-50 text-primary-600 hover:bg-primary-100 dark:bg-primary-400/10 dark:text-primary-400 dark:hover:bg-primary-400/20 focus:ring-primary-500/50 dark:focus:ring-primary-400/50 text-xs py-1 px-2"
                                            :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                            :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                            <span class="text-xs" x-text="showReturnForm ? '➖' : '➕'"></span>
                                            <span class="text-xs ml-1" x-text="showReturnForm ? 'Ocultar' : 'Agregar'"></span>
                                        </button>
                                    </div>
                                    <div class="inline-flex items-center justify-center rounded-full bg-orange-50 px-2.5 py-0.5 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400">
                                        <span class="text-xs font-medium">{{ count($returns) }} {{ count($returns) == 1 ? 'devolución' : 'devoluciones' }}</span>
                                    </div>
                                </div>
                                <div class="fi-section-content p-6">
                                    <!-- Return Form (Collapsible) -->
                                    <div x-show="showReturnForm" 
                                         x-transition:enter="transition ease-out duration-300" 
                                         x-transition:enter-start="opacity-0 transform -translate-y-4 scale-95" 
                                         x-transition:enter-end="opacity-100 transform translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-200"
                                         x-transition:leave-start="opacity-100 transform translate-y-0 scale-100" 
                                         x-transition:leave-end="opacity-0 transform -translate-y-4 scale-95"
                                         class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg border border-gray-200 dark:border-gray-600 mb-4">
                                        <h5 class="text-sm font-medium text-gray-950 dark:text-white mb-3">Registrar Nueva Devolución</h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                            <!-- Product Selection -->
                                            <div class="relative">
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white mb-1">
                                                    Producto
                                                </label>
                                                <div class="relative">
                                                    <input
                                                        wire:model.live="product_search"
                                                        type="text"
                                                        placeholder="{{ $selected_product ? $selected_product->name : 'Escriba al menos 3 caracteres para buscar...' }}"
                                                        @if($selected_product) readonly @endif
                                                        class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm pl-3 {{ $selected_product ? 'pr-10 bg-gray-50 dark:bg-gray-600' : '' }}" />
                                                    
                                                    @if($selected_product)
                                                        <!-- Botón X para limpiar selección -->
                                                        <div class="absolute top-0 right-0 pt-2 px-2 flex items-center justify-end" style="width: 100%;">
                                                            <button
                                                                wire:click="clearProductSelection"
                                                                type="button"
                                                                class="flex items-center justify-center h-5 w-5 text-gray-400 hover:text-red-500 dark:text-gray-500 dark:hover:text-red-400 transition-colors duration-200 rounded-full hover:bg-red-50 dark:hover:bg-red-900/20"
                                                                title="Limpiar selección">
                                                                <svg fill="currentColor" viewBox="0 0 20 20" class="h-4 w-4">
                                                                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    @endif
                                                </div>
                                                
                                                <!-- Dropdown de resultados -->
                                                @if($show_product_dropdown && count($filtered_products) > 0)
                                                    <div class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm">
                                                        @foreach($filtered_products as $product)
                                                            <button
                                                                wire:click="selectProduct({{ $product['id'] }})"
                                                                type="button"
                                                                class="w-full text-left px-4 py-3 text-sm text-gray-900 dark:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700 focus:bg-gray-100 dark:focus:bg-gray-700 focus:outline-none transition-colors duration-150 border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                                                                <div class="flex items-start justify-between">
                                                                    <div class="flex-1 min-w-0">
                                                                        <div class="font-medium truncate">{{ $product['name'] }}</div>
                                                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                                            <span>Código: {{ $product['code'] }}</span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                @endif
                                                
                                                <!-- Mensaje cuando no hay resultados -->
                                                @if($show_product_dropdown && count($filtered_products) === 0 && strlen($product_search) >= 3)
                                                    <div class="absolute z-50 mt-1 w-full bg-white dark:bg-gray-800 shadow-lg rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm">
                                                        <div class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">
                                                            <div class="flex items-center">
                                                                <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                                </svg>
                                                                No se encontraron productos
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                                
                                                @error('return_product_id') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>

                                            <!-- Return Type -->
                                            <div>
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white mb-1">
                                                    Tipo de Devolución
                                                </label>
                                                <select wire:model="return_type" class="fi-select-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm">
                                                    <option value="">Seleccione el tipo</option>
                                                    @foreach($return_types as $key => $label)
                                                        <option value="{{ $key }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                @error('return_type') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>

                                            <!-- Quantity -->
                                            <div>
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white mb-1">
                                                    Cantidad
                                                </label>
                                                <input type="number" min="1" wire:model="return_quantity" class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm" />
                                                @error('return_quantity') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                            <!-- Reason -->
                                            <div>
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white mb-1">
                                                    Motivo
                                                </label>
                                                <textarea wire:model="return_reason" rows="2" placeholder="Describa el motivo de la devolución" class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm"></textarea>
                                                @error('return_reason') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>

                                            <!-- Affects Inventory Checkbox -->
                                            <div class="flex items-center">
                                                <div class="flex items-center h-5">
                                                    <input type="checkbox" wire:model="return_affects_inventory" class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm focus:border-primary-300 focus:ring focus:ring-primary-200 focus:ring-opacity-50 dark:border-gray-600 dark:bg-gray-700 dark:focus:border-primary-600 dark:focus:ring-primary-600" />
                                                </div>
                                                <div class="px-2 text-sm">
                                                    <label class="font-medium text-gray-700 dark:text-gray-300">Afecta Inventario</label>
                                                    <p class="text-gray-500 dark:text-gray-400 text-xs">Marque si esta devolución debe registrar movimiento de inventario</p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Add Return Button -->
                                        <div class="flex justify-end mt-4">
                                            <button type="button" wire:click="addReturn" class="fi-btn fi-btn-size-sm relative inline-grid grid-flow-col items-center justify-center gap-1 rounded-lg border-0 font-semibold outline-none transition duration-75 focus:ring-2 fi-color-primary bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 focus:ring-primary-500/50 dark:focus:ring-primary-400/50 text-xs py-2 px-3">
                                                <span class="mr-1">➕</span> Registrar Devolución
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Returns Table -->
                                    @if(count($returns) > 0)
                                    <div class="overflow-hidden">
                                        <div class="overflow-x-auto" style="max-height: 225px;">
                                            <div class="fi-ta-content relative divide-y divide-gray-200 overflow-x-auto dark:divide-white/10 dark:border-t-white/10">
                                                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                                                    <thead class="fi-ta-header-ctn divide-y divide-gray-200 dark:divide-white/5">
                                                        <tr class="bg-gray-50 dark:bg-white/5">
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Producto</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Cantidad</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Tipo</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Motivo</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Inventario</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Hora</span>
                                                                </span>
                                                            </th>

                                                        </tr>
                                                    </thead>
                                                    <tbody class="fi-ta-body divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                                                        @foreach($returns as $return)
                                                        <tr class="fi-ta-row [@media(hover:hover)]:transition [@media(hover:hover)]:duration-75 hover:bg-gray-50 dark:hover:bg-white/5">
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                        {{ $return['product_name'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                        {{ $return['quantity'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 py-1 fi-color-custom bg-orange-50 text-orange-600 ring-orange-600/10 dark:bg-orange-400/10 dark:text-orange-400 dark:ring-orange-400/30">
                                                                        {{ $return['type'] }}
                                                                    </span>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white truncate max-w-32" title="{{ $return['reason'] }}">
                                                                        {{ $return['reason'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 py-1 {{ $return['affects_inventory'] === 'Sí' ? 'fi-color-success bg-green-50 text-green-600 ring-green-600/10 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30' : 'fi-color-gray bg-gray-50 text-gray-600 ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30' }}">
                                                                        {{ $return['affects_inventory'] }}
                                                                    </span>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                        {{ $return['created_at'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <button type="button" wire:click="deleteReturn({{ $return['id'] }})" class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus:ring-2 -m-2 h-8 w-8 text-gray-400 hover:text-gray-500 focus:ring-primary-600 dark:text-gray-500 dark:hover:text-gray-400 dark:focus:ring-primary-500" title="Eliminar devolución">
                                                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                                        </svg>
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    @else
                                    <div class="text-center py-6">
                                        <div class="text-gray-400 text-3xl mb-2">🔄</div>
                                        <p class="text-gray-500 text-sm">No hay devoluciones registradas para hoy</p>
                                    </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Movements Section (Changes and Royalties) -->
                        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6">
                            <div class="fi-section-header flex flex-col gap-3 p-6 pb-0">
                                <div class="flex items-center justify-between gap-x-3">
                                    <div class="flex items-center gap-x-3">
                                        <h4 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">🔄 Movimientos (Cambios y Regalías)</h4>
                                    </div>
                                    <div class="inline-flex items-center justify-center rounded-full bg-blue-50 px-2.5 py-0.5 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400">
                                        <span class="text-xs font-medium">{{ count($movements) }} {{ count($movements) == 1 ? 'movimiento' : 'movimientos' }}</span>
                                    </div>
                                </div>
                                <div class="fi-section-content p-6">


                                    <!-- Movements Table -->
                                    @if(count($movements) > 0)
                                    <div class="overflow-hidden">
                                        <div class="overflow-x-auto" style="max-height: 225px;">
                                            <div class="fi-ta-content relative divide-y divide-gray-200 overflow-x-auto dark:divide-white/10 dark:border-t-white/10">
                                                <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                                                    <thead class="fi-ta-header-ctn divide-y divide-gray-200 dark:divide-white/5">
                                                        <tr class="bg-gray-50 dark:bg-white/5">
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Producto</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Cantidad</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Tipo</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Nota</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Hora</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Venta</span>
                                                                </span>
                                                            </th>
                                                            <th class="fi-ta-header-cell px-3 py-3.5 sm:first-of-type:ps-6 sm:last-of-type:pe-6 text-start">
                                                                <span class="group flex w-full items-center gap-x-1 whitespace-nowrap justify-start">
                                                                    <span class="fi-ta-header-cell-label text-sm font-semibold text-gray-950 dark:text-white">Acciones</span>
                                                                </span>
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="fi-ta-body divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                                                        @foreach($movements as $movement)
                                                        <tr class="fi-ta-row [@media(hover:hover)]:transition [@media(hover:hover)]:duration-75 hover:bg-gray-50 dark:hover:bg-white/5">
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                        {{ $movement['product_name'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                        {{ $movement['quantity'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 py-1 {{ $movement['type_raw'] === 'change' ? 'fi-color-info bg-blue-50 text-blue-600 ring-blue-600/10 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30' : 'fi-color-success bg-green-50 text-green-600 ring-green-600/10 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30' }}">
                                                                        {{ $movement['type'] }}
                                                                    </span>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white truncate max-w-32" title="{{ $movement['note'] }}">
                                                                        {{ $movement['note'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    <div class="fi-ta-text text-sm leading-6 text-gray-950 dark:text-white">
                                                                        {{ $movement['created_at'] }}
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-3 sm:last-of-type:pe-3">
                                                                <div class="fi-ta-col-wrp px-3 py-4">
                                                                    @if($movement['sale_id'])
                                                                        <span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 py-1 fi-color-primary bg-blue-50 text-blue-600 ring-blue-600/10 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">
                                                                            {{ $movement['sale_info'] }}
                                                                        </span>
                                                                    @else
                                                                        <span class="text-xs text-gray-400">—</span>
                                                                    @endif
                                                                </div>
                                                            </td>

                                                        </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    @else
                                    <div class="text-center py-6">
                                        <div class="text-gray-400 text-3xl mb-2">🔄</div>
                                        <p class="text-gray-500 text-sm">No hay movimientos registrados para hoy</p>
                                    </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- Right Column - Financial Summary -->
                <div class="custom-col-35">
                    <div class="custom-bg-light p-4 rounded-lg border border-gray-200">
                        @if($employee_id)
                        <!-- Financial Summary -->
                        <div class="fi-section rounded-lg bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mb-6">
                            <div class="border-b border-gray-200 dark:border-gray-700 p-3">
                                <div class="fi-section-header mb-1">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-x-1">
                                            <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">🧮</span>
                                            <h3 class="fi-section-header-heading text-md font-semibold leading-5 text-gray-950 dark:text-white">
                                                Resumen Financiero
                                            </h3>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="p-1">
                                <ul class="fi-ta-list divide-y divide-gray-200 dark:divide-gray-700">
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">💰</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Ventas al Contado</span>
                                            </div>
                                            <span class="font-semibold text-gray-950 dark:text-white">L {{ number_format($total_cash_sales, 2) }}</span>
                                        </div>
                                        <!-- Desglose de Ventas al Contado -->
                                        <div class="flex justify-between items-center mt-1 pl-8">
                                            <span class="text-xs text-gray-600 dark:text-gray-400">Efectivo</span>
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">L {{ number_format($total_cash_sales - $total_deposit_sales, 2) }}</span>
                                        </div>
                                        <div class="flex justify-between items-center mt-1 pl-8">
                                            <span class="text-xs text-gray-600 dark:text-gray-400">Depósitos</span>
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">L {{ number_format($total_deposit_sales, 2) }}</span>
                                        </div>
                                    </li>
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">🏦</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Ventas a Crédito</span>
                                            </div>
                                            <span class="font-semibold text-gray-950 dark:text-white">L {{ number_format($total_credit_sales, 2) }}</span>
                                        </div>
                                    </li>
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">💰</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Total Cobros</span>
                                            </div>
                                            <span class="font-semibold text-gray-950 dark:text-white">L {{ number_format($total_collections, 2) }}</span>
                                        </div>
                                        <!-- Desglose de Cobros Realizados -->
                                        <div class="flex justify-between items-center mt-1 pl-8">
                                            <span class="text-xs text-gray-600 dark:text-gray-400">Efectivo</span>
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">L {{ number_format($total_cash_collections, 2) }}</span>
                                        </div>
                                        <div class="flex justify-between items-center mt-1 pl-8">
                                            <span class="text-xs text-gray-600 dark:text-gray-400">Depósitos</span>
                                            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">L {{ number_format($total_deposit_collections, 2) }}</span>
                                        </div>
                                    </li>
                                    <li class="fi-ta-item p-2 bg-gray-50 dark:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">📈</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Total Ventas</span>
                                            </div>
                                            <span class="font-bold text-gray-950 dark:text-white">L {{ number_format($total_sales, 2) }}</span>
                                        </div>
                                    </li>
                                    <!-- Separator -->
                                    <li class="fi-ta-item px-2 py-1 bg-gray-100 dark:bg-gray-600">
                                        <div class="h-px w-full bg-gray-200 dark:bg-gray-500"></div>
                                    </li>
                                    <!-- Cash Amount Input -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">💰</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Efectivo Recibido</span>
                                            </div>
                                            <div class="w-32">
                                                <input type="number" step="0.01" placeholder="0.00" 
                                                    wire:model.lazy="cash_received"
                                                    wire:change="updateCashReceived($event.target.value)"
                                                    class="fi-input block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                                    :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'" />
                                        </div>
                                    </div>
                                    </li>
                                    
                                    <!-- Total Efectivo Producto Faltante -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700 bg-amber-50/50 dark:bg-amber-400/5">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-amber-50 p-0.5 text-amber-500 dark:bg-amber-500/10 dark:text-amber-400 text-sm">💎</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Total Efectivo Prod. Faltante</span>
                                            </div>
                                            <span class="font-semibold text-amber-600 dark:text-amber-400">L {{ number_format($product_shortage_total, 2) }}</span>
                                        </div>
                                    </li>
                                    
                                    <!-- Efectivo Esperado -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">💵</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Efectivo Esperado <span class="text-xs text-gray-500 dark:text-gray-400">(mín. L 0.00)</span></span>
                                            </div>
                                            <span class="font-semibold text-gray-950 dark:text-white">L {{ number_format($total_cash_expected, 2) }}</span>
                                        </div>
                                    </li>
                                    
                                    <!-- Total Gastos (se resta del efectivo disponible) -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-red-50 p-0.5 text-red-500 dark:bg-red-500/10 dark:text-red-400 text-sm">💸</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Total Gastos <span class="text-xs text-gray-500 dark:text-gray-400">(se resta)</span></span>
                                            </div>
                                            <span class="font-semibold text-red-600 dark:text-red-400">L {{ number_format($total_bills, 2) }}</span>
                                        </div>
                                    </li>
                                    
                                    <!-- Diferencia de Efectivo -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">🔄</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Diferencia de Efectivo</span>
                                            </div>
                                            <span class="font-semibold {{ $cash_difference < 0 ? 'text-red-600' : 'text-green-600' }} dark:{{ $cash_difference < 0 ? 'text-red-400' : 'text-green-400' }}">L {{ number_format($cash_difference, 2) }}</span>
                                        </div>
                                    </li>
                                    
                                    <!-- Separator -->
                                    <li class="fi-ta-item px-2 py-1 bg-gray-100 dark:bg-gray-600">
                                        <div class="h-px w-full bg-gray-200 dark:bg-gray-500"></div>
                                    </li>
                                    
                                    <!-- Depósitos Realizados -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">💸</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Depósitos Realizados</span>
                                            </div>
                                            <span class="font-semibold text-gray-950 dark:text-white">L {{ number_format($deposits_made, 2) }}</span>
                                        </div>
                                    </li>
                                    
                                    <!-- Depósitos Esperados -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">🏦</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Depósitos Esperados</span>
                                            </div>
                                            <span class="font-semibold text-gray-950 dark:text-white">L {{ number_format($total_deposit_expected, 2) }}</span>
                                        </div>
                                    </li>
                                    
                                    <!-- Diferencia de Depósitos -->
                                    <li class="fi-ta-item p-2 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <div class="flex justify-between items-center">
                                            <div class="flex items-center gap-x-1">
                                                <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">🔄</span>
                                                <span class="text-sm font-medium text-gray-950 dark:text-white">Diferencia de Depósitos</span>
                                            </div>
                                            <span class="font-semibold {{ $deposit_difference < 0 ? 'text-red-600' : 'text-green-600' }} dark:{{ $deposit_difference < 0 ? 'text-red-400' : 'text-green-400' }}">L {{ number_format($deposit_difference, 2) }}</span>
                                        </div>
                                    </li>
                                    

                                </ul>
                            </div>
                        </div>

                        <!-- Deposits Management Section -->
                        <div class="fi-section rounded-lg bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mt-2" x-data="{ showDepositForm: false }">
                            <div class="fi-section-header mb-1">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-x-1">
                                        <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-primary-50 p-0.5 text-primary-500 dark:bg-primary-500/10 dark:text-primary-400 text-sm">🏦</span>
                                        <h3 class="fi-section-header-heading text-md font-semibold leading-5 text-gray-950 dark:text-white">
                                            Gestión de Depósitos
                                        </h3>
                                    </div>
                                    <button type="button" @click="showDepositForm = !showDepositForm"
                                        class="fi-btn fi-btn-size-xs relative inline-grid grid-flow-col items-center justify-center gap-0.5 rounded-md border-0 font-semibold outline-none transition duration-75 focus:ring-1 fi-color-primary bg-primary-50 text-primary-600 hover:bg-primary-100 dark:bg-primary-400/10 dark:text-primary-400 dark:hover:bg-primary-400/20 focus:ring-primary-500/50 dark:focus:ring-primary-400/50 text-xs py-1 px-2"
                                        :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                        :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                        <span class="text-xs" x-text="showDepositForm ? '➖ Ocultar' : '➕ Agregar'"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="p-1">
                                <div class="space-y-2">
                                    <!-- Deposit Form (Collapsible) -->
                                    <div x-show="showDepositForm" x-transition:enter="transition ease-out duration-300" 
                                         x-transition:enter-start="opacity-0 transform -translate-y-2 scale-95" 
                                         x-transition:enter-end="opacity-100 transform translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-200"
                                         x-transition:leave-start="opacity-100 transform translate-y-0 scale-100" 
                                         x-transition:leave-end="opacity-0 transform -translate-y-2 scale-95"
                                         class="bg-gray-50 dark:bg-gray-700 p-2 rounded-lg border border-gray-200 dark:border-gray-600 mb-2">
                                        
                                        <div class="space-y-2">
                                            <!-- Amount Input -->
                                            <div class="mb-1">
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                    Monto
                                                </label>
                                                <input type="number" step="0.01" placeholder="0.00" wire:model="deposit_amount"
                                                    class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm" />
                                                @error('deposit_amount') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>

                                            <!-- Bank Selection -->
                                            <div class="mb-1">
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                    Banco
                                                </label>
                                                <select wire:model="deposit_bank" class="fi-select-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm">
                                                    <option value="">Seleccione un banco</option>
                                                    @foreach($banks as $bank)
                                                        <option value="{{ $bank }}">{{ $bank }}</option>
                                                    @endforeach
                                                </select>
                                                @error('deposit_bank') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>

                                            <!-- Reference Input -->
                                            <div class="mb-1">
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                    Referencia
                                                </label>
                                                <input type="text" placeholder="Número de referencia" wire:model="deposit_reference"
                                                    class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm" />
                                                @error('deposit_reference') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                        </div>

                                        <!-- Add Deposit Button -->
                                        <div class="flex justify-end mt-3">
                                            <button type="button" wire:click="saveDeposit"
                                                class="fi-btn fi-btn-size-sm relative inline-grid grid-flow-col items-center justify-center gap-1 rounded-lg border-0 font-semibold outline-none transition duration-75 focus:ring-2 fi-color-primary bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 focus:ring-primary-500/50 dark:focus:ring-primary-400/50 text-xs py-2 px-2"
                                                :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                                :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                                <span class="mr-1">💾</span> Guardar Depósito
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Deposits Table -->
                                    <div class="mt-2">
                                        <h4 class="fi-section-header-heading text-xs font-medium text-gray-950 dark:text-white mb-1">Depósitos Registrados</h4>
                                        <div class="fi-ta rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                            <div class="fi-ta-content relative overflow-x-auto">
                                                <table class="fi-ta-table w-full divide-y divide-gray-200 text-start dark:divide-white/5">
                                                    <thead>
                                                        <tr class="bg-gray-50 dark:bg-gray-700/50">
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Banco</span>
                                                            </th>
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Referencia</span>
                                                            </th>
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Monto</span>
                                                            </th>
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 text-right">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Acciones</span>
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                                        @forelse($deposits as $deposit)
                                                        <tr class="fi-ta-row hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs text-gray-700 dark:text-gray-300">
                                                                {{ $deposit['bank'] }}
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs text-gray-700 dark:text-gray-300">
                                                                {{ $deposit['reference_number'] }}
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs font-medium text-gray-900 dark:text-white">
                                                                L {{ number_format($deposit['amount'], 2) }}
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs text-right">
                                                                <button type="button" wire:click="deleteDeposit({{ $deposit['id'] }})"
                                                                    class="fi-icon-btn fi-icon-btn-size-xs relative flex items-center justify-center rounded-md outline-none transition duration-75 hover:bg-gray-50 focus:ring-1 dark:hover:bg-gray-700 fi-color-danger text-danger-600 hover:text-danger-500 focus:ring-danger-500/50 dark:text-danger-500 dark:hover:text-danger-400 dark:focus:ring-danger-400/50"
                                                                    :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                                                    :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                                                    <span>🗑️</span>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        @empty
                                                        <tr>
                                                            <td colspan="4" class="px-2 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                                                <div class="flex flex-col items-center justify-center">
                                                                    <span class="text-lg mb-1">💰</span>
                                                                    <p class="text-xs">No hay depósitos registrados</p>
                                                                    <p class="text-xs mt-0.5">Utilice el botón + para agregar un depósito</p>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                                
                                                <!-- Total Depósitos Realizados -->
                                                <div class="mt-2 p-2 bg-gray-50 dark:bg-gray-700 rounded-md">
                                                    <div class="flex justify-between items-center">
                                                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Total Depósitos Realizados:</span>
                                                        <span class="text-sm font-semibold text-gray-900 dark:text-white">L {{ number_format(collect($deposits)->sum('amount'), 2) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Bills Management Section -->
                        <div class="fi-section rounded-lg bg-white p-2 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 mt-2" x-data="{ showBillForm: false }">
                            <div class="fi-section-header mb-1">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-x-1">
                                        <span class="fi-section-header-icon flex items-center justify-center rounded-md bg-red-50 p-0.5 text-red-500 dark:bg-red-500/10 dark:text-red-400 text-sm">💸</span>
                                        <h3 class="fi-section-header-heading text-md font-semibold leading-5 text-gray-950 dark:text-white">
                                            Gestión de Gastos
                                        </h3>
                                    </div>
                                    <button type="button" @click="showBillForm = !showBillForm"
                                        class="fi-btn fi-btn-size-xs relative inline-grid grid-flow-col items-center justify-center gap-0.5 rounded-md border-0 font-semibold outline-none transition duration-75 focus:ring-1 fi-color-primary bg-primary-50 text-primary-600 hover:bg-primary-100 dark:bg-primary-400/10 dark:text-primary-400 dark:hover:bg-primary-400/20 focus:ring-primary-500/50 dark:focus:ring-primary-400/50 text-xs py-1 px-2"
                                        :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                        :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                        <span class="text-xs" x-text="showBillForm ? '➖ Ocultar' : '➕ Agregar'"></span>
                                    </button>
                                </div>
                            </div>
                            <div class="p-1">
                                <div class="space-y-2">
                                    <!-- Bill Form (Collapsible) -->
                                    <div x-show="showBillForm" x-transition:enter="transition ease-out duration-300" 
                                         x-transition:enter-start="opacity-0 transform -translate-y-2 scale-95" 
                                         x-transition:enter-end="opacity-100 transform translate-y-0 scale-100"
                                         x-transition:leave="transition ease-in duration-200"
                                         x-transition:leave-start="opacity-100 transform translate-y-0 scale-100" 
                                         x-transition:leave-end="opacity-0 transform -translate-y-2 scale-95"
                                         class="bg-gray-50 dark:bg-gray-700 p-2 rounded-lg border border-gray-200 dark:border-gray-600 mb-2">
                                        
                                        <div class="space-y-2">
                                            <!-- Description Input -->
                                            <div class="mb-1">
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                    Descripción
                                                </label>
                                                <input type="text" placeholder="Descripción del gasto" wire:model="bill_description"
                                                    class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm" />
                                                @error('bill_description') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>

                                            <!-- Amount Input -->
                                            <div class="mb-1">
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                    Monto
                                                </label>
                                                <input type="number" step="0.01" placeholder="0.00" wire:model="bill_amount"
                                                    class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm" />
                                                @error('bill_amount') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>

                                            <!-- Reference Input -->
                                            <div class="mb-1">
                                                <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-5 text-gray-950 dark:text-white">
                                                    Referencia
                                                </label>
                                                <input type="text" placeholder="Número de referencia o factura" wire:model="bill_reference"
                                                    class="fi-input block w-full border-gray-300 rounded-lg shadow-sm outline-none focus:ring-2 focus:ring-primary-600 focus:border-primary-600 disabled:opacity-70 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 dark:focus:ring-primary-500 dark:focus:border-primary-500 text-sm" />
                                                @error('bill_reference') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                                            </div>
                                        </div>

                                        <!-- Add Bill Button -->
                                        <div class="flex justify-end mt-3">
                                            <button type="button" wire:click="saveBill"
                                                class="fi-btn fi-btn-size-sm relative inline-grid grid-flow-col items-center justify-center gap-1 rounded-lg border-0 font-semibold outline-none transition duration-75 focus:ring-2 fi-color-primary bg-primary-600 text-white hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 focus:ring-primary-500/50 dark:focus:ring-primary-400/50 text-xs py-2 px-2"
                                                :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                                :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                                <span class="mr-1">💾</span> Guardar Gasto
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Bills Table -->
                                    <div class="mt-2">
                                        <h4 class="fi-section-header-heading text-xs font-medium text-gray-950 dark:text-white mb-1">Gastos Registrados</h4>
                                        <div class="fi-ta rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                            <div class="fi-ta-content relative overflow-x-auto">
                                                <table class="fi-ta-table w-full divide-y divide-gray-200 text-start dark:divide-white/5">
                                                    <thead>
                                                        <tr class="bg-gray-50 dark:bg-gray-700/50">
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Descripción</span>
                                                            </th>
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Referencia</span>
                                                            </th>
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Monto</span>
                                                            </th>
                                                            <th class="fi-ta-header-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 text-right">
                                                                <span class="fi-ta-header-cell-label text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Acciones</span>
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                                        @forelse($bills as $bill)
                                                        <tr class="fi-ta-row hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors duration-150">
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs text-gray-700 dark:text-gray-300">
                                                                {{ $bill['description'] }}
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs text-gray-700 dark:text-gray-300">
                                                                {{ $bill['reference_number'] }}
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs font-medium text-gray-900 dark:text-white">
                                                                L {{ number_format($bill['amount'], 2) }}
                                                            </td>
                                                            <td class="fi-ta-cell p-0 first-of-type:ps-1 last-of-type:pe-1 sm:first-of-type:ps-2 sm:last-of-type:pe-2 py-1.5 whitespace-nowrap text-xs text-right">
                                                                <button type="button" wire:click="deleteBill({{ $bill['id'] }})"
                                                                    class="fi-icon-btn fi-icon-btn-size-xs relative flex items-center justify-center rounded-md outline-none transition duration-75 hover:bg-gray-50 focus:ring-1 dark:hover:bg-gray-700 fi-color-danger text-danger-600 hover:text-danger-500 focus:ring-danger-500/50 dark:text-danger-500 dark:hover:text-danger-400 dark:focus:ring-danger-400/50"
                                                                    :class="{'opacity-50 cursor-not-allowed': $wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'}"
                                                                    :disabled="$wire.current_reconciliation && $wire.current_reconciliation.status.value === 'completed'">
                                                                    <span>🗑️</span>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        @empty
                                                        <tr>
                                                            <td colspan="4" class="px-2 py-3 text-center text-xs text-gray-500 dark:text-gray-400">
                                                                <div class="flex flex-col items-center justify-center">
                                                                    <span class="text-lg mb-1">💸</span>
                                                                    <p class="text-xs">No hay gastos registrados</p>
                                                                    <p class="text-xs mt-0.5">Utilice el botón + para agregar un gasto</p>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        @endforelse
                                                    </tbody>
                                                </table>
                                                
                                                <!-- Total Gastos Realizados -->
                                                <div class="mt-2 p-2 bg-red-50 dark:bg-red-700 rounded-md">
                                                    <div class="flex justify-between items-center">
                                                        <span class="text-xs font-medium text-red-700 dark:text-red-300">Total Gastos Realizados:</span>
                                                        <span class="text-sm font-semibold text-red-900 dark:text-white">L {{ number_format(collect($bills)->sum('amount'), 2) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Mensajes de error o éxito -->
                        @if(session()->has('error'))
                        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mt-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-red-500" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium">{{ session('error') }}</p>
                                </div>
                            </div>
                        </div>
                        @endif
                        
                        
                        <!-- Reconciliation Status -->
                        @if($reconciliation_created && $current_reconciliation)
                        <div class="bg-white border border-gray-200 rounded-lg p-4 mt-6">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    @if($current_reconciliation->status->value === 'completed')
                                    <svg class="h-5 w-5 text-green-500" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                    </svg>
                                    @else
                                    <svg class="h-5 w-5 text-blue-500" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                    @endif
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-gray-800">
                                        @if($current_reconciliation->status->value === 'completed')
                                        Cuadre Completado
                                        @else
                                        Cuadre Inicializado
                                        @endif
                                    </h3>
                                    <div class="mt-2 text-sm text-gray-700">
                                        <p>Se ha creado un cuadre diario con estado 
                                            @if($current_reconciliation->status->value === 'completed')
                                            <strong class="text-green-600">COMPLETADO</strong>
                                            @else
                                            <strong>PENDIENTE</strong>
                                            @endif
                                            para el empleado seleccionado.</p>
                                        <p class="mt-1">ID del Cuadre: <strong>#{{ $current_reconciliation->id }}</strong></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif
                        @else
                        <div class="text-center py-12">
                            <div class="text-gray-400 text-6xl mb-4">👤</div>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Seleccione un Empleado</h3>
                            <p class="text-gray-500">Elija un empleado para ver sus ventas y cobros del día</p>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>