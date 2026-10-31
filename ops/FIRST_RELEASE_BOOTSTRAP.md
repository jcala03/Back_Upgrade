# Primer release: migración independiente y orden backend → frontend

Instrucciones preparadas, NO ejecutadas. Requieren autorización para mantenimiento,
respaldo, CORS/configuración, migración y push. No activar compras ni correo reales.

## 1. Entregar la migración ANTES de cualquier push (equipo local)

Desde backendupgrade, con el commit backend aprobado en HEAD:

```bash
set -euo pipefail
umask 077
RELEASE_BACKEND_SHA=$(git rev-parse HEAD)
[[ "$RELEASE_BACKEND_SHA" =~ ^[0-9a-f]{40}$ ]]
BOOTSTRAP_DIR=$(mktemp -d /tmp/upgrade79-bootstrap.XXXXXX)
git archive --format=tar.gz --output="$BOOTSTRAP_DIR/bootstrap.tar.gz" \
  "$RELEASE_BACKEND_SHA" ops/release-preflight.php ops/ReleaseMigrationPlan.php \
  ops/backup-production.sh database/migrations
(cd "$BOOTSTRAP_DIR" && sha256sum bootstrap.tar.gz > SHA256SUMS)
printf 'SHA backend aprobado: %s\n' "$RELEASE_BACKEND_SHA"
cat "$BOOTSTRAP_DIR/SHA256SUMS"

# Ejecutar estos dos comandos SOLO tras autorizar la entrega privada por SSH.
ssh -p 65002 u497825029@212.85.29.152 \
  "umask 077; install -d -m 700 /home/u497825029/upgrade79-bootstrap/$RELEASE_BACKEND_SHA"
scp -P 65002 "$BOOTSTRAP_DIR/bootstrap.tar.gz" "$BOOTSTRAP_DIR/SHA256SUMS" \
  "u497825029@212.85.29.152:/home/u497825029/upgrade79-bootstrap/$RELEASE_BACKEND_SHA/"
```

El paquete contiene código del commit local y el historial para comparar migraciones;
NO contiene .env, vendor, datos ni credenciales. No requiere GitHub, git pull, checkout,
Composer ni que el deploy haya funcionado.

## 2. Preparar candidato y respaldo privado (SSH, misma sesión Bash)

Primero cancelar ejecuciones antiguas y coordinar la pausa de escritores/colas/Cron
durante esta ventana. No iniciar otros deploys. No basta artisan down para detener jobs.

```bash
set -euo pipefail
set +x
umask 077
read -r -p 'SHA backend aprobado (el impreso en local): ' RELEASE_BACKEND_SHA
[[ "$RELEASE_BACKEND_SHA" =~ ^[0-9a-f]{40}$ ]]
PROJECT="$HOME/domains/crmupgradecol.com/laravel"
INCOMING="$HOME/upgrade79-bootstrap/$RELEASE_BACKEND_SHA"
exec 9>"$HOME/.upgrade79-deploy.lock"
flock -w 900 9
(cd "$INCOMING" && sha256sum --check --quiet SHA256SUMS)
cat "$INCOMING/SHA256SUMS"  # Comparar con el hash impreso en el equipo local.
CANDIDATE=$(mktemp -d "$HOME/upgrade79-bootstrap/candidate-XXXXXX")
tar -xzf "$INCOMING/bootstrap.tar.gz" -C "$CANDIDATE"
MIGRATION="$CANDIDATE/database/migrations/2026_10_28_000001_add_configuration_context_to_order_items.php"
printf '%s  %s\n' \
  0b2587afa21f6f869d18b1d91642d9ce0dabffa8fc542f668ddc3ad5939e8eb8 \
  "$MIGRATION" | sha256sum --check --quiet
cd "$PROJECT"
php artisan down --retry=60
bash "$CANDIDATE/ops/backup-production.sh"
```

