# Jugos Jaco

Sistema ERP/CRM para la gestión de distribución y ventas de jugos. Aplicación monolítica con panel administrativo (Filament) y API REST para app móvil de vendedores en campo.

## Stack Tecnológico

| Capa | Tecnología |
|------|-----------|
| Backend | PHP 8.2+, Laravel 11 |
| Panel Admin | Filament v3 + Livewire |
| Estilos | Tailwind CSS 3.4 + PostCSS |
| Base de Datos | MySQL (relacional) + Eloquent ORM |
| API Auth | Laravel Sanctum |
| Roles/Permisos | Spatie Laravel Permission |
| Testing | Pest PHP |
| Formateo | Laravel Pint |

## Funcionalidades Principales

- **Gestión de Clientes**: registro con ubicación GPS (Plus Code), equipamiento, asignación a empleados y turnos de visita programados.
- **Fuerza de Ventas en Campo**: registro de visitas, tracking GPS, asignación diaria de productos a vendedores.
- **Punto de Venta**: búsqueda de productos, carrito, cálculo de impuestos hondureños, múltiples formas de pago (efectivo, crédito, depósito, tarjeta), descuento de inventario.
- **Cuadre de Caja Diario**: conciliación por empleado — ventas al contado/crédito, cobros, depósitos, gastos (facturas), retornos de producto, sobrantes de producto asignado.
- **Inventarios**: producto terminado por sucursal, materia prima y bitácora de movimientos (entradas, salidas, daños, devoluciones).
- **Cuentas por Cobrar**: seguimiento de ventas al crédito con estados (pendiente, pagado, vencido), pagos parciales/totales.
- **Precios por Escala**: múltiples listas de precios por tipo de cliente y unidad de medida, actualización masiva.
- **Dashboard**: widgets de KPIs visibles según rol (superadmin, administrador, cajero).
- **Mapa Interactivo**: geolocalización de clientes y empleados.
- **Sucursales**: multi-sucursal con inventario por sucursal.

## Requisitos del Sistema

- PHP 8.2 o superior
- MySQL 8.0 o superior
- Composer
- Node.js 18+ (para assets frontend)

## Instalación

```bash
# Clonar repositorio
git clone <repo-url> jugos_jaco_web
cd jugos_jaco_web

# Instalar dependencias PHP
composer install

# Instalar dependencias frontend y compilar
npm install
npm run build

# Copiar variables de entorno
cp .env.example .env

# Configurar conexión a base de datos en .env
# DB_DATABASE=jugos_jaco_web
# DB_USERNAME=tu_usuario
# DB_PASSWORD=tu_contraseña

# Generar clave de aplicación
php artisan key:generate

# Ejecutar migraciones y seeders
php artisan migrate --seed

# Iniciar servidor de desarrollo
composer run dev
```

El panel administrativo estará disponible en `http://localhost:8000`.

## Ejecutar en Desarrollo

```bash
# Inicia servidor, cola, y Vite simultáneamente
composer run dev
# o manualmente:
php artisan serve &
php artisan queue:work &
npm run dev
```

## Estructura del Proyecto

```
app/
├── Enums/              # Enumeraciones (bancos, municipios, estados, roles)
├── Filament/
│   ├── Pages/          # Páginas personalizadas (Mapa, Gestor de Turnos)
│   ├── Resources/      # CRUDs del panel admin (21 recursos)
│   └── Widgets/        # Widgets del dashboard
├── Http/
│   └── Controllers/    # API y controladores web
├── Models/             # Modelos Eloquent (33 modelos)
├── Services/           # Lógica de negocio (12 servicios)
└── Traits/             # ApiResponse trait
```

## API REST

Autenticación vía Sanctum (Bearer token). Endpoints disponibles:

| Módulo | Endpoints |
|--------|-----------|
| Clientes | CRUD, imágenes, visitas, días de visita |
| Empleados | Datos, ubicación GPS |
| Ventas | Crear, listar, detalle |
| Productos | Listar, asignados, movimientos |
| Cuentas por Cobrar | Listar, pagos, cobros del día |
| Tipos de Precio | Listar |

## Pruebas

```bash
php artisan test
# o
./vendor/bin/pest
```

## Roles del Sistema

| Rol | Acceso |
|-----|--------|
| Superadministrador | Acceso total al panel, todos los widgets |
| Administrador | Gestión completa (excepto registro de sistema) |
| Empleado | Sin acceso al panel (usa API móvil) |
| Cajero | Ventas, inventarios — solo ve sus clientes asignados |

## Convenciones de Código

- **Modelos**: PascalCase singular (`ClientVisit`, `ProductPrice`)
- **Tablas**: snake_case plural (`client_visits`, `product_prices`)
- **Controladores**: PascalCase terminado en `Controller`
- **Rutas API**: kebab-case agrupadas por entidad
- **Respuestas API**: estandarizadas mediante `ApiResponse` trait
- **Arquitectura**: "Fat Models, Skinny Controllers" — lógica de consulta en scopes Eloquent y Services
