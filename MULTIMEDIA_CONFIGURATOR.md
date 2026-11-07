# Configurador multimedia — contrato y activación controlada

Trabajo local sobre el backend publicado `85d6a625e281d24042f1fbf5e94e46d78549bf66`, rama `feature/multimedia-configurator`. Preparación de commit local autorizada después del QA final; no es un release publicado. No se modificaron Hostinger, credenciales, registros, pagos, inventario ni migraciones.

## Diagnóstico e impacto

| Área existente | Hallazgo | Cambio mínimo |
|---|---|---|
| CRM de productos y campos dinámicos | Ya permite especificaciones con scope variant, imágenes y compatibilidades por SKU | Se reutiliza sin otro editor; no cambiar scopes históricos |
| Productos/variantes | IDs y SKU únicos, precios comerciales independientes | Sin columnas nuevas ni generación de combinaciones |
| Registro del configurador | Solo perfil OEM/memoria; producción bloqueada incluso con flags | Segundo perfil por referencia y activación productiva explícita, apagada por defecto |
| Compatibilidades | Filtrado y compra ya comparan vehículo/OEM en la misma fila | Exigir año real si cobertura registrada es parcial; conservar límites publicados |
| Carrito, órdenes y reservas | Contexto histórico y stock compartido por SKU ya implementados | Contexto del perfil por referencia no fabrica memoria; no modificar reservas |
| Checkout | Validador de contexto y precios del servidor ya existen | Mantener RAM obligatoria por política del perfil de memoria; rechazarla en el perfil por referencia |
| Categorías OEM obligatorias | Un producto sin inscripción podía caer en selector tradicional | Devolver configuración requerida pero bloqueada: no activa la categoría ni permite omitir OEM |
| Preflight | Rechazaba cualquier compra configurable | Solo admite aprobación independiente, catálogo exacto y cada producto revisado; conserva URL/CORS/Wompi/esquema/SMTP |

## Esquema realmente inspeccionado, solo lectura

Conexión verificada: APP_ENV local, XAMPP/MariaDB 10.4.32, base `upgrade`. La inspección utilizó una transacción READ ONLY finalizada con rollback, sin migraciones ni escrituras.

- `products`: SKU/slug únicos, categoría nullable con FK, flags activo/visible y configuración comercial existentes.
- `product_variants`: FK al padre; SKU único nullable; precios y estado propios; índices padre/activo/principal/visible.
- `product_category_fields`: scope, tipo, unidad y estado existentes; unique categoría + field_key.
- `product_variant_spec_values`: decimal(12,2) nullable y unique variante + campo, ambas FK existentes.
- `product_variant_compatibilities`: FK a variante, marca, modelo, generación y OEM; años nullable e índices existentes.
- `vehicle_version_multimedia_system`: cobertura parcial nullable por relación; se admiten intervalos múltiples sin inventar continuidad.
- `order_items.configuration_context`: existente y nullable; MariaDB expone el JSON como longtext. Órdenes históricas conservan null.

No se necesitan tablas, columnas, índices, endpoints ni migraciones nuevos para estos cambios.

La categoría local Pantallas Android, ID 6, tiene `ram_gb` y `almacenamiento_gb` numéricos, scope variant, GB y activos. Hay cuatro productos y nueve variantes de esa categoría. No hay categoría Linux local. No se crearon ni modificaron categorías, atributos o productos para suplir esa ausencia.

## Perfiles explícitos, independientes del sistema operativo

1. `vehicle-oem-memory-v1`: vehículo, OEM y pareja RAM/almacenamiento estructurada por variante. No admite inferir atributos por nombre ni copiarlos del padre.
2. `vehicle-oem-reference-v1`: vehículo, OEM y referencia comercial exacta. Apropiado para kits sin opciones de memoria publicadas, incluyendo Linux cuando corresponda. No admite RAM ficticia ni atributos de memoria enviados por el cliente.

No se elige perfil por texto Android/Linux, marca, slug o categoría. Un equipo Linux con memoria realmente registrada puede usar el primer perfil. Productos tradicionales y categorías sin requisito OEM conservan su flujo anterior.

Si una categoría exige OEM y falta una inscripción revisada, la API devuelve `configuration.required=true`, UI/compra apagadas y perfil null; el servidor rechaza compras sin configuración válida. Esto cierra la omisión del requisito, no crea una inscripción automática.

## Contrato API y validación

Se reutilizan GET /api/products, GET /api/products/:slug, GET /api/vehicle-* y POST /api/orders.

