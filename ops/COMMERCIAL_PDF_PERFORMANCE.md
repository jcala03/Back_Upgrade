# Rendimiento PDF comercial — medición y cambio local

## Conclusión y límites

Confirmado: cada descarga creaba `fontCache` dentro del directorio temporal del
PDF y lo borraba al terminar. CPDF volvía a interpretar los `.ufm` de DejaVu Sans
regular/negrita y a generar sus JSON. El código de la biblioteca que lo confirma
está en `vendor/dompdf/dompdf/lib/Cpdf.php`, método `openFont()`.

Ese trabajo innecesario cuesta aproximadamente 20–30 ms por documento en este
equipo. La corrección mejora la generación un **4,8–8,8 %** en las muestras;
**no demuestra por sí sola la causa completa de una demora de varios segundos**.
El usuario confirmó lentitud tanto local como en producción.

No se pudo medir MySQL, preparación con registros reales ni el endpoint
autenticado completo: actualmente no hay MySQL escuchando en 3306/3307. Esto es
una limitación de la medición actual, no una explicación retroactiva del síntoma.
No se inició MySQL ni se escribieron registros. Tampoco se accedió a Hostinger.

## Recorrido inspeccionado

1. `CommercialDocumentActions.tsx` → `downloadCommercialDocument()` →
   `apiDownload()` → un GET con credenciales, sin petición previa a `/document`.
2. Rutas existentes, `auth:sanctum`, usuario activo y limitación de solicitudes.
3. `CommercialDocumentController::record()` conserva permisos admin/own y
   aislamiento por `sales_employee_id` para consultas propias.
4. `CommercialDocumentData::make()` carga relaciones en lote y prepara snapshots;
   no se cambió. No hay evidencia estática de un bucle N+1, pero falta el conteo
   SQL real para confirmarlo con datos del entorno afectado.
5. Blade → Dompdf (`loadHtml`, `render`, `output`) → respuesta privada no-store.
6. React consume el Blob, crea Object URL y pulsa el enlace de descarga. El timer
   de 1000 ms solo revoca la URL **después** del clic; no retrasa la descarga.

Endpoints conservados:

- `/api/admin/quotations/{id}/pdf`
- `/api/admin/orders/{id}/pdf`
- `/api/my/quotations/{id}/pdf`
- `/api/my/sales/{id}/pdf`

No se detectaron renderizados duplicados, solicitudes remotas de recursos ni un
logo pesado: el original mide 16.896 bytes y leerlo/convertirlo cuesta ~0,04 ms.
Fuentes embebidas con subconjunto y compresión existentes se conservan.

## Medición de generación (ms)

Siete rondas alternadas por caso, en procesos PHP CLI independientes; primera
ronda excluida, mediana de seis observaciones por modo. Baseline: servicio del
HEAD `111073f`, con el diseño monocromático aprobado. Después: servicio real
modificado, caché de métricas caliente. No se reutilizaron instancias Dompdf.
Son DTOs sintéticos: no se asigna un valor ficticio a SQL o preparación comercial.

| Caso | Páginas | Antes | Después | Mejora | Bytes antes = después |
|---|---:|---:|---:|---:|---:|
| Cotización | 1 | 269,76 | 248,89 | 7,7 % | 40.650 |
| Orden | 1 | 274,90 | 250,73 | 8,8 % | 40.783 |
| Cotización larga | 4 | 428,03 | 399,28 | 6,7 % | 45.496 |
| Orden larga | 4 | 422,79 | 402,60 | 4,8 % | 45.349 |

Cada celda siguiente es **antes → después**, en ms. Medianas por etapa: no es
correcto exigir que su suma sea exactamente la mediana del total.

