<?php

namespace App\Providers\Filament;

use App\Enums\Permiso;
use App\Filament\Pages\Tenancy\EditarEmpresa;
use App\Filament\Pages\Tenancy\RegistrarEmpresa;
use App\Filament\Resources\Comprobantes\ComprobanteResource;
use App\Models\Empresa;
use App\Support\Permisos;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->passwordReset()
            ->profile()
            ->brandName('Facturador')
            ->font('Roboto')
            ->colors([
                // Paleta generada, ajustando los tonos usados por botones al color exacto de la marca
                'primary' => array_replace(Color::hex('#0c7286'), [
                    500 => Color::convertToOklch('#11849a'),
                    600 => Color::convertToOklch('#0c7286'),
                    700 => Color::convertToOklch('#095a6a'),
                ]),
                'danger' => Color::Rose,
                'gray' => Color::Slate,
            ])
            ->sidebarWidth('18.75rem')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->breadcrumbs(false)
            ->navigationGroups([
                NavigationGroup::make('Ventas')->icon(Heroicon::OutlinedDocumentText),
                NavigationGroup::make('Clientes')->icon(Heroicon::OutlinedUsers)->collapsed(),
                NavigationGroup::make('Productos/Servicios')->icon(Heroicon::OutlinedSquares2x2)->collapsed(),
                NavigationGroup::make('Comprobantes pendientes')->icon(Heroicon::OutlinedExclamationCircle)->collapsed(),
                NavigationGroup::make('Configuración')->icon(Heroicon::OutlinedCog6Tooth)->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('Nuevo comprobante')
                    ->group('Ventas')
                    ->sort(1)
                    ->url(fn () => ComprobanteResource::getUrl('create'))
                    ->visible(fn () => ComprobanteResource::canCreate())
                    ->isActiveWhen(fn () => request()->routeIs(ComprobanteResource::getRouteBaseName().'.create')),
                NavigationItem::make('Por atender')
                    ->group('Comprobantes pendientes')
                    ->sort(1)
                    ->url(fn () => ComprobanteResource::getUrl('index', ['tab' => 'por-atender']))
                    ->visible(fn () => ComprobanteResource::canViewAny())
                    ->isActiveWhen(fn () => request()->routeIs(ComprobanteResource::getRouteBaseName().'.index') && request()->query('tab') === 'por-atender')
                    ->badge(fn () => ComprobanteResource::contarPorAtender(), color: 'danger'),
                NavigationItem::make('Datos de la empresa')
                    ->group('Configuración')
                    ->sort(9)
                    ->url(fn () => filament()->getTenantProfileUrl())
                    ->visible(fn () => Permisos::puede(Permiso::GestionarEmpresa))
                    ->isActiveWhen(fn () => request()->routeIs('filament.admin.tenant.profile')),
            ])
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn () => view('filament.hooks.accesos-rapidos'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.hooks.estado-conexion'))
            ->renderHook(PanelsRenderHook::SIMPLE_LAYOUT_START, fn () => view('filament.hooks.login-aside'))
            ->tenant(Empresa::class, slugAttribute: 'ruc')
            ->tenantRegistration(RegistrarEmpresa::class)
            ->tenantProfile(EditarEmpresa::class)
            ->spa()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);

        // Notificaciones dentro del sistema (alertas de plazos por vencer)
        $panel->databaseNotifications()->databaseNotificationsPolling('60s');

        if (config('facturacion.registro_abierto')) {
            $panel->registration();
        }

        return $panel;
    }
}
