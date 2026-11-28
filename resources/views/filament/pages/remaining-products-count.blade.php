<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="rpc-employee" class="text-sm font-medium text-gray-950 dark:text-white">Empleado</label>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input.select id="rpc-employee" wire:model.live="employee_id">
                            <option value="">Seleccione un empleado</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee['id'] }}">{{ $employee['name'] }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="rpc-date" class="text-sm font-medium text-gray-950 dark:text-white">Fecha</label>
                    <x-filament::input.wrapper class="mt-1">
                        <x-filament::input id="rpc-date" type="date" wire:model.live="date" />
                    </x-filament::input.wrapper>
                </div>
            </div>
        </x-filament::section>

        <div wire:loading.class="opacity-50" wire:target="employee_id,date">
            @if (! filled($employee_id))
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Seleccione un empleado para ver sus productos asignados.</p>
                </x-filament::section>
            @elseif (empty($rows))
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Sin productos asignados para esta fecha.</p>
                </x-filament::section>
            @else
                @if ($closed)
                    <div role="status" class="mb-4 rounded-lg bg-warning-50 p-4 text-sm font-medium text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400">
                        🔒 El cuadre de este día ya fue cerrado. Solo lectura.
                    </div>
                @endif

                <x-filament::section>
                    <x-slot name="heading">Productos asignados ({{ count($rows) }})</x-slot>
                    <x-slot name="headerEnd">{{ $this->returnAllAction }}</x-slot>

                    <div class="overflow-x-auto">
                        <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 text-start dark:divide-white/5">
                            <thead>
                                <tr class="text-sm font-semibold text-gray-950 dark:text-white">
                                    <th scope="col" class="px-3 py-3.5 text-start">Producto</th>
                                    <th scope="col" class="px-3 py-3.5 text-center">Asignado</th>
                                    <th scope="col" class="px-3 py-3.5 text-center">Vendido</th>
                                    <th scope="col" class="px-3 py-3.5 text-center">Cambios</th>
                                    <th scope="col" class="px-3 py-3.5 text-center">Regalías</th>
                                    <th scope="col" class="px-3 py-3.5 text-center">Devuelto</th>
                                    <th scope="col" class="px-3 py-3.5 text-center">Faltante</th>
                                    @unless ($closed)
                                        <th scope="col" class="px-3 py-3.5"><span class="sr-only">Acciones</span></th>
                                    @endunless
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                                @foreach ($rows as $row)
                                    @php
                                        $typed = $counts[$row['id']] ?? '';
                                        $dirty = ! is_numeric($typed) || (float) $typed != (float) $row['returned_quantity'];
                                    @endphp
                                    <tr wire:key="rpc-row-{{ $row['id'] }}" class="text-sm text-gray-950 dark:text-white">
                                        <td class="px-3 py-4">
                                            {{ $row['product_name'] }}
                                            <span class="ms-2 rounded-md bg-gray-50 px-1.5 py-0.5 text-xs text-gray-600 ring-1 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400">{{ $row['product_code'] }}</span>
                                        </td>
                                        <td class="px-3 py-4 text-center">{{ $row['quantity_assigned'] }}</td>
                                        <td class="px-3 py-4 text-center">{{ $row['quantity_sold'] }}</td>
                                        <td class="px-3 py-4 text-center">{{ $row['changes_quantity'] }}</td>
                                        <td class="px-3 py-4 text-center">{{ $row['royalties_quantity'] }}</td>
                                        <td class="px-3 py-4 text-center">
                                            <input
                                                type="number" min="0" step="0.01" max="{{ $row['max_returnable'] }}"
                                                aria-label="Cantidad devuelta de {{ $row['product_name'] }}"
                                                wire:model="counts.{{ $row['id'] }}"
                                                wire:keydown.enter="save({{ $row['id'] }})"
                                                @disabled($closed)
                                                class="fi-input mx-auto block w-20 rounded-lg border-gray-300 text-center text-sm shadow-sm disabled:opacity-70 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200"
                                            />
                                            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">
                                                @if ($dirty && ! $closed) Sin guardar · @endif máx. {{ $row['max_returnable'] }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-4 text-center font-semibold">{{ $row['remaining'] }}</td>
                                        @unless ($closed)
                                            <td class="px-3 py-4 text-end">
                                                <x-filament::button size="sm" color="gray" wire:click="save({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="save({{ $row['id'] }})">
                                                    Guardar
                                                </x-filament::button>
                                            </td>
                                        @endunless
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        Faltante = asignado − vendido − cambios − regalías − devuelto. Presione Enter en la cantidad para guardar la fila.
                    </p>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
