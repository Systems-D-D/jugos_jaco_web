<x-filament-panels::page>
    @php
        $rows = $this->lineRows();
        $totals = $this->totals();
        $suggestions = $this->suggestions();
        $branchChosen = filled($this->data['branch_id'] ?? null);
    @endphp

    <form wire:submit="register" class="space-y-6">
        {{-- Cabecera del lote --}}
        {{ $this->form }}

        <x-filament::section>
            <x-slot name="heading">Productos</x-slot>
            <x-slot name="description">
                Busque cada producto y agréguelo al lote. La existencia mostrada es la actual.
            </x-slot>

            {{-- Buscador: Enter agrega el primer resultado --}}
            <div class="relative">
                <x-filament::input.wrapper
                    :disabled="! $branchChosen"
                    prefix-icon="heroicon-m-magnifying-glass"
                >
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        wire:keydown.enter.prevent="addFirstSuggestion"
                        :disabled="! $branchChosen"
                        :placeholder="$branchChosen
                            ? 'Escriba el nombre del producto…'
                            : 'Elija primero una sucursal'"
                    />
                </x-filament::input.wrapper>

                @if ($branchChosen && $suggestions->isNotEmpty())
                    <ul class="mt-2 divide-y divide-gray-200 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
                        @foreach ($suggestions as $suggestion)
                            <li>
                                <button
                                    type="button"
                                    wire:click="addLine({{ $suggestion['id'] }})"
                                    class="flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm transition hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
                                >
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ $suggestion['label'] }}
                                    </span>
                                    <span class="shrink-0 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                        {{ $suggestion['stock'] }}
                                    </span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @elseif ($branchChosen && filled(trim($this->search)))
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Ningún producto coincide con «{{ $this->search }}».
                    </p>
                @endif
            </div>

            {{-- Líneas del lote --}}
            <div class="mt-6">
                @if ($rows->isEmpty())
                    <div class="rounded-lg border border-dashed border-gray-300 px-6 py-8 text-center dark:border-white/10">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Todavía no hay productos en el lote.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                        <x-filament-tables::table>
                            <x-slot name="header">
                                <x-filament-tables::header-cell>Producto</x-filament-tables::header-cell>
                                <x-filament-tables::header-cell alignment="end">Actual</x-filament-tables::header-cell>
                                <x-filament-tables::header-cell alignment="end">Cantidad</x-filament-tables::header-cell>
                                <x-filament-tables::header-cell alignment="end">Queda en</x-filament-tables::header-cell>
                                <x-filament-tables::header-cell>
                                    <span class="sr-only">Quitar</span>
                                </x-filament-tables::header-cell>
                            </x-slot>

                            @foreach ($rows as $row)
                                <x-filament-tables::row wire:key="line-{{ $row['index'] }}">
                                    <x-filament-tables::cell>
                                        <div class="px-3 py-3 text-sm font-medium text-gray-950 dark:text-white">
                                            {{ $row['name'] }}
                                        </div>
                                    </x-filament-tables::cell>

                                    <x-filament-tables::cell>
                                        <div class="px-3 py-3 text-end text-sm tabular-nums text-gray-500 dark:text-gray-400">
                                            @if ($row['current'] !== null)
                                                {{ number_format($row['current'], 2) }}
                                                <span class="text-xs">{{ $row['unit'] }}</span>
                                            @else
                                                &mdash;
                                            @endif
                                        </div>
                                    </x-filament-tables::cell>

                                    <x-filament-tables::cell>
                                        <div class="px-3 py-2">
                                            <div class="ms-auto w-32">
                                                <x-filament::input.wrapper
                                                    :valid="! $errors->has('lines.' . $row['index'] . '.quantity')"
                                                >
                                                    <x-filament::input
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        class="text-end tabular-nums"
                                                        wire:model.live.debounce.500ms="lines.{{ $row['index'] }}.quantity"
                                                        aria-label="Cantidad de {{ $row['name'] }}"
                                                    />
                                                </x-filament::input.wrapper>
                                            </div>
                                            @error('lines.' . $row['index'] . '.quantity')
                                                <p class="mt-1 text-end text-xs text-danger-600 dark:text-danger-400">
                                                    {{ $message }}
                                                </p>
                                            @enderror
                                        </div>
                                    </x-filament-tables::cell>

                                    <x-filament-tables::cell>
                                        <div class="px-3 py-3 text-end text-sm font-semibold tabular-nums">
                                            @if ($row['resulting'] === null)
                                                <span class="text-gray-400 dark:text-gray-500">&mdash;</span>
                                            @elseif ($row['resulting'] < 0)
                                                <span class="text-danger-600 dark:text-danger-400">
                                                    {{ number_format($row['resulting'], 2) }}
                                                </span>
                                            @else
                                                <span class="text-success-600 dark:text-success-400">
                                                    {{ number_format($row['resulting'], 2) }}
                                                </span>
                                            @endif
                                        </div>
                                    </x-filament-tables::cell>

                                    <x-filament-tables::cell>
                                        <div class="px-3 py-3">
                                            <x-filament::icon-button
                                                icon="heroicon-m-x-mark"
                                                color="gray"
                                                size="sm"
                                                type="button"
                                                wire:click="removeLine({{ $row['index'] }})"
                                                :label="'Quitar ' . $row['name']"
                                            />
                                        </div>
                                    </x-filament-tables::cell>
                                </x-filament-tables::row>
                            @endforeach
                        </x-filament-tables::table>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Totales y envío --}}
        <div class="flex flex-wrap items-center gap-6 rounded-xl bg-gray-50 px-6 py-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Productos</p>
                <p class="text-xl font-bold tabular-nums text-gray-950 dark:text-white">{{ $totals['products'] }}</p>
            </div>

            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Unidades</p>
                <p class="text-xl font-bold tabular-nums text-gray-950 dark:text-white">
                    {{ number_format($totals['units'], 2) }}
                </p>
            </div>

            @if ($this->movementTypeLabel())
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Tipo</p>
                    <x-filament::badge :color="$this->movementTypeColor()" class="mt-1">
                        {{ $this->movementTypeLabel() }}
                    </x-filament::badge>
                </div>
            @endif

            <div class="ms-auto">
                <x-filament::button
                    type="submit"
                    size="lg"
                    icon="heroicon-m-check"
                    :disabled="$totals['products'] === 0"
                >
                    @if ($totals['products'] === 0)
                        Registrar movimientos
                    @elseif ($totals['products'] === 1)
                        Registrar 1 movimiento
                    @else
                        Registrar {{ $totals['products'] }} movimientos
                    @endif
                </x-filament::button>
            </div>
        </div>
    </form>
</x-filament-panels::page>