| Etapa | Cotización 1p | Orden 1p | Cotización 4p | Orden 4p |
|---|---:|---:|---:|---:|
| Consultas MySQL | N/D | N/D | N/D | N/D |
| Preparación comercial con registros | N/D | N/D | N/D | N/D |
| Opciones / espacio / validación caché | 1,08 → 4,70 | 1,20 → 5,17 | 1,06 → 4,67 | 1,13 → 4,81 |
| Lectura del logo | 0,04 → 0,05 | 0,04 → 0,05 | 0,04 → 0,04 | 0,04 → 0,06 |
| Constructor Dompdf | 19,21 → 19,07 | 19,21 → 19,01 | 19,62 → 19,11 | 19,01 → 19,83 |
| Blade | 6,77 → 6,33 | 6,23 → 6,83 | 6,87 → 5,70 | 6,51 → 6,80 |
| Dompdf: parseo HTML | 6,71 → 7,35 | 8,11 → 7,52 | 13,28 → 13,05 | 13,65 → 13,15 |
| Dompdf: layout/render | 132,75 → 103,47 | 135,03 → 105,70 | 280,02 → 246,51 | 276,06 → 250,15 |
| Pie | 0,07 → 0,08 | 0,08 → 0,08 | 0,24 → 0,22 | 0,19 → 0,19 |
| Dompdf: salida / fuentes embebidas | 102,53 → 105,49 | 104,60 → 105,58 | 107,78 → 106,74 | 105,64 → 108,45 |
| Limpieza | 0,34 → 0,28 | 0,38 → 0,25 | 0,31 → 0,17 | 0,29 → 0,18 |

La mejora está en evitar el parseo UFM dentro de render; no se redujo la calidad
de fuentes, el contenido o la precisión. La primera generación en frío todavía
necesita producir las métricas. Los tiempos absolutos CLI no equivalen a PHP-FPM.

## HTTP y navegador — banco aislado, no endpoint autenticado real

Componente real compilado `CommercialDocumentActions` + generación real de
Laravel/Dompdf, servidos por middleware local de fixtures. Sin SQL ni sesión
backend real. Tres observaciones alternadas por modo. No se usa esta tabla para
atribuir rendimiento a Sanctum, a MySQL o al puente de Hostinger.

| Caso | HTTP completo antes → después (ms) |
|---|---:|
| Cotización 1p | 510,9 → 484,0 |
| Orden 1p | 510,9 → 483,2 |
| Cotización 4p | 665,6 → 660,3 |
| Orden 4p | 869,2 → 753,6 |

Incluye arranque de un proceso PHP por solicitud: no es el modelo PHP-FPM de
producción. Medianas pequeñas sujetas a carga local; no prometen esa mejora en red.
Leer cuerpo/Blob tardó 0,6–1,8 ms y crear Object URL 0,5–1,4 ms. No se modificó
frontend porque no se confirmó allí un cuello de botella.

## Implementación y seguridad

- `CommercialDocumentPdf.php`: sigue generando cada PDF bajo demanda, con igual
  diseño, opciones de seguridad y limpieza privada. Método protegido de creación
  del motor para probar fallos sin cambiar la firma pública.
- `CommercialPdfFontCache.php`: solo guarda dos JSON públicos de métricas DejaVu,
  un lock y un marcador; carpeta privada 0700, fuera de `storage/app/public`.
- Namespace derivado de versión Dompdf + SHA-256 de ambos UFM. Un cambio de
  fuentes/dependencia no reutiliza métricas obsoletas.
- Lock de calentamiento: no se leen archivos a medio escribir. Solo solicitudes
  iniciales esperan; caché caliente no serializa las descargas.
- Si no hay almacenamiento disponible, vuelve al directorio temporal previo.
- No guarda PDF, HTML, datos de cliente, respuestas, subconjuntos de fuentes
  derivados del texto del cliente ni importes. No existe caché de documentos.
- No se tocaron controladores, rutas, permisos, modelos, cálculos, Blade, logo,
  Wompi, inventario, SMTP o el mecanismo de descarga.

## Pruebas y evidencia

- Backend Unit: **102 PASS, 375 aserciones**.
- Presentación + caché + sonda: 13 casos dentro de esa suite.
- Frontend documental: **5/5 PASS**; sin cambios frontend.
- 24 descargas con componente real (4 casos × 2 modos × 3), una petición por clic;
  nombre correcto, PDF válido y botón recuperado; errores 403/500 mostrados.
- Ocho generaciones simultáneas con caché inicialmente fría: todas correctas,
  sin errores; solo persisten los cuatro archivos tipográficos esperados.
- **10 páginas idénticas píxel a píxel** al rasterizar antes/después a 1200 px;
  texto extraído idéntico, importes y tamaños de los cuatro PDF iguales.
- Excepción controlada del motor: limpia el temporal, libera lock y no marca
  caché incompleta como lista. Almacenamiento no disponible vuelve al modo previo.
- Sonda comprobada por STDIN contra renderer anterior y nuevo, sin modificar
  el código del renderer anterior para ejecutar la prueba.
