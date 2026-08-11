<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login(Login::class)
            ->topNavigation()
            ->brandName(config('app.name'))
            ->brandLogo(asset('/images/logo.png'))
            ->brandLogoHeight(fn() => \Illuminate\Support\Facades\Auth::check() ? '3.5rem' : '12rem')
            ->colors([
                'primary' => Color::Amber,
            ])
            // Tema propio: habilita las utilidades de Tailwind del proyecto
            // dentro del panel (sin esto, sólo existen las clases que Filament
            // usa internamente en su CSS precompilado).
            //
            // Se sirve como CSS plano desde public/ —no por Vite— a propósito:
            // /public/build está en .gitignore, así que un tema por Vite
            // obligaría a correr `npm run build` en cada despliegue o el panel
            // quedaría sin estilos. Este archivo se versiona ya compilado.
            //
            // Al agregar clases nuevas en app/Filament o resources/views/filament
            // hay que recompilar: `npm run build:theme`.
            ->theme(asset('css/filament/admin/theme.css'))
            ->navigationGroups([
               'Administración',
               'Clientes',
               'Finanzas',
               'Productos',
               'Inventario',
               'Ventas'
            ])
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                \App\Filament\Pages\Locations\ClientLocations::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                \App\Filament\Widgets\StatsOverview::class,
                \App\Filament\Widgets\ClientsPerEmployeeWidget::class,
                \App\Filament\Widgets\StockAlertsWidget::class,
                \App\Filament\Widgets\RawMaterialStockAlertsWidget::class,
                \App\Filament\Widgets\SalesRankingWidget::class,
                \App\Filament\Widgets\AccountsReceivableWidget::class,
                \App\Filament\Widgets\AccountWidget::class,
                \App\Filament\Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
