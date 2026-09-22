# Wompi Phase 7B-3: expiración de reservas ecommerce

`ecommerce:expire-stock-reservations` busca únicamente órdenes ecommerce
`confirmed` con `stock_reservation_expires_at` vencido. Cada identificador se
revalida en su propia transacción.

El worker bloquea primero los `payments` de la orden, por `id`, y después la
orden. Es compatible con el orden financiero del webhook (`WebhookEvent →
Payment → Order`); el worker no posee un ledger que bloquear.

- Un crédito válido es un `Payment` `completed`, sin reconciliation, de la
  misma moneda, cuya suma cubre `Order.total`. La orden queda `confirmed/paid`
  y `stock_reservation_expires_at` se limpia. Nunca se amplía.
- Cualquier `reconciliation_required_at` impide la cancelación automática y no
  genera alertas ni otra reconciliation.
- Una reserva impaga se cancela mediante `OrderService`. Ese flujo existente
  registra la reversión de venta, fija `stock_reverted_at` y anula comisiones
  pendientes; por sus sentinelas, una segunda ejecución no vuelve a mover stock.
- Si el webhook APPROVED obtiene los locks primero, el worker observa el
  crédito y no cancela. Si expiry confirma la cancelación primero, el webhook
  conserva la orden cancelada y aplica la política existente de late approval
  con reconciliation; no recompromete inventario.

El scheduler registra el comando con `everyMinute()` y
`withoutOverlapping()`. Producción debe ejecutar el scheduler estándar de
Laravel; esta fase no instala ni configura un scheduler del sistema operativo.