- Pint, PHP lint y `git diff --check`: PASS.
- Pruebas Feature con DB / HTTP autenticado real: pendientes por MySQL detenido;
  no se hicieron escrituras ni se eliminaron datos QA.

Evidencia numérica local, ignorada por Git: `qa-artifacts/pdf-performance/`.
Rasterizados y banco de prueba: `/tmp/upgrade79-pdf-perf-xhwIf1/`.
Los cambios preexistentes en comisiones y preflight se conservaron.

## Completar medición real local / Hostinger — no ejecutado en producción

`ops/profile-commercial-pdf.php` es exclusivamente CLI. En modo real abre una
transacción MySQL **READ ONLY**, reutiliza el controlador con permisos/propiedad
reales y termina con rollback. La copia instrumentada del renderer solo existe
en memoria. No imprime SQL, identificadores de clientes, credenciales ni PDF.
Solo crea temporales privados de Blade/fuentes, que elimina al terminar.

Desde el backend, usar IDs existentes y un usuario activo autorizado:

```bash
php ops/profile-commercial-pdf.php --type=quotation --id=123 --user=456 --scope=admin --repeat=2
php ops/profile-commercial-pdf.php --type=order --id=789 --user=456 --scope=admin --repeat=2
```

`--scope=own` aplica los permisos propios y la propiedad del empleado. No usar
IDs de ejemplo sin sustituirlos. No genera ventas ni pagos.

Hostinger, sin instalar el script, desde la terminal local de `backendupgrade`:

```bash
ssh -p 65002 USUARIO@HOST 'cd "$HOME/domains/crmupgradecol.com/laravel" && php -- --type=quotation --id=123 --user=456 --scope=admin --repeat=2' < ops/profile-commercial-pdf.php
```

Repetir para una orden y registros largos. Compartir únicamente el JSON. No
compartir cookies, `.env`, credenciales, documentos ni salidas SQL. No ejecutar
migraciones ni cambiar configuración. Un renderer desconocido rechaza la
instrumentación; la sonda no debe modificarse para ignorar ese rechazo.

La sonda distingue conexión SQL, carga del actor, permisos/carga del registro,
tiempo SQL/conteo, preparación (incluye SQL), Blade, Dompdf, tamaño y páginas.
`controller_ms` no incluye autenticación HTTP, red ni puente: no debe presentarse
como tiempo de respuesta HTTP. Dos iteraciones CLI tampoco equivalen a dos
procesos FPM independientes; sirven para diagnosticar frío/caliente.

Para medir HTTP real, desde la consola del navegador ya autenticado en el CRM:

```javascript
async function medirPdf(path) {
  const url = new URL(path, location.origin);
  if (url.origin !== location.origin || url.search || url.hash ||
      !/^\/api\/(?:admin\/(?:quotations|orders)|my\/(?:quotations|sales))\/\d+\/pdf$/.test(url.pathname)) {
    throw new Error('Usa únicamente una ruta PDF del mismo CRM');
  }
  const start = performance.now();
  const response = await fetch(url, {
    credentials: 'include', cache: 'no-store',
    headers: { Accept: 'application/pdf, application/json' },
    signal: AbortSignal.timeout(30000),
  });
  const headersAt = performance.now();
  const body = await response.arrayBuffer();
  const end = performance.now();
  return {
    status: response.status,
    headers_ms: +(headersAt - start).toFixed(1),
    body_ms: +(end - headersAt).toFixed(1),
    total_ms: +(end - start).toFixed(1),
    bytes: body.byteLength,
    content_type: response.headers.get('Content-Type'),
  };
}
// Sustituir ID y elegir el prefijo permitido para la sesión actual:
console.log(await medirPdf('/api/admin/quotations/123/pdf'));
```

El código no descarga ni publica el documento y no muestra su contenido. Hace
el GET autorizado habitual. Evitar ráfagas: se mantiene el throttle existente.

## Qué falta concluir en Hostinger

No está probado un defecto del puente ni un límite concreto de Hostinger. Faltan
SQL real, middleware/sesión, TTFB y transferencia. Comparar PHP CLI con PHP web,
permisos de `storage/framework/cache` para el usuario web y carga del hosting;
no trasladar automáticamente los números locales al servidor. La sonda informa
versiones, hash/tamaño del logo y metadatos no secretos para contrastar releases.
Si el almacenamiento web no permite la caché privada, la descarga conserva el
modo temporal previo, sin perder funcionalidad.

Sin cambios de producción, base de datos, dependencias, commit, push o deploy.
