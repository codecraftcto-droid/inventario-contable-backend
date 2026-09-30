# Despliegue del backend en Dokploy

Proyecto de Dokploy: **Inventario Contable RyR Consulting**
Dominio: **https://inventariocontable-backend.codecraft.net.pe**

## 1. Base de datos

Servicio **MySQL 8.4** de Dokploy en el mismo proyecto (sin puerto externo).
Anota su **Internal Host**; va en `DB_HOST`.

## 2. Crear la aplicación

1. En el proyecto: **Create Service → Application**, nombre `backend`.
2. **Provider:** GitHub, repositorio `codecraftcto-droid/inventario-contable-backend`, rama `main`.
3. **Build Type:** `Dockerfile` (ruta `Dockerfile`, contexto `.`).
4. Activa **Autodeploy** si quieres que cada push a `main` despliegue.

## 3. Variables de entorno

Copia `.env.deploy.example` en la pestaña **Environment** y completa los valores:

| Variable | Cómo obtenerla |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` |
| `JWT_SECRET` | `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` |
| `DB_*` | Datos del servicio MySQL (host interno, puerto 3306) |
| `ADMIN_*` | Solo se usan con `RUN_SEEDERS=true` |

**Primera instalación en una base vacía:** `RUN_SEEDERS=true` para crear módulos, permisos, roles y el administrador.
Después del primer despliegue exitoso, **vuelve a ponerlo en `false`** (el seeder restablece la contraseña del
administrador y los permisos de los roles cada vez que corre).

`RUN_MIGRATIONS=true` aplica las migraciones nuevas en cada despliegue.

## 4. Volumen (fotos de los registros)

Pestaña **Advanced → Volumes / Mounts → Add Volume**:

- Tipo: **Volume Mount**
- Nombre: `inventario-backend-storage`
- Mount path: `/var/www/html/storage/app/private`

Sin este volumen las fotos se pierden en cada despliegue.

## 5. Dominio

Pestaña **Domains → Add Domain**:

- Host: `inventariocontable-backend.codecraft.net.pe`
- Path: `/`
- Container port: `80`
- HTTPS: activado, certificado **Let's Encrypt**

En el DNS de `codecraft.net.pe` crea un registro **A** `inventariocontable-backend` → IP del servidor.

## 6. Verificación

- `https://inventariocontable-backend.codecraft.net.pe/up` debe responder **200**.
- Los logs de Laravel salen en la pestaña **Logs** del servicio (`LOG_CHANNEL=stderr`).
