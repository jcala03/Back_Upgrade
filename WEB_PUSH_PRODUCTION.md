# Web Push — preparación y operación de producción

Este documento describe el código local y los workflows versionados. No acredita
la configuración del servidor ni una entrega real a un teléfono. No contiene claves.

## Qué hace un push a main

Hay dos repositorios y dos despliegues independientes:

| Repositorio | Automatización versionada |
| --- | --- |
| `backendupgrade` | `.github/workflows/deploy.yml`: SSH, `git pull --ff-only origin main`, `composer2 install --no-dev --optimize-autoloader --no-interaction --no-scripts`, `package:discover`, `optimize:clear`, `migrate --force`, copia de `public`, ajuste existente de `index.php`, storage link, `config:cache`, `view:cache`, `queue:restart`. |
| `upgrade79-web` | `.github/workflows/deploy.yml`: Node 22, `npm ci`, `npm run build` (cliente y SSR), verifica y copia `dist` al directorio público, preserva los archivos `.htaccess` y `laravel-api.php` administrados en Hostinger; comprueba frontend/API y entrega de `/sw.js`. |

El workflow backend apunta a
`/home/u497825029/domains/crmupgradecol.com/laravel`; el frontend apunta al
`public_html` de `upgradecolombia.com`. Son rutas existentes del deploy, no una
indicación para cambiarlas: el puente API y las reglas del servidor quedan fuera
de Git. Verificar en Hostinger que la API de `upgradecolombia.com` usa ese backend.

Los workflows no ejecutan las suites de tests. No hay configuración versionada
de Supervisor/systemd/process manager, ni instalación del cron del scheduler.
`composer dev` lanza un listener genérico local; no mantiene el consumidor
`database/web-push` de producción. No hay coordinación entre los dos workflows:
publicar el backend primero y verificarlo antes de publicar el frontend.

`queue:restart` solo señala a los workers existentes que salgan tras su trabajo
actual. No arranca procesos. El gestor del servidor debe volver a iniciarlos,
incluso después de una salida limpia, usando el mismo usuario y almacén de caché.

El build produce SSR, pero el workflow frontend solo copia artefactos; no instala
ni inicia un proceso Node para `npm start`. La entrega SSR y las cabeceras estáticas
del servidor real deben verificarse con la configuración existente de Hostinger.

## Archivos que deben incluirse en el próximo commit

Además de los archivos modificados, incluir los nuevos archivos de Push que aún
puedan aparecer como `??` en `git status`. No basta con `git add -u`.

- Backend: `config/webpush.php`, `app/Contracts/WebPushTransport.php`,
  `app/Services/CrmWebPush.php`, `app/Services/VapidWebPushTransport.php`,
  `app/Jobs/SendCrmWebPush.php`, `app/Support/PushEndpoint.php`,
  `app/Models/PushSubscription.php`,
  `app/Http/Controllers/Api/PushSubscriptionController.php`, migración de
  `push_subscriptions`, tests de Push, este documento; cambios en provider,
  rutas, logout, `.env.example`, `composer.json`, `composer.lock` y workflow.
- Frontend: `src/pwa/`, `src/api/push.ts`, `public/sw.js`, manifest, offline,
  iconos, tests y script de QA; integración existente en perfil, arranque,
  autenticación, layout, archivos SEO y workflow.

No incluir `.env`, claves, logs, perfiles/capturas privadas ni salidas de consola
con secretos. `.env` y `.env.production` del backend están ignorados. La
`.env.production` pública del frontend solo puede contener configuración pública;
Vite incorpora `VITE_*` al JavaScript descargable.

## Configuración privada del servidor

Configurar en el backend real, sin copiar `.env.example` encima de su `.env`:

```dotenv
WEBPUSH_ENABLED=true
WEBPUSH_VAPID_PUBLIC_KEY=<publicKey>
WEBPUSH_VAPID_PRIVATE_KEY=<privateKey>
WEBPUSH_VAPID_SUBJECT=https://upgradecolombia.com
WEBPUSH_QUEUE_CONNECTION=database
WEBPUSH_QUEUE=web-push
```

La disponibilidad de `/api/push/config` requiere el interruptor y los tres valores
VAPID no vacíos. No comprueba la salud del worker. `public_key` es la única clave
que devuelve esa API; nunca devuelve la privada ni subject. Requiere sesión,
cuenta activa y `notifications.view`. Las escrituras conservan CSRF y rate limit.

`minishlink/web-push` instalado y fijado por `composer.lock`: **10.1.0**. Su método
`VAPID::validate()` acepta el subject URL indicado; la prueba de transporte firma
un JWT con ese valor y verifica su claim `sub`, usando HTTP simulado.

### Generación VAPID: una sola vez, en terminal privada

