# Validación local del release — 2026-10-30

- Backend funcional: 73 PASS, 636 aserciones. Comisiones, permisos propios/admin,
  documentos PDF/correo simulado, compatibilidad/configurador, firmas e importes.
- Política nueva de migraciones: 11 PASS, 14 aserciones; rechaza pendientes distintas,
  historial huérfano, columna incompatible y actualización sin autorización.
- Total de esas ejecuciones backend: 84 PASS, 650 aserciones.
- MySQL efectivo verificado antes de las pruebas: testing, upgrade_test, loopback,
  XAMPP /opt/lampp. Nueve tablas persistentes mantienen los mismos hashes después.
- No se ejecutó la suite histórica completa: las clases RefreshDatabase podrían
  vaciar QA persistente. No afirmar cobertura de toda esa suite en este cierre.
- Frontend: 179/179 PASS. Avisos HMR por puerto 24678 ocupado; ningún test falló.
- TypeScript, cliente y SSR: PASS. Build fuera del dist existente, en /tmp.
- Prerender: PASS, 10 páginas con catálogo local de solo lectura. El código compilado
  usa API/canónico públicos. CI reconstruirá snapshots con el catálogo productivo.
- public/sw.js no modificado y coincide con el artefacto construido, SHA-256:
  43338314702133f01e4df46edf0e9640229a0da55f593d0773104e6bcbfa3482.
- Pint y sintaxis PHP/Bash: PASS. Composer audit: sin advisories.
- Preparación inicial: se validaron workflows manuales, SHA exacto y backup_id.
  Esta política se sustituyó posteriormente por push automático + workflow_dispatch;
  consultar RELEASE_HOSTINGER.md y la validación adicional al final de este documento.
- Staging excluye .env privados, copias, dumps, qa-artifacts y dist local. Se revisó
  contra secretos activos locales sin imprimirlos. MAIL_PASSWORD=null en .env.example
  es un marcador vacío de Laravel, no una credencial.

Pendiente productivo: respaldo/restauración real, autorización CORS y .env, comparación
efectiva de las 87 migraciones con el release (no basta contar), migración única,
publicación y smoke tests. No hubo conexión a Hostinger, push, pagos ni deploy.
Wompi seguirá sin iniciar compras hasta rotación/activación separadas; SMTP real y
compra configurable permanecen desactivados por la política de este release.

## Corrección posterior: despliegue automático

- push a main restaurado en ambos repositorios; workflow_dispatch adicional.
- Jobs ordinarios sin aprobación manual ni backup_id obligatorio. Solo la operación
  excepcional de migración tiene entorno protegido y exige backup/confirmación manual.
- SHA del evento, validaciones CI previas, plataforma y esquema del candidato comprobados
  ANTES de checkout/Composer/mantenimiento. Cero comandos migrate en deploy ordinario.
- Nueva migración identificada también por SHA-256; desconocidas, cambios históricos,
  historial discrepante o esquema incompleto bloquean la publicación.
- Respaldos privados automáticos de código, cola GitHub y flock común entre repositorios.
  Frontend se sube a staging privado, comprueba hashes y copia assets antes de index.
- PHP unit tests: 43 PASS, 80 aserciones; sin base de datos ni servicios externos.
- Regresión de workflows: 11 PASS con comandos falsos/directorios temporales aislados.
  Casos positivos backend/frontend y fallos previos sin tocar la versión instalada;
  puente, reglas y storage preservados. No se simulan escrituras en una base real.
- Frontend: 179/179 PASS; TypeScript PASS. Sin cambios a módulos ni al build/PWA.
  Se conservan build cliente/SSR/prerender y los controles postpublicación existentes.
- YAML, scripts Bash, lint PHP, Pint y composer validate: PASS. git diff --check PASS
  para estos cambios; el dist antiguo del usuario sigue fuera de los commits.
- No se accedió a Hostinger ni se ejecutaron push, deploy o migraciones. La ejecución
  real en GitHub/Hostinger queda pendiente. Revisar secretos disponibles al job ordinario,
  configurar revisores solo en production-migrations y comprobar flock en el hosting.