- `configuration` mantiene schema_version 1 y sus seis campos; admite el nuevo profile_id.
- Perfil de memoria: DTO conserva ram_gb y almacenamiento_gb, obligatorios en el validador comercial registrado aunque el FormRequest permita omitirlos para otros perfiles.
- Perfil por referencia: DTO no incluye esas claves; el validador las rechaza si se envían.
- Vehículo/OEM, pertenencia padre-variante y estados activos/visibles siguen verificados en servidor.
- Si años comerciales u OEM cubren solo una parte de la generación, un año null no permite comprar. Una unión técnica que cubre la generación completa no obliga a inventar año.
- El precio procede del mecanismo comercial existente; nombres, SKU y etiquetas del snapshot se reconstruyen en el servidor.
- Se conserva product_id + product_variant_id y el discriminador contextual para líneas OEM diferentes de un mismo SKU.
- La comprobación de surtido, bloqueo y reserva original no cambia. Stock público agregado sigue siendo provisional.
- Revalidación antes de la primera reserva, idempotencia, reintentos y snapshots históricos existentes se conservan.

## Activación preparada, NO ejecutada

Por autorización expresa se preparó quitar el bloqueo absoluto de entorno, sustituyéndolo por condiciones estrictas. Los valores por defecto productivos continúan apagados:

```dotenv
PRODUCT_CONFIGURATOR_PREVIEW_ENABLED=false
PRODUCT_CONFIGURATOR_PURCHASE_ENABLED=false
PRODUCT_CONFIGURATOR_PRODUCTION_APPROVED=false
PRODUCT_CONFIGURATOR_REGISTRATION=
```

No se modificó ningún .env. El opt-in local previo del producto 6 se conserva cuando no se suministran variables explícitas; no se traslada a producción.

Un operador deberá preparar posteriormente un JSON privado con `catalog` y `products`. Ejemplo de estructura, NO valores para copiar:

```json
{
  "catalog": {
    "environment": "production",
    "driver": "mysql",
    "host": "HOST_VERIFICADO",
    "port": "3306",
    "database": "BASE_PRODUCTIVA_VERIFICADA"
  },
  "products": {
    "ID_REAL_REVISADO": {
      "profile_id": "vehicle-oem-memory-v1",
      "profile_version": 1,
      "reviewed": true,
      "approved_variant_ids": [456]
    }
  }
}
```

Los IDs del ejemplo deben reemplazarse por IDs enteros reales revisados: 456 es solo ilustrativo, no una referencia autorizada. Todas las identidades de catálogo son strings exactas. No añadir contraseñas al JSON ni compartir el .env. JSON inválido falla explícitamente en vez de activar un fallback.

Antes de activación futura:

1. Revisar físicamente cada SKU, kit, vehículo/OEM, años, atributos, imágenes, precio y stock por sede. No aprobar fixtures, QA ni DEMO.
2. Preparar el release con backend primero y pin frontend del nuevo SHA. El SHA de este commit se obtiene con git rev-parse HEAD; el frontend debe fijarlo exactamente antes de crear su commit. La autorización actual permite commits locales, no publicación ni activación.
3. Desplegar código manteniendo flags apagados; verificar esquema/contratos. No activar un perfil nuevo contra el backend anterior.
4. Con autorización separada de configuración productiva, definir catálogo exacto y productos reviewed; aprobar producción y UI, y habilitar compras solo al completar verificaciones. Reconstruir caché y ejecutar preflight de lectura en el entorno correcto.

El registro exige coincidencia exacta de catálogo. En producción requiere además aprobación independiente y reviewed por producto; bloquea referencias identificadas QA/DEMO. Para comprar exige approved_variant_ids: lista no vacía, sin duplicados, de IDs enteros positivos. El padre revisado no autoriza automáticamente variantes nuevas. La API expone únicamente la lista pública purchasable_variant_ids para deshabilitar esas referencias también en React; el validador de compra vuelve a comprobarla en servidor. Una lista omitida, vacía o inválida bloquea compras, no se interpreta como «todas».

El preflight exige flags coherentes, perfiles soportados y catálogo igual a la conexión efectiva, además de los controles previos de base, URLs, CORS y pagos. Si compra está activa exige la lista individual por producto. Su helper nuevo viaja con ops en el paquete candidato existente; no hay que debilitar el workflow ni actualizarlo para publicar el propio validador.

## Desactivación y compatibilidad

Mantener la inscripción y poner PURCHASE_ENABLED=false impide compras configurables, incluidas llamadas directas. PREVIEW_ENABLED=false deshabilita además la UI, sin convertir la referencia inscrita en tradicional. No borrar la inscripción para desactivar una referencia obligatoria.

