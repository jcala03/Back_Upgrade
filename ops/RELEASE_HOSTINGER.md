# Release Hostinger — procedimiento controlado

Este documento prepara operaciones; NO las autoriza ni las ejecuta.
Para cerrar el primer release SIN depender del deploy bloqueado, usar
[FIRST_RELEASE_BOOTSTRAP.md](FIRST_RELEASE_BOOTSTRAP.md): entrega privada por SCP,
migración única y vínculo obligatorio entre los commits backend/frontend.
Los workflows despliegan automáticamente con push a main y conservan workflow_dispatch.
El código siempre corresponde al SHA del evento. Los deploys ordinarios NO requieren
aprobación manual, backup_id ni un entorno con revisores. Únicamente la operación manual
de migración utiliza production-migrations (configurar sus revisores si el plan lo permite).
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
El último resultado es el backup_id OBLIGATORIO para la operación manual de migración.
En despliegues ordinarios se crean respaldos privados de código automáticamente; no se
exige este respaldo SQL ni intervención del usuario cuando el esquema ya está al día.

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
Si no se autoriza la sustitución puntual, el workflow se detendrá antes de publicar;
no descartar cambios ajenos para desbloquearlo.

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

Tras autorizar y completar CORS/.env en este primer release, reconstruir su configuración
efectiva mediante php artisan config:cache. La guarda previa inspecciona la instalación
ACTUAL, incluso su caché; el archivo editado no basta para eliminar los tres orígenes
cacheados. No ejecutar esta operación ahora sin autorización productiva.

## 5. Punto del push y backend

PRIMER RELEASE: autorizar push backend, verificar YAML y reactivar Actions si estaban
deshabilitadas. No ejecutar workflows antiguos ya encolados. La ejecución automática se
detendrá SIN checkout/Composer/mantenimiento si el esquema no está preparado. La copia
privada del candidato permite comparar TODOS sus nombres de migración con la base real
antes de cambiar la versión instalada. 87 frente a 88 por sí solo no prueba igualdad.
Si el push se realizó con Actions deshabilitadas, no hay ejecución retroactiva: después
de reactivarlas, lanzar dispatch SIN marcar migración para esta primera ejecución.
Es un arranque excepcional; los siguientes pushes a main sí se ejecutan automáticamente.

Si falta la migración conocida, con respaldo verificado, CORS/configuración regularizados
y mantenimiento autorizado, ejecutar workflow_dispatch backend marcando
run_configuration_migration=true y aportando backup_id. release_sha es una confirmación
opcional que, si se proporciona, debe coincidir con el SHA seleccionado de main.
Solo esta operación excepcional admite exactamente:

    2026_10_28_000001_add_configuration_context_to_order_items.php

Rechaza otras pendientes, historial huérfano y columna preexistente con migración pendiente.
La operación ejecuta el archivo candidato por ruta absoluta, con --realpath; equivalente a:

    php artisan migrate --force --path=database/migrations/2026_10_28_000001_add_configuration_context_to_order_items.php

No ejecutar el comando también por SSH. La operación exige backup recuperable asociado
al HEAD instalado, mantenimiento y aprobación previa; NO cambia código, Composer ni
cachés y deja mantenimiento como estaba. Reejecutar después el deploy automático fallido
(Re-run jobs), o lanzar dispatch SIN marcar migración. No hace falta otro commit.

DESPLIEGUES NORMALES: modificar, commit y push a main. CI valida dependencias, sintaxis,
Pint y unit tests sin base de datos. SSH verifica árbol limpio, ascendencia, plataforma
PHP y esquema del candidato. Rechaza también modificaciones/borrados de migraciones
históricas. Si todo pasa, respalda código/env/vendor privadamente y publica el SHA exacto.
No ejecuta NINGÚN comando migrate. Solo entonces entra en mantenimiento, instala desde
lock, reconstruye cachés y sale de mantenimiento al completar. Una migración futura
necesita operación autorizada específica; no se ejecuta automáticamente por ser nueva.

Después de éxito:

    cd "$PROJECT"
    php ops/release-preflight.php --require-current
    php artisan migrate:status --no-ansi
    git rev-parse HEAD

Reconstruye config/view cache, comprueba storage, señala reinicio de workers existentes
y sale de mantenimiento SOLO al terminar. queue:restart no inicia un consumidor.

## 6. Frontend y smoke tests

En este primer release, publicar primero backend y después hacer el push frontend.
Después, cada push frontend a main ejecuta tests, build cliente/SSR/prerender y controles.
El SHA backend requerido está versionado en .github/required-backend-sha del frontend
y es obligatorio también en push. El input opcional backend_sha solo lo confirma;
no permite reemplazarlo. Se exige HEAD remoto y marca privada de deploy exitoso con
ese mismo SHA; flock solo evita carreras y NO sustituye esta dependencia.
backup_id sigue siendo opcional. El paquete se sube a un directorio privado, no al sitio vivo.
Antes de publicar comprueba hashes, estado del backend/esquema y preserva un respaldo
privado automático. Copia assets antes del HTML y sustituye index al final.
No usa el dist local viejo ni publica qa-artifacts/.env privados.
Conserva puente y .htaccess raíz; instala solo el bloque SEO administrado con respaldo.

Concurrencia GitHub serializada sin cancelar procesos activos; bloqueo flock compartido
en Hostinger entre backend, frontend y migraciones. Requiere flock disponible y ambos
despliegues con el mismo usuario Hostinger. No son suficientes grupos GitHub distintos
entre repositorios. El frontend rechaza ejecuciones antiguas frente al run ya publicado.
Los jobs ordinarios NO deben usar un environment con aprobaciones requeridas; sus secretos
deben estar disponibles a nivel repositorio/organización. No se cambian secretos aquí.

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

Si falla una validación previa, conserva la versión y disponibilidad anteriores.
Si backend falla después de entrar en mantenimiento, queda así; no ejecutar up a ciegas
ni migración global. Los respaldos de código están en upgrade79-deploy-rollbacks.
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

## 8. Validación de los workflows sin Hostinger

    php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit
    php vendor/bin/pint --test ops tests/Unit
    python3 ops/test_deploy_workflows.py

El último comando requiere PyYAML y ambos repositorios locales; no es una dependencia
de producción ni ejecuta SSH/SQL. Usa comandos falsos y directorios temporales propios
para probar publicación exitosa y rechazo antes de cambiar código/HTML. No modifica HOME.
Conserva las pruebas anteriores y añade protección por hash del archivo de migración.
Los jobs CI ejecutan unit tests PHP sin base de datos y la suite frontend antes del build.

La concurrencia GitHub es por repositorio: https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency
Los revisores de environment se reservan a migraciones: https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments
