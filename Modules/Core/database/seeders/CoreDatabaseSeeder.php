<?php

namespace Modules\Core\Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Modules\Core\Models\Action;
use Modules\Core\Models\Module;
use Spatie\Permission\PermissionRegistrar;

class CoreDatabaseSeeder extends Seeder
{
    /**
     * Catálogo de acciones. El orden define el orden de las columnas en la matriz de permisos.
     */
    private const ACTIONS = [
        ['code' => 'VER', 'name' => 'Ver', 'icon' => 'tabler-eye'],
        ['code' => 'CREAR', 'name' => 'Crear', 'icon' => 'tabler-plus'],
        ['code' => 'EDITAR', 'name' => 'Editar', 'icon' => 'tabler-pencil'],
        ['code' => 'ELIMINAR', 'name' => 'Eliminar', 'icon' => 'tabler-trash'],
        ['code' => 'EXPORTAR', 'name' => 'Exportar', 'icon' => 'tabler-file-export'],
        ['code' => 'IMPORTAR', 'name' => 'Importar', 'icon' => 'tabler-database-import'],
        ['code' => 'APROBAR', 'name' => 'Aprobar', 'icon' => 'tabler-circle-check'],
        ['code' => 'ESCANEAR', 'name' => 'Escanear', 'icon' => 'tabler-barcode'],
        ['code' => 'SINCRONIZAR', 'name' => 'Sincronizar', 'icon' => 'tabler-cloud-upload'],
        ['code' => 'VER_TODOS', 'name' => 'Ver todos', 'icon' => 'tabler-eye-check'],
    ];

    private const CRUD = ['VER', 'CREAR', 'EDITAR', 'ELIMINAR'];