Desde la raíz del backend, con `vendor` instalado:

```bash
php -r 'require "vendor/autoload.php"; $keys = Minishlink\WebPush\VAPID::createVapidKeys(); echo json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;'
```

Devuelve `publicKey` y `privateKey`. Ejecutarlo manualmente cuando se vaya a
configurar producción, fuera de GitHub Actions/logs de deploy/chats. Guardar la
pareja en el gestor de secretos y en el entorno privado del backend. No generar
una pareja por build o por despliegue. No ejecutar este comando para un diagnóstico.
Las pruebas automáticas solo generan claves efímeras de QA en memoria, no secretos
de producción ni archivos de claves. Conservar también `APP_KEY`: cifra las
suscripciones en reposo; cambiarla dejaría ilegibles las existentes.

## Cola y migraciones

`CrmWebPush::created()` fija explícitamente conexión y cola desde `webpush.*`,
independientes de `QUEUE_CONNECTION`. Con los valores anteriores, incluso si la
cola general es `sync`, Push usa `database/web-push`. Mantener esta conexión
persistente: no sustituirla por `sync`.

Migraciones necesarias:

- `0001_01_01_000002_create_jobs_table.php`: `jobs`, `job_batches`, `failed_jobs`
  (ya forma parte del historial Git).
- `2026_10_24_000001_create_push_subscriptions_table.php`: suscripciones, FK de
  usuario, hash único, claves/endpoints cifrados mediante el modelo, expiración.

`php artisan migrate --force` es suficiente para crear las tablas pendientes,
una vez incluidas ambas migraciones en Git y con la base/permisos correctos.
El workflow ya lo ejecuta; no ejecutar rollback ni recrear tablas. Confirmar el
estado real en producción con `php artisan migrate:status`. El estado local no
demuestra qué migraciones están aplicadas en producción. Las migraciones de
compatibilidad OEM presentes en el worktree pertenecen a otra funcionalidad.

El job tiene 3 intentos, timeout 30 s y backoff de 60/300 s. El transporte tiene
timeout 15 s y connect timeout 5 s, sin seguir redirecciones. `retry_after` de la
conexión database es 90 s por defecto y debe superar el timeout del worker (45 s)
y del job. Verificar cualquier override `DB_QUEUE_*`: si la cola usa otra base o
tabla, las tablas deben existir allí. `failed_jobs` usa la configuración de
`queue.failed`.

404/410 y expiración/endpoints inválidos desactivan la suscripción. Errores de
transporte, 429 y 5xx fallan el job y permiten reintento; otros rechazos se
registran como fallos tras agotar los intentos. Los errores del job se sanean:
no se propagan mensajes de proveedor que puedan contener endpoints o claves.
Los payloads de jobs contienen IDs, no claves de suscripción ni VAPID. Revisar
fallos con `php artisan queue:failed` y corregir la causa antes de reintentarlos;
los avisos leídos o de más de 24 h se omiten al ejecutar el job.

## Worker obligatorio en Hostinger

Desde la raíz del backend real, con el mismo entorno/usuario que Laravel:

```bash
php artisan queue:work database --queue=web-push --tries=3 --timeout=45
```

El servidor debe mantenerlo vivo y arrancarlo de nuevo tras reinicios/deploy.
No hay evidencia del plan o gestor disponible; no se instala una configuración
específica desde este repositorio. Alternativas a confirmar con el servidor:

- Supervisor: programa con reinicio también tras salida limpia y arranque al boot.
- systemd: servicio con reinicio también tras `queue:restart` y arranque al boot.
- Gestor de procesos de Hostinger, **si el plan lo ofrece**, con el mismo comando.
- Si solo se permite cron: ejecuciones periódicas de
  `php artisan queue:work database --queue=web-push --tries=3 --timeout=45 --stop-when-empty --max-time=50`,
  protegidas con un lock del servidor para impedir solapamientos. Configurar rutas
  absolutas a PHP/artisan, directorio de trabajo, permisos y logs privados. Es
  un consumidor por lotes con latencia del cron, no un proceso permanente.

No se elige ni instala una de estas alternativas sin confirmar el entorno.
El cron existente de Laravel, si se requiere para alertas programadas, debe
ejecutar `php artisan schedule:run` cada minuto. El repositorio programa las
cotizaciones próximas a vencer a las 08:00 en la zona del negocio y la expiración
de reservas cada minuto; no instala ese cron en el sistema. Push inmediato nace
del commit de una notificación CRM y no requiere un scheduler Push adicional.

Después de cambiar variables manualmente: `php artisan config:cache` y
`php artisan queue:restart`, con el gestor ya preparado. El workflow realiza
ambos pasos en los despliegues posteriores; no rellena variables ni secretos.

## Frontend, HTTPS y prueba de aceptación