Antes de retirar código que conoce el perfil por referencia, deshabilitar nuevas compras y comprobar carritos/órdenes que ya lo contengan. No borrar snapshots ni restaurar datos para hacer rollback de código. El frontend anterior no entiende el perfil nuevo y lo mantiene bloqueado, no debe ofrecer una sustitución silenciosa.

La base `u497825029_upgrade_ux_qa` es producción y permanece fuera del alcance. PHPUnit Feature existente exige otra base y utiliza RefreshDatabase: no se ejecutó contra upgrade, producción ni upgrade_test. La evidencia inicial era unitaria, con PDO que rechaza conexiones y SQL evaluado en memoria de Python. El QA final siguiente añade persistencia real mediante PDO/Eloquent y el kernel HTTP de Laravel sobre SQLite :memory:, sin abrir conexiones MySQL.

## QA final — persistencia y bloqueo

Se reutilizaron ambos worktrees feature, conservando sus HEAD y los cambios ajenos en los worktrees originales. No hubo commit, push, acceso a Hostinger, alteraciones de .env ni escrituras en bases existentes. No se creó ninguna base MySQL nueva.

El PHP 8.3 del sistema carecía de pdo_sqlite. Se descargó y extrajo el paquete Ubuntu compatible bajo `/tmp/upgrade79-sqlite-runtime.AXBDLt`, cargando la extensión solo mediante `php -d extension=...`. No se instaló ningún paquete del sistema ni se cambió php.ini. El PHP de XAMPP (8.0) no cumple los requisitos del backend y no se utilizó para ejecutar Laravel.

El harness `tests/Support/MultimediaSqliteApplication.php` no lee .env ni caché de configuración. Antes del bootstrap de proveedores elimina todas las conexiones salvo SQLite :memory:, fija correo en memoria, finge Mail/Queue e impide HTTP externos. Comprueba driver PDO, nombre :memory:, PRAGMA database_list sin archivos y foreign_keys activas. Ante ausencia de SQLite o configuración incompatible falla, sin fallback a MySQL. La clave de cifrado del harness es sintética y exclusiva del proceso de pruebas.

Se construye el esquema en memoria desde las migraciones reales. Adaptaciones **solo de pruebas**, no modificaciones de las migraciones productivas:

- CHECK de tres tablas de inventario: mismo contenido dentro de CREATE TABLE, en lugar de ALTER ADD CONSTRAINT no soportado en SQLite.
- Dos FK de inventory_movements: eliminación por columnas en vez de nombre.
- Eliminación explícita del índice stock antes de quitar la columna histórica.
- Colación utf8mb4_bin registrada en SQLite con comparación binaria para claves sintéticas ASCII.
- El cambio histórico sales → user no tiene datos que convertir en la base vacía; se aplica el mismo default final mediante Schema.

Esto no certifica migraciones MySQL, equivalencia completa de colaciones, bloqueos FOR UPDATE ni concurrencia MariaDB. Esas verificaciones específicas requieren una base MySQL **nueva, aislada y autorizada**; ninguna base existente es una alternativa válida.

Resultados finales:

| Validación | Resultado |
|---|---|
| Unidades backend, sin conexión DB | 154/154 PASS; 555 aserciones |
| Integración SQL/HTTP SQLite | 36/36 PASS; 280 aserciones |
| Preflight CLI con configuración simulada | 9/9 PASS |
| Contratos de despliegue | 13/13 PASS |

La suite SQL verifica perfiles memoria y referencia; BMW/CIC/NBT y Toyota/Touch con relaciones sintéticas explícitas; límites técnicos 2012/2013 y 2014/2018; rechazo de año omitido o incompatible; relaciones padre/variante; memoria por variante independiente; precios del servidor; snapshots JSON leídos con DB::table; lectura pública de checkout y lectura CRM autenticada del mismo contexto. También comprueba idempotencia, conflicto de contexto, dos OEM del mismo SKU, stock físico único, stock insuficiente y sedes que no pueden surtir pese al agregado de red.

Se rechazan mediante POST real al kernel /api/orders: omisión/manipulación de contexto, ID inexistente/ajeno, variante oculta/inactiva, memoria inventada en perfil de referencia, combinación ficticia entre dos filas diferentes de compatibilidad, ausencia de inscripción obligatoria, catálogo distinto, inyección de precio y flags desde cliente. Las solicitudes incluyen datos de cliente válidos e Idempotency-Key: no se confunden esos rechazos con errores de formulario ajenos al configurador.

