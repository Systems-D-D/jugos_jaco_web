<x-filament-panels::page>
    <div class="fi-section-content-ctn rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-content p-6">
            @livewire('reconciliations.create-reconciliation', [
                'employee_id' => request('employee_id'),
                'reconciliation_date' => request('reconciliation_date'),
            ])
        </div>
    </div>
</x-filament-panels::page>