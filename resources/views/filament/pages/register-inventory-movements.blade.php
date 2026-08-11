<x-filament-panels::page>
    <form wire:submit="register" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" size="lg">
                Registrar movimientos
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
