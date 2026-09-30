#!/usr/bin/env sh
# Arranque del contenedor en Dokploy: prepara carpetas, migra (opcional) y cachea la configuración.
set -e

cd /var/www/html

# storage/app/private (fotos de los registros) vive en un volumen: se recrean las carpetas por si está vacío.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

if [ -z "$APP_KEY" ] || [ -z "$JWT_SECRET" ]; then
  echo "ERROR: faltan APP_KEY o JWT_SECRET en las variables de entorno de Dokploy."
  exit 1
fi

php artisan optimize:clear >/dev/null 2>&1 || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  echo "Esperando la base de datos y aplicando migraciones..."
  attempts=0
  until php artisan migrate --force; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 30 ]; then
      echo "ERROR: no se pudo migrar después de ${attempts} intentos."
      exit 1
    fi
    sleep 2
  done
fi

# SOLO para la primera instalación: el seeder de seguridad restablece la contraseña del
# administrador y los permisos de los roles. Después de usarlo, vuelve a ponerlo en false.
if [ "${RUN_SEEDERS:-false}" = "true" ]; then
  echo "Cargando datos iniciales (módulos, permisos, roles y administrador)..."
  php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan event:cache >/dev/null 2>&1 || true

exec "$@"
