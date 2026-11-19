# CRM: expiración por inactividad y comprobación de envíos

## Alcance

Se conserva Laravel/Sanctum: cookies de sesión, CSRF y permisos existentes. No se
introducen JWT, tablas ni migraciones. Las rutas públicas y Wompi no cambian.

- `CRM_SESSION_IDLE_MINUTES`: opcional; por defecto **30**, entre 1 y 1440.
- No confundir con `SESSION_LIFETIME`: las peticiones automáticas pueden renovar
  el almacenamiento nativo, pero no el reloj de interacción del CRM.
- El login inicia el reloj; `GET /api/auth/session` lo consulta sin renovarlo.
- Solo `POST /api/auth/activity`, autenticado y protegido por CSRF, confirma
  interacción. Ignora horas y plazos enviados por el cliente.
- El middleware comprueba el vencimiento antes de los controladores del CRM.
  Revoca el token actual o invalida la sesión y devuelve 401 `SESSION_EXPIRED`.
- Las cookies anteriores al release adoptan el reloj una sola vez. Para tokens
  personales previos se utiliza su emisión, nunca `last_used_at` automático.
- El reloj se comparte por credencial. No se cierran otros dispositivos.
- El logout automático identifica la sesión: una pestaña vieja no puede cerrar
  una sesión nueva. Las marcas de revocación impiden revivir un ID invalidado.
- La validación en servidor es efectiva en la próxima petición, aunque el
  navegador esté cerrado o suspendido; no requiere Cron.

El navegador escucha interacción real y limita los avisos al servidor. No usa
polling ni foco como actividad. Sincroniza el vencimiento/logout entre pestañas,
avisa un minuto antes y muestra el motivo al volver al login. No conserva
contraseñas ni tokens en almacenamiento web. No se guardan borradores privados
automáticamente: hay que guardar el formulario antes del vencimiento.

## QA y publicación

Las pruebas usan conexiones prohibidas o SQLite `:memory:` con salvaguardas.
Nunca ejecutar `tests/Feature` contra una base existente para este release.

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit tests/Isolated
python3 ops/test_release_preflight.py
composer audit --no-interaction
```

El runner requiere `pdo_sqlite` solo para pruebas. No es una dependencia nueva
del servidor comercial. GitHub Actions conserva el preflight, backup de código,
SHA exacto y receipt, y no ejecuta migraciones en pushes ordinarios.

1. Publicar backend por fast-forward y esperar SUCCESS.
2. Verificar GET anónimo `/api/auth/me` y `/api/auth/session`: 401 JSON.
3. Actualizar el pin frontend al **SHA final de este backend**, descendiente de
   `dad6dc87079e89b589920d790d1ac9d5abb8c98c`, no al SHA anterior.
4. Publicar frontend solo después del backend y comprobar SUCCESS/receipt.
5. Con una cuenta autorizada: entrar, guardar cualquier formulario pendiente,
   revisar `/api/auth/session`, cerrar manualmente y volver a entrar. Dejar 30
   minutos sin interacción: aviso, login y rechazo de la cookie vencida.

El reloj acelerado se prueba únicamente con fixtures locales. No reducir el
timeout productivo ni manipular el reloj/datos del servidor para probarlo.

## Envia.com: estado separado del release de sesiones

Lectura SSH reportada por el propietario: BAQ ID 1 dispone de 3 unidades de la
variante 64 y 12 de la 65; puede surtir una de cada una. BOG no las dispone.
Ambos orígenes están completos; CAL no aparece. No se cambia de sede por conveniencia.

La misma lectura encontró peso y las tres dimensiones **NULL** en el producto
40 y sus variantes 64/65. El propietario confirmó un destino completo y medidas,
pero estas deben estar **persistidas por el propietario desde el CRM** antes de
consultar la tarifa. No se rellenan registros mediante este release.

Las pruebas aisladas cubren herencia, conversión g→kg y mm→cm, contrato de tarifas,
errores, firma de cotización, importe impuesto por backend, recogida sin transporte
y ausencia de movimientos/pagos. Son respuestas simuladas, no una tarifa comercial.
La consulta real sigue pendiente hasta obtener una respuesta válida del proveedor.
La sonda privada del operador no se publica ni contiene el token; solo permite
consultas geográficas/tarifas con una transacción SQL de solo lectura y usa las
medidas almacenadas. No crea órdenes, guías, etiquetas ni cargos.
