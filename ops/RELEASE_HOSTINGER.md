# Release Hostinger — procedimiento controlado

Este documento prepara operaciones; NO las autoriza ni las ejecuta.
Los workflows son exclusivamente manuales, fijados al SHA completo de main seleccionado.
GitHub environment production debe tener revisores requeridos configurados por el usuario.
Antes del primer push: deshabilitar temporalmente Actions en ambos repositorios, cancelar
ejecuciones anteriores y comprobar que no existe otro auto-deploy Git en hPanel.

## 1. SSH y mantenimiento autorizado

    PROJECT="$HOME/domains/crmupgradecol.com/laravel"
    FRONT="$HOME/domains/upgradecolombia.com/public_html"
    cd "$PROJECT"
    git status --short
    git rev-parse HEAD

Coordinar ventana, esperar operaciones activas y pausar escritores/consumidores/Cron
con autorización; no matar procesos automáticamente. Luego, con autorización:

    php artisan down --retry=60
    install -d -m 700 "$HOME/upgrade79-release-tools"

Copiar SOLO ops/backup-production.sh del release aprobado a ese directorio privado
mediante SCP (puerto 65002); comparar sha256sum local/remoto antes de ejecutarlo:

    bash "$HOME/upgrade79-release-tools/backup-production.sh"

Solicita host/base/usuario verificados y contraseña MySQL por prompt, nunca en argv.
Respalda SQL, backend con vendor/.git/.env/storage, frontend, public del backend,
puente y .htaccess. No usa --databases: no debe incluir CREATE DATABASE/USE.
Escribe fuera de public_html, con directorio 700 y archivos privados.
Falla ante storage externo, dump incompleto o errores tar; no ignorar estos fallos.
Confirmar motores de tablas: si no son transaccionales, detener todos los escritores.

## 2. Verificar recuperación ANTES del deploy

El script verifica gzip/tar/SHA256; esto NO prueba restauración SQL.
Con autorización adicional, restaurar en una instancia privada aislada, nunca upgrade,
upgrade_test con QA persistente, ni producción. No arrancar aplicaciones, colas,
scheduler ni event_scheduler del clon. No reutilizar credenciales de producción.

    # Solo en la instancia aislada autorizada:
    mysql --host=HOST_AISLADO --user=USUARIO_AISLADO -p \
      -e 'CREATE DATABASE restore_release CHARACTER SET utf8mb4'
    set -o pipefail
    gzip -dc "$BACKUP/database.sql.gz" |
      mysql --host=HOST_AISLADO --user=USUARIO_AISLADO -p restore_release

BACKUP es la ruta exacta informada por el script. Verificar antes que el dump no cambia
la base destino. Comparar tablas, conteos, índices, triggers y migraciones con el origen
sin divulgar datos personales. Extraer los tar a directorios nuevos privados y comprobar
.env, vendor, logo, storage/imágenes, index, puente, reglas y permisos.
Mantener los respaldos; no borrar datos ni extraer encima del sitio.

Solo después de la prueba, el operador registra su verificación:

    cd "$BACKUP"
    sha256sum --check --quiet SHA256SUMS
    umask 077
    printf '%s\n' 'Restauración aislada y archivos verificados por el operador' > RESTORE_VERIFIED
    chmod 600 RESTORE_VERIFIED
    basename "$BACKUP"

El marcador es una declaración del operador, no una restauración automática.
El último resultado es el backup_id de AMBOS workflows.

## 3. Regularizar SOLO CORS — autorización separada

    cd "$PROJECT"
    test -f "$BACKUP/RESTORE_VERIFIED"
    cp -p config/cors.php "$BACKUP/cors-before.php"
    chmod 600 "$BACKUP/cors-before.php"
    git diff -- config/cors.php > "$BACKUP/cors-before.patch"
    chmod 600 "$BACKUP/cors-before.patch"
    git diff --name-only
    git diff -- config/cors.php
    git show HEAD:config/cors.php > "$BACKUP/cors-versioned.php"
    php -l "$BACKUP/cors-versioned.php"

Revisar que el único cambio remoto sea la lista de orígenes conocida. Si aparecen
otras diferencias, detenerse. No reset, restore, clean ni stash general.
SOLO tras autorizar expresamente sustituir ESTE archivo por su versión versionada:

    cp "$BACKUP/cors-versioned.php" config/cors.php
    php -l config/cors.php
    git diff --exit-code -- config/cors.php
    git diff --quiet
    git diff --cached --quiet

No se pierde el original: queda en respaldo privado. No afecta .env ni otros archivos.
Mantener caché vigente hasta el despliegue. Si no se autoriza la sustitución puntual,
no ejecutar el workflow in-place; preparar otra estrategia.

## 4. .env productivo — privado y sin activar cobros ni SMTP

