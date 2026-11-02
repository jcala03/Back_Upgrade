# Publicar la política Wompi de producción

Preparación local, no ejecutada. Los comandos de commit, push y SSH de este documento
requieren autorización posterior. No editar .env, borrar credenciales, migrar ni
procesar pagos para desbloquear este release.

## Causa y política

El preflight original se introdujo en a3fb7b7 para el primer release sin compras
reales. Rechazaba WOMPI_PUBLIC_KEY y WOMPI_INTEGRITY_SECRET no vacías. Hostinger
confirmó que esos dos controles fallan porque fueron configuradas deliberadamente.
Los demás controles de configuración pasan; el esquema aún no se ha inspeccionado
en este intento porque el proceso se detuvo antes.

La política nueva exige las cuatro credenciales productivas presentes, con prefijos
pub_prod_, prv_prod_, prod_integrity_ y prod_events_, sufijo no vacío, caracteres
ASCII imprimibles y sin espacios/controles. También valida la reserva (entero 1–1440).
Los prefijos corresponden a la documentación oficial:
https://docs.wompi.co/docs/colombia/ambientes-y-llaves/

Se conservan las URLs oficiales, retorno exacto, APP_ENV=production, debug apagado,
CORS exclusivamente público, correo real desactivado, configurador no comprable,
identidad MySQL e historial/columna nullable configuration_context. No se modifica
checkout, firma, webhook, conciliación, idempotencia, inventario ni precios.
El error Wompi solo enumera nombres de controles, nunca valores o excepciones SQL.

**Límite:** no se autentican claves contra Wompi. Prefijos correctos no prueban
vigencia, titularidad común ni rotación de secretos expuestos. Confirmar estos
aspectos privadamente antes de aceptar cobros. La aplicación ya permitía checkout
tradicional con su configuración existente; el preflight no es un interruptor de
pagos. No realizar compras reales como prueba de esta corrección.

## Orden sin bloqueo circular

1. Autorizar y publicar el commit backend corregido.
2. Esperar éxito COMPLETO del workflow backend, no solo su validación.
3. Verificar HEAD, recibo de despliegue y preflight actualizado mediante SSH de lectura.
4. Actualizar únicamente el SHA requerido en el frontend, crear su commit y publicarlo.

El backend ejecuta **el preflight del candidato** extraído del commit del evento,
con vendor/configuración de la instalación existente. No ejecuta primero el
preflight antiguo. El nuevo helper se entrega dentro del archivo `ops` del candidato.
Tras checkout, ejecuta de nuevo el preflight ya instalado. Por tanto NO requiere
editar manualmente el preflight de Hostinger ni desactivar controles.

El frontend sí ejecuta el preflight instalado y exige HEAD + recibo backend iguales
a `.github/required-backend-sha`. Reintentar el frontend antiguo después de publicar
otro SHA backend fallaría en esa comprobación. No reutilizar ni forzar un recibo viejo.

## Comandos posteriores a la autorización (misma terminal local)

Desde backendupgrade. Revisar que no haya cambios staged ajenos antes de continuar:

```bash
set -euo pipefail
git status --short
git diff --cached --name-only
git diff --check
git add -- .github/workflows/deploy.yml ops/release-preflight.php \
  ops/ReleaseWompiPolicy.php ops/test_release_preflight.py \
  tests/Unit/ReleaseWompiPolicyTest.php ops/RELEASE_HOSTINGER.md \
  ops/FIRST_RELEASE_BOOTSTRAP.md ops/WOMPI_PREFLIGHT_RELEASE.md
git diff --cached --check
git diff --cached --stat
# Revisar el contenido staged; excluir cualquier archivo ajeno antes de autorizar commit.
git diff --cached
git commit -m "fix: validate complete production Wompi configuration before deploy"
backend_release_sha=$(git rev-parse HEAD)
git push origin HEAD:main
gh run list --repo jcala03/Back_Upgrade --commit "$backend_release_sha" --limit 1
```

Esperar a que aparezca el run de ese SHA; la consulta es de lectura y puede repetirse.
Después esperar a su finalización (no continuar si exit status es distinto de cero):

```bash
backend_run_id=$(gh run list --repo jcala03/Back_Upgrade --commit "$backend_release_sha" \
  --limit 1 --json databaseId --jq '.[0].databaseId // empty')
test -n "$backend_run_id"
gh run watch "$backend_run_id" --repo jcala03/Back_Upgrade --exit-status

ssh -p 65002 u497825029@212.85.29.152 \
  "RELEASE_EXPECTED='$backend_release_sha' bash -se" <<'REMOTE'
set -euo pipefail
cd "$HOME/domains/crmupgradecol.com/laravel"
test "$(git rev-parse HEAD)" = "$RELEASE_EXPECTED"
test "$(cat "$HOME/.upgrade79-backend-deployed-sha")" = "$RELEASE_EXPECTED"
php ops/release-preflight.php --require-current
REMOTE
```

El resultado debe indicar PASS, cero migraciones pendientes y columna compatible.
Si falla el esquema, detenerse: no ejecutar migrate, config:cache ni reintentar con
otra política para ocultarlo. Inspeccionar la condición real por separado.

Solo tras ese éxito, en la misma terminal:

```bash
cd ../upgrade79-web
git status --short
# Abrir este archivo e introducir únicamente el SHA impreso abajo, conservando LF.
printf 'SHA backend que debe quedar en el archivo: %s\n' "$backend_release_sha"
nano .github/required-backend-sha
test "$(cat .github/required-backend-sha)" = "$backend_release_sha"
git diff --check
git add -- .github/required-backend-sha
git diff --cached
git commit -m "ci: pair frontend with production Wompi preflight backend"
frontend_release_sha=$(git rev-parse HEAD)
git push origin HEAD:main
gh run list --repo jcala03/Front_Upgrade --commit "$frontend_release_sha" --limit 1
```

Esperar el run correspondiente y revisar su resultado, sin aprobar manualmente cada
deploy ordinario. Ambos workflows conservan push a main, validaciones y lock común.
No ejecutar workflow_dispatch de migración para este cambio: no hay migraciones nuevas.

## Si algo falla

- Un rechazo del candidato ocurre antes de checkout, Composer, mantenimiento y
  publicación. Conservar el staging privado; no borrar datos para reintentar.
- Si aparecen archivos locales modificados en Hostinger, detenerse y revisar su
  diff en privado. No ejecutar reset/restore/clean para desbloquearlo.
- Un fallo posterior al checkout puede dejar el backend parcialmente actualizado
  o en mantenimiento. No publicar frontend ni falsificar el recibo; revisar el log
  y usar el respaldo de código generado por el workflow bajo el mismo lock, con
  autorización específica. No hay cambios de esquema que revertir en este release.
- La sonda histórica diagnose-release-preflight.php está fijada por hashes al
  preflight antiguo. Tras este release debe negarse a ejecutarse, no reinterpretar
  la política nueva como si todavía exigiera claves vacías.