    public function run(): void
    {
        foreach (self::ACTIONS as $index => $action) {
            Action::updateOrCreate(
                ['code' => $action['code']],
                ['name' => $action['name'], 'icon' => $action['icon'], 'sort_order' => $index + 1, 'is_active' => true]
            );
        }

        // Árbol base. Los módulos agrupadores (sin path) no llevan acciones: se muestran
        // en el menú si el usuario tiene permiso en alguno de sus hijos.
        $tree = [
            [
                'code' => 'DASH', 'name' => 'Dashboard', 'icon' => 'tabler-layout-dashboard',
                'path' => '/dashboard', 'platform' => 'WEB', 'is_locked' => true,
                'actions' => ['VER'],
            ],
            [
                // Solo el registro de empresas cliente (datos y usuarios permitidos por contrato).
                'code' => 'EMP', 'name' => 'Empresas', 'icon' => 'tabler-building-skyscraper',
                'path' => '/apps/companies', 'platform' => 'WEB',
                'actions' => [...self::CRUD, 'EXPORTAR'],
                'children' => [
                    [
                        // Opción de cada empresa (is_menu = false): modal con el CRUD de sus sedes.
                        'code' => 'EMP_SEDE', 'name' => 'Sedes', 'icon' => 'tabler-map-pin',
                        'platform' => 'WEB', 'is_menu' => false,
                        'actions' => self::CRUD,
                    ],
                ],
            ],
            [
                // Listado de inventarios de cada empresa. Sus submódulos son OPCIONES de cada
                // inventario (is_menu = false): no van en el menú lateral, pero tienen sus propios
                // permisos para mostrar/ocultar cada botón del listado.
                'code' => 'INV', 'name' => 'Inventarios', 'icon' => 'tabler-clipboard-list',
                'path' => '/apps/inventories', 'platform' => 'AMBOS', 'is_locked' => true,
                // APROBAR = cerrar el inventario. VER_TODOS = ver todos sin estar asignado (sin él,
                // el personal interno solo ve los inventarios que tiene asignados).
                'actions' => [...self::CRUD, 'VER_TODOS', 'APROBAR', 'EXPORTAR'],
                'children' => [
                    [
                        // Modal: descargar formato Excel, subirlo con data y ver/editar la tabla cargada.
                        'code' => 'INV_BASE', 'name' => 'Base contable', 'icon' => 'tabler-database-import',
                        'platform' => 'WEB', 'is_menu' => false, 'is_locked' => true,
                        'actions' => [...self::CRUD, 'IMPORTAR', 'EXPORTAR'],
                    ],
                    [
                        // Escaneo de activos en campo (web y app) y envío de lo tomado sin señal.
                        'code' => 'INV_TOMA', 'name' => 'Toma de inventario', 'icon' => 'tabler-barcode',
                        'platform' => 'AMBOS', 'is_menu' => false, 'is_locked' => true,
                        'actions' => ['VER', 'ESCANEAR', 'EDITAR', 'ELIMINAR', 'SINCRONIZAR', 'EXPORTAR'],
                    ],
                    [
                        // Modal: elegir qué personal interno trabaja en el inventario.
                        'code' => 'INV_ASIG', 'name' => 'Asignar inventariadores', 'icon' => 'tabler-user-check',
                        'platform' => 'WEB', 'is_menu' => false, 'is_locked' => true,
                        'actions' => ['VER', 'EDITAR'],
                    ],
                    [
                        // Modal: pockets (zonas / tandas de conteo) del inventario y sus inventariadores.
                        'code' => 'INV_POCK', 'name' => 'Pockets', 'icon' => 'tabler-layout-grid',
                        'platform' => 'WEB', 'is_menu' => false, 'is_locked' => true,
                        'actions' => self::CRUD,
                    ],
                ],
            ],
            [
                'code' => 'SEG', 'name' => 'Seguridad', 'icon' => 'tabler-shield-lock',
                'platform' => 'WEB', 'is_locked' => true,
                'children' => [
                    [
                        'code' => 'SEG_USU', 'name' => 'Usuarios', 'icon' => 'tabler-users',
                        'path' => '/apps/security/users', 'platform' => 'WEB', 'is_locked' => true,
                        'actions' => self::CRUD,
                    ],
                    [
                        'code' => 'SEG_ROL', 'name' => 'Roles', 'icon' => 'tabler-user-shield',
                        'path' => '/apps/security/roles', 'platform' => 'WEB', 'is_locked' => true,
                        'actions' => self::CRUD,
                    ],
                    [
                        // Permisos = qué acciones del catálogo están habilitadas en cada módulo.
                        'code' => 'SEG_PER', 'name' => 'Permisos', 'icon' => 'tabler-key',
                        'path' => '/apps/security/permissions', 'platform' => 'WEB', 'is_locked' => true,
                        'actions' => self::CRUD,
                    ],
                    [
                        'code' => 'SEG_MOD', 'name' => 'Módulos', 'icon' => 'tabler-layout-grid',
                        'path' => '/apps/security/modules', 'platform' => 'WEB', 'is_locked' => true,
                        'actions' => self::CRUD,
                    ],
                    [
                        // Sesiones activas (ELIMINAR = revocar) y log de accesos.
                        'code' => 'SEG_SES', 'name' => 'Sesiones y accesos', 'icon' => 'tabler-devices',
                        'path' => '/apps/security/sessions', 'platform' => 'WEB', 'is_locked' => true,
                        'actions' => ['VER', 'ELIMINAR', 'EXPORTAR'],
                    ],
                ],
            ],
        ];

        $actionIds = Action::pluck('id', 'code');

        foreach ($tree as $index => $definition) {
            $this->createModule($definition, $index + 1, $actionIds->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<string, int>  $actionIds
     */
    private function createModule(array $definition, int $sortOrder, array $actionIds, ?int $parentId = null): void
    {
        $module = Module::updateOrCreate(
            ['code' => $definition['code']],
            [
                'parent_id' => $parentId,
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'icon' => $definition['icon'] ?? null,
                'path' => $definition['path'] ?? null,
                'sort_order' => $sortOrder,
                'platform' => $definition['platform'],
                'is_active' => true,
                'is_locked' => $definition['is_locked'] ?? false,
                'is_menu' => $definition['is_menu'] ?? true,
            ]
        );

        foreach ($definition['actions'] ?? [] as $actionCode) {
            Permission::updateOrCreate(
                ['module_id' => $module->id, 'action_id' => $actionIds[$actionCode], 'guard_name' => config('auth.defaults.guard')],
                ['name' => Permission::nameFor($module->code, $actionCode)]
            );
        }

        foreach ($definition['children'] ?? [] as $index => $child) {
            $this->createModule($child, $index + 1, $actionIds, $module->id);
        }
    }
}