Editar únicamente con autorización el .env EXISTENTE mediante editor privado.
Preservar APP_KEY productiva, MySQL, sesiones/Sanctum, VAPID/Push y proveedores.
Nunca copiar el .env de localhost ni imprimir secretos.

    WOMPI_BASE_URL=https://production.wompi.co/v1
    WOMPI_CHECKOUT_URL=https://checkout.wompi.co/p/
    WOMPI_REDIRECT_URL=https://upgradecolombia.com/checkout/payment/return
    COMMERCIAL_DOCUMENTS_REAL_MAIL_ENABLED=false
    MAIL_MAILER=array

Mantener WOMPI_PUBLIC_KEY y WOMPI_INTEGRITY_SECRET vacías en ESTE release. El preflight
lo exige: no existe bandera global y estos dos valores bastan para iniciar checkout.
No introducir las credenciales expuestas. Las cuatro credenciales productivas nuevas
se instalarán por canal privado SOLO después de coordinar rotación y autorizar activación.
La URL de eventos debe verificarse en el dashboard Wompi, no se registra por .env:
https://upgradecolombia.com/api/webhooks/wompi
No activar el mailer global ni SMTP de documentos.

## 5. Punto del push y backend

Con commits aprobados, respaldo recuperable, CORS regularizado y mantenimiento,
autorizar el push de AMBOS repositorios a main. Actions aún deshabilitadas; no despachar.
Verificar en GitHub que llegó el SHA exacto y que el YAML publicado no tiene trigger push.
Reactivar Actions y despachar primero backend con release_sha y backup_id.
Marcar run_configuration_migration SOLO con autorización expresa.

El workflow exige respaldo correspondiente al HEAD anterior, mantenimiento y árbol tracked
limpio. Obtiene el SHA aprobado, valida ascendencia, checkout exacto e instala desde lock.
ops/release-preflight.php compara TODOS los nombres aplicados contra los archivos del release.
87 frente a 88 por sí solo no prueba igualdad. Solo permite ninguna pendiente o exactamente:

    2026_10_28_000001_add_configuration_context_to_order_items.php

Rechaza otras pendientes, historial huérfano y columna preexistente con migración pendiente.
El único comando de migración que permite la casilla es:

    php artisan migrate --force --path=database/migrations/2026_10_28_000001_add_configuration_context_to_order_items.php

No ejecutarlo de nuevo manualmente además del workflow. Si no se autoriza y está pendiente,
el workflow se detiene en mantenimiento. No ejecuta migraciones globales.

Después de éxito:

    cd "$PROJECT"
    php ops/release-preflight.php --require-current
    php artisan migrate:status --no-ansi
    git rev-parse HEAD

Reconstruye config/view cache, comprueba storage, señala reinicio de workers existentes
y sale de mantenimiento SOLO al terminar. queue:restart no inicia un consumidor.

## 6. Frontend y smoke tests

Despachar frontend manualmente con SHA frontend, SHA backend ya publicado y backup_id.
Comprueba backend/esquema, compila cliente+SSR+prerender con API y canónico públicos y
publica el NUEVO build. No usa el dist local viejo ni publica qa-artifacts/.env privados.
Conserva puente y .htaccess raíz; instala solo el bloque SEO administrado con respaldo.

    curl -sS -o /dev/null -w 'API health: %{http_code}\n' https://upgradecolombia.com/api/health
    curl -sS -o /dev/null -w 'Frontend: %{http_code}\n' https://upgradecolombia.com/
    curl -sS -o /dev/null -w 'Retorno: %{http_code}\n' https://upgradecolombia.com/checkout/payment/return
    curl -sS -o /dev/null -w 'Webhook GET esperado 405: %{http_code}\n' https://upgradecolombia.com/api/webhooks/wompi

Navegador: login Usuario/Admin, imágenes/catálogo, cotizaciones existentes y descarga PDF,
WhatsApp manual SIN afirmar envío, correo en simulación, comisiones/períodos/sedes/permisos,
consulta de ventas, consola/hidratación y móvil. No crear compras, ventas, pagos ni stock
productivos como smoke test sin autorización aparte. Un GET 405 no certifica un webhook.
Checkout continúa rechazando pago sin credenciales; configurador comercial sigue bloqueado.
Revisar consumidor Push existente sin regenerar VAPID ni modificar el Service Worker.

## 7. Recuperación

Si falla backend, queda en mantenimiento. No ejecutar up a ciegas ni migración global.
Registrar último paso y si la columna llegó a aplicarse. No migrate:rollback: conservar
la columna nullable y los snapshots, incluso si se recupera código anterior.

Con autorización, recuperar backend+vendor compatibles en un directorio privado limpio,
conservar .env y storage vigentes y coordinar el cambio de instalación/puente. Reconstruir
cachés del código recuperado antes de reabrir. No sobrescribir uploads nuevos con backup viejo.
Si solo falla frontend, recuperar sus archivos del tar aprobado preservando puente/storage
y reglas salvo autorización expresa. No editar SW como atajo ni asumir reversión instantánea
de clientes ya cacheados.

No restaurar SQL automáticamente: podría borrar órdenes/pagos posteriores al respaldo.
La recuperación de base exige autorización independiente, respaldo del estado fallido
y reconciliación de operaciones externas. No borrar respaldos.
