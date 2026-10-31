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
- Workflows: YAML y scripts Bash válidos; solo workflow_dispatch, SHA exacto aprobado,
  respaldo privado verificado y migración única por casilla explícita. Se probaron
  rechazo de SHA inválido/distinto y backup_id inválido sin operaciones remotas.
- Staging excluye .env privados, copias, dumps, qa-artifacts y dist local. Se revisó
  contra secretos activos locales sin imprimirlos. MAIL_PASSWORD=null en .env.example
  es un marcador vacío de Laravel, no una credencial.

Pendiente productivo: respaldo/restauración real, autorización CORS y .env, comparación
efectiva de las 87 migraciones con el release (no basta contar), migración única,
publicación y smoke tests. No hubo conexión a Hostinger, push, pagos ni deploy.
Wompi seguirá sin iniciar compras hasta rotación/activación separadas; SMTP real y
compra configurable permanecen desactivados por la política de este release.
