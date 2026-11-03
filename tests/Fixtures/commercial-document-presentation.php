<?php

// Synthetic presentation-only DTOs: no models, database records or real contacts.
$quotation = [
    'id' => 125, 'kind' => 'quotation', 'code' => 'COT-000125', 'filename' => 'COT-000125.pdf',
    'number' => 'QA-VISUAL-125', 'title' => 'Cotización', 'status' => 'Borrador',
    'payment_status' => null, 'created_at' => '03/11/2026 10:30', 'valid_until' => '18/11/2026',
    'business' => [
        'business_name' => 'Upgrade La 79', 'legal_name' => 'Datos comerciales de muestra · QA LOCAL',
        'tax_id' => 'QA-NO-VALIDO', 'address' => 'Dirección de muestra (QA)', 'city' => 'Barranquilla · Colombia',
        'phone' => '000 000 0000', 'email' => 'ventas@example.test',
    ],
    'branch' => 'Sede de muestra · QA LOCAL',
    'customer' => ['name' => 'Cliente de muestra · QA LOCAL', 'email' => 'cliente@example.test', 'phone' => '000 000 0000', 'city' => 'Ciudad de muestra'],
    'vehicle' => 'Vehículo de muestra · Generación QA · 2015 · QA000',
    'items' => [
        ['name' => 'Pantalla Android 8 GB / 256 GB · Referencia QA', 'variant' => 'Kit OEM QA · 8 GB / 256 GB · QA-PANTALLA-8256',
            'sku' => 'QA-PANTALLA-8256', 'configuration' => ['Vehículo: vehículo de muestra · Generación QA', 'Sistema OEM: OEM de muestra', 'RAM: 8 GB', 'Almacenamiento: 256 GB'],
            'quantity' => 1, 'unit_price' => 1200000, 'discount' => 20000, 'total' => 1180000],
        ['name' => 'Cámara de reversa · Referencia QA', 'variant' => 'Kit de instalación QA', 'sku' => 'QA-CAMARA-001', 'configuration' => [],
            'quantity' => 2, 'unit_price' => 180000, 'discount' => 10000, 'total' => 350000],
        ['name' => 'Instalación y configuración · Servicio QA', 'variant' => null, 'sku' => null, 'configuration' => [],
            'quantity' => 1, 'unit_price' => 150000, 'discount' => 0, 'total' => 150000],
    ],
    'subtotal' => 1710000, 'discount_total' => 30000, 'charges_total' => 0, 'charges' => [], 'total' => 1680000,
    'paid' => null, 'balance' => null, 'currency' => 'COP',
    'notes' => "Documento de muestra para revisión visual. No corresponde a una operación real.\nLas características repetidas se conservan deliberadamente.",
    'notice' => 'Cotización comercial. No acredita pago ni constituye una reserva de inventario.',
];
$order = array_replace($quotation, [
    'id' => 411, 'kind' => 'pending_order', 'code' => 'ORDEN-000411', 'filename' => 'ORDEN-000411.pdf',
    'number' => 'QA-VISUAL-411', 'title' => 'Orden pendiente', 'status' => 'Pendiente', 'payment_status' => 'Sin pagar',
    'valid_until' => null, 'notes' => null, 'charges_total' => 25000, 'charges' => [['label' => 'Transporte de muestra QA', 'amount' => 25000]],
    'total' => 1705000, 'paid' => 0, 'balance' => 1705000,
    'notice' => 'Este documento no acredita una venta pagada. Consulta los estados y abonos registrados. No es factura electrónica DIAN.',
]);
$sale = array_replace($order, [
    'kind' => 'paid_sale', 'code' => 'VENTA-000411', 'filename' => 'VENTA-000411.pdf',
    'title' => 'Comprobante de venta pagada', 'status' => 'Completada', 'payment_status' => 'Pagada', 'paid' => 1705000, 'balance' => 0,
    'notice' => 'Comprobante comercial de pago registrado. No es factura electrónica DIAN.',
]);

return ['quotation' => $quotation, 'pending-order' => $order, 'paid-sale' => $sale];