**Parar aquí hasta probar la restauración SQL y de archivos en un destino aislado.**
Seguir [RELEASE_HOSTINGER.md, sección 2](RELEASE_HOSTINGER.md#2-verificar-recuperación-antes-del-deploy).
Solo entonces registrar RESTORE_VERIFIED. Un checksum o gzip válido NO prueba restauración.
Conservar todos los respaldos privados. No restaurar sobre producción.

Antes de continuar, regularizar exclusivamente CORS y la configuración/caché productiva
según las secciones 3–4 ya preparadas; requieren autorización separada. Sin ello el
preflight falla deliberadamente. No copiar el .env local. Mantener mantenimiento y lock.

## 3. Aplicar SOLO la migración aprobada (misma sesión SSH)

```bash
read -r -p 'ID del respaldo restaurado y verificado: ' BACKUP_ID
[[ "$BACKUP_ID" =~ ^release-[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9]{6}$ ]]
BACKUP="$HOME/upgrade79-private-backups/$BACKUP_ID"
test -f "$BACKUP/RESTORE_VERIFIED"
(cd "$BACKUP" && sha256sum --check --quiet SHA256SUMS)
test "$(cat "$BACKUP/backend-head.txt")" = "$(git -C "$PROJECT" rev-parse HEAD)"
test -f "$PROJECT/storage/framework/down"
cd "$PROJECT"
git diff --exit-code
git diff --cached --exit-code
php "$CANDIDATE/ops/release-preflight.php" --project-root="$PROJECT" \
  --migration-root="$CANDIDATE/database/migrations"
php artisan migrate --pretend --force --realpath --path="$MIGRATION"
```

El preflight debe listar únicamente la migración aprobada como pendiente, y la columna
debe estar ausente. Compara TODOS los nombres aplicados con los del candidato: no basta
87/88. Si ya está aplicada correctamente, omitir migrate y usar solo el preflight final.
Revisar el SQL pretend: único cambio previsto, JSON nullable en order_items.

**Tras la autorización de ejecución y revisión del pretend:**

```bash
php artisan migrate --force --realpath --path="$MIGRATION"
php "$CANDIDATE/ops/release-preflight.php" --project-root="$PROJECT" \
  --migration-root="$CANDIDATE/database/migrations" --require-current
flock -u 9
exec 9>&-
```

El último PASS verifica el registro de la migración, cero pendientes y columna nullable
compatible. Se ejecutó UN archivo con Laravel/vendor instalados; ningún otro migrate.
Dejar mantenimiento hasta que el deploy backend termine correctamente. Si falla,
detenerse: no hacer rollback SQL, retry general ni artisan up a ciegas.

## 4. Push y orden obligatorio de publicación

Tras autorizar push, desde backendupgrade: `git push origin main`.
Esperar Actions PASS. El backend escribe atómicamente
`$HOME/.upgrade79-backend-deployed-sha` SOLO después de Composer, cachés, artisan up y
comprobación del SHA. No crear este archivo a mano.

Comprobación SSH de solo lectura (introducir el SHA aprobado):

```bash
read -r -p 'SHA backend aprobado: ' RELEASE_BACKEND_SHA
[[ "$RELEASE_BACKEND_SHA" =~ ^[0-9a-f]{40}$ ]]
test "$(git -C "$HOME/domains/crmupgradecol.com/laravel" rev-parse HEAD)" = "$RELEASE_BACKEND_SHA"
test "$(cat "$HOME/.upgrade79-backend-deployed-sha")" = "$RELEASE_BACKEND_SHA"
test ! -f "$HOME/domains/crmupgradecol.com/laravel/storage/framework/down"
```

Desde upgrade79-web, verificar `.github/required-backend-sha` == SHA aprobado; después,
con autorización: `git push origin main`. Actions exige ese SHA tanto en HEAD remoto
como en la marca de éxito, además de esquema actualizado y ausencia de mantenimiento,
BAJO el lock compartido y ANTES de copiar archivos al sitio público.

Si el frontend llega primero, falla cerrado: solo puede haber subido staging privado;
no publica HTML ni assets. Tras backend PASS, usar Re-run jobs del mismo frontend.
flock evita carreras; SHA + marca de éxito imponen la dependencia real.

Futuros pushes siguen siendo automáticos. Un cambio solo de frontend conserva el pin.
Un release coordinado actualiza el pin al nuevo commit backend y publica backend primero;
si cambia el backend y se olvida el pin, frontend se bloquea, no asume compatibilidad.
Migraciones nuevas/desconocidas siguen bloqueando deploy hasta una operación autorizada.