La suite simula el entorno production **solo en la aplicación aislada en memoria** y demuestra que una variante nueva no hereda la aprobación de su padre. Las referencias aprobadas en ese test no existen fuera de SQLite. No se activó ninguna referencia del catálogo local o productivo.

La revalidación de una orden cuyo atributo cambia falla antes de reservar y conserva el JSON histórico. Las existencias solo se preparan en fixtures en memoria; los flujos comprobados no generan movimientos ni pagos. OrderCreatedMail queda capturado por Mail::fake; ningún correo sale del proceso.

### Reproducir sin tocar bases existentes

Con pdo_sqlite disponible (o especificando su extensión temporal):

```bash
php -d extension=/tmp/upgrade79-sqlite-runtime.AXBDLt/runtime/usr/lib/php/20230831/pdo_sqlite.so \
  vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  --do-not-cache-result tests/Isolated/MultimediaPersistenceTest.php
```

No usar phpunit.xml, artisan test, RefreshDatabase ni migrate:fresh sobre las bases existentes para repetir este QA. No se modificaron los guardas históricos de Tests/TestCase.php o phpunit.xml.

El navegador integrado utiliza `tests/Support/multimedia-http-bridge.php`: CLI por stdio, sin servidor público ni puerto PHP; entrega cada solicitud al kernel HTTP real con la misma conexión PDO en memoria. No es un endpoint nuevo, no acepta SQL arbitrario ni llamadas de pago. El resultado y las capturas se documentan en el informe frontend.

**Publicación con compras desactivadas:** apta tras preparar el release backend → frontend y su nuevo pin de SHA. No se autoriza venta ni se cambia configuración remota. Siguen pendientes la certificación comercial/física de cada SKU, catálogo Linux real y una autorización separada de activación. Un flag global o parámetros enviados por React no sustituyen el catálogo exacto, reviewed, aprobación de producción ni lista individual de variantes.

## Preparación del release local

Tras fetch de origin/main no se detectaron cambios remotos posteriores al backend base 85d6a625e281d24042f1fbf5e94e46d78549bf66. La rama feature no tenía commits propios; este release añade un único commit: `feat(multimedia): enforce approved OEM configurations and variants`. Los worktrees originales, sus ramas main y sus cambios pendientes se conservan sin modificación.

Se incluyen exclusivamente el contrato y registro del configurador, validador de contexto, helper anual, plantilla de flags desactivados, política de preflight y pruebas/documentación asociadas. Los fixtures son código de pruebas aisladas, no seeders ni datos para publicar. No se incluyen dumps, .env privados, imágenes sintéticas, artefactos de navegador/build ni scripts de carga DEMO. No cambian dependencias, migraciones, Wompi, inventario/reservas, autenticación ni PWA.

La publicación futura sigue siendo automática por push a main y mantiene workflow_dispatch. No hay que modificar ni saltar el workflow: valida PHP/Pint/unidades/preflight y Composer antes de SSH. El preflight candidato viaja con ops, de modo que no depende de actualizar antes el validador instalado. Después comprueba también la configuración nueva sin caché anterior y vuelve a generar caché. No ejecuta migraciones durante un push ordinario.

Condiciones que pueden detener el futuro despliegue (no se comprobaron remotamente en esta preparación):

- Configuración efectiva o sin caché incompatible: entorno no productivo, debug activo, SMTP real habilitado, URLs/CORS/Wompi incorrectos o credenciales incompletas/incompatibles.
- Registro JSON inválido o intento de activación sin revisión/catalogación/lista individual coherente. Para este release deben conservarse los tres flags en false y no inscribir/aprobar referencias productivas.
- Base efectiva diferente de la configurada, migraciones pendientes/desconocidas, o ausencia/tipo incompatible de order_items.configuration_context. No hay migraciones nuevas en este release.
- Modificaciones Git pendientes del servidor, divergencia de historial o plataforma/dependencias inseguras. No se regularizan ni se descartan automáticamente.

Después de autorizar publicación: actualizar origin/main mediante fast-forward desde este commit exacto (no desde el main local atrasado), esperar éxito de Actions backend y comprobar SHA instalado + receipt privado. Solo entonces publicar el commit frontend cuyo pin corresponda. El frontend verifica ambos SHA bajo lock **antes** de reemplazar archivos públicos; un lock por sí solo no establece el orden. Ante fallo se detiene la secuencia, no se publica frontend ni se activan compras. No usar force push.