Se registra `/sw.js` con alcance `/` y `updateViaCache: "none"`, solo en build de
producción y contexto seguro. `npm run dev` no lo registra. El SW recibe Push,
comprueba el propietario guardado del dispositivo y permite clics únicamente
a rutas CRM del mismo origen. No cachea respuestas privadas. Perfil no pide
permiso al montar: requiere clic explícito para suscribir el dispositivo.

Hostinger debe servir `/sw.js` como JavaScript, sin redirect/HTML/login y sin
cache inmutable, además de manifest, offline e iconos en la raíz. El workflow
comprueba archivos y respuesta pública del SW, pero no modifica `.htaccess` ni
las reglas de caché del servidor. HTTPS válido es obligatorio en dispositivos;
localhost es apto para QA de escritorio. Una IP LAN HTTP no sirve para teléfonos.
Confirmar salida HTTPS desde PHP a los proveedores permitidos y extensiones
OpenSSL/cURL/mbstring. No es necesario instalar un servidor WebSocket.

Prueba local después de configurar un entorno de prueba separado:

1. Ejecutar tests backend exclusivamente en `upgrade_test` (el guard comprueba
   también `SELECT DATABASE()`), frontend tests y build cliente/SSR.
2. Servir el build con `npm start` o preview; configurar API accesible, cookies,
   CORS/Sanctum para el origen local. Para QA automatizada usar
   `scripts/verify-pwa-browser.mjs` contra el servidor SSR: usa API/proveedor
   simulados y un SW real, sin probar entrega física.
3. Para entrega real, configurar una pareja VAPID del entorno de pruebas fuera
   de Git, su worker y entrar con un usuario autorizado. Confirmar que
   `/api/push/config` devuelve `enabled: true` y solo la clave pública.
4. Activar desde Mi perfil; verificar registro propio y estado activo, cerrar
   CRM y generar una notificación operativa admitida para ese usuario.
5. Comprobar notificación, clic, contador y `last_sent_at`; desactivar y comprobar
   que ese dispositivo deja de recibir. Probar varias cuentas y dispositivos.

Repetir en producción después de la preparación privada de Hostinger, con
Android e iPhone físicos (iPhone instalado en pantalla de inicio). Verificar
aislamiento al cambiar cuenta/cerrar sesión, expiración, worker y jobs fallidos.
La configuración `enabled: true` y un test con proveedor simulado no demuestran
recepción real del sistema operativo. Los destinatarios y permisos siguen los
de la bandeja CRM; activar Push no concede alertas administrativas a Usuario.

## Lo que Git no puede completar

Las claves/variables reales, el gestor del worker, cron, reglas de Hostinger,
permisos de navegador y suscripciones de dispositivos requieren preparación
del entorno. Un push publica código y ejecuta migraciones/cachés, pero **no
activa Push por sí solo**. No publicar secretos para eliminar estos pasos.

## Validación local de esta revisión (2026-10-27)

- Backend: suite completa **707 tests / 7.407 aserciones**, correcta. Suite de
  Push y notificaciones: **22 tests / 180 aserciones**, correcta. Guard de pruebas
  exige MySQL `upgrade_test` y comprueba la base efectiva antes de modificar datos.
- Pint sobre los archivos PHP de Push, provider, logout y rutas: correcto.
- Frontend: **8/8 tests PWA**; suite completa **75/76**, con fallo previo ajeno a
  Push en `tests/ecommerce-r2.test.mjs:244`: el regex exige `<WeekView days={days}`
  sin salto de línea, mientras el JSX del calendario está formateado en varias.
  No se modificó el calendario ni esa prueba en esta tarea.
- Build con los dominios del workflow: cliente y SSR correctos; aviso existente
  de chunk mayor de 500 kB. Artefactos SW/manifest/offline/iconos presentes.
- QA de navegador: **19 comprobaciones**, cero errores de página; SW real sobre
  servidor SSR, instalabilidad Chrome, desktop/tablet/móvil, registro diferido,
  activación/desactivación, clic, actualización, offline, error de consulta y
  recuperación. API, suscripción y entrega del proveedor simuladas; no constituye
  prueba física FCM/APNS/Mozilla.
- Workflows: YAML y sintaxis de sus bloques Bash comprobados sin ejecutarlos.
- `composer audit --locked --no-dev` mantiene el aviso **CVE-2026-108082** de
  `symfony/http-foundation` presente antes de esta revisión. Su corrección requiere
  una actualización de dependencias independiente de Push; no se declara el
  conjunto de dependencias libre de vulnerabilidades.

No se generaron claves de producción ni se modificó su entorno; no se activó
Push, ejecutó migración de producción, commit, push o deploy. Quedan pendientes
la preparación privada del servidor y la prueba de recepción en dispositivos.
