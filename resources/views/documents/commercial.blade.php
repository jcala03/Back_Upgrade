<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>{{ $document['title'] }} {{ $document['code'] }}</title>
<style>
@page { margin: 36px 36px 48px; }
body { font-family: "DejaVu Sans", sans-serif; color: #202c38; font-size: 10px; line-height: 1.5; }
table { width: 100%; border-collapse: collapse; }
.header { background: #0c1722; color: #fff; }
.header td { padding: 16px; vertical-align: middle; }
.logo { width: 116px; }
h1 { font-size: 19px; margin: 0 0 5px; color: #117da7; }
h2 { font-size: 11px; margin: 18px 0 8px; color: #117da7; }
p { margin: 4px 0; }
.muted { color: #607183; font-size: 9px; }
.columns td { vertical-align: top; width: 50%; padding: 12px 12px 12px 0; }
.items { table-layout: fixed; margin-top: 18px; }
.items thead { display: table-header-group; }
.items th { background: #e9f1f6; color: #233e50; padding: 9px 6px; text-align: left; font-size: 9px; }
.items td { border-bottom: 1px solid #dce4eb; padding: 10px 6px; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
.items tr { page-break-inside: avoid; }
.right { text-align: right !important; }
.totals { width: 48%; margin: 18px 0 0 auto; page-break-inside: avoid; }
.totals td { padding: 5px; }
.total { background: #e9f1f6; font-size: 13px; font-weight: bold; }
.notice { margin-top: 22px; padding: 12px; background: #f2f5f7; border-left: 3px solid #117da7; }
.notes { white-space: pre-line; word-wrap: break-word; }
</style></head>
<body>
<table class="header"><tr><td style="width: 140px"><img class="logo" src="{{ $logo }}" alt="Upgrade La 79"></td><td>
<strong style="font-size: 15px">{{ $document['business']['business_name'] }}</strong>
@foreach (['legal_name', 'tax_id', 'address', 'city', 'phone', 'email'] as $field)
@if (!empty($document['business'][$field]))<div>{{ $field === 'tax_id' ? 'NIT: ' : '' }}{{ $document['business'][$field] }}</div>@endif
@endforeach
</td></tr></table>
<h1 style="margin-top: 22px">{{ $document['title'] }}</h1>
<strong>{{ $document['code'] }}</strong> <span class="muted">Referencia del sistema: {{ $document['number'] }}</span>
<p>Fecha: {{ $document['created_at'] }} · Estado: {{ $document['status'] }}</p>
@if ($document['payment_status'])<p>Estado de pago: {{ $document['payment_status'] }}</p>@endif
@if ($document['valid_until'])<p>Vigencia: {{ $document['valid_until'] }}</p>@endif
<table class="columns"><tr><td><h2>Cliente</h2><strong>{{ $document['customer']['name'] }}</strong>
@foreach (['email', 'phone', 'city'] as $field)
@if (!empty($document['customer'][$field]))<div>{{ $document['customer'][$field] }}</div>@endif
@endforeach
</td><td>
@if ($document['branch'])<h2>Sede de la operación</h2><p>{{ $document['branch'] }}</p>@endif
@if ($document['vehicle'])<h2>Vehículo registrado</h2><p>{{ $document['vehicle'] }}</p>@endif
</td></tr></table>
<table class="items"><thead><tr>
<th style="width: 43%">Producto / servicio</th><th style="width: 7%">Cant.</th><th style="width: 18%" class="right">Precio unitario</th><th style="width: 14%" class="right">Descuento</th><th style="width: 18%" class="right">Total</th>
</tr></thead><tbody>
@foreach ($document['items'] as $item)
<tr><td><strong>{{ $item['name'] }}</strong>
@if ($item['variant'])<div>{{ $item['variant'] }}</div>@endif
@if ($item['sku'])<div class="muted">SKU: {{ $item['sku'] }}</div>@endif
@foreach ($item['configuration'] as $line)<div>{{ $line }}</div>@endforeach
</td><td>{{ $item['quantity'] }}</td>
<td class="right">{{ \App\Services\CommercialDocumentData::money($item['unit_price'], $document['currency']) }}</td>
<td class="right">{{ \App\Services\CommercialDocumentData::money($item['discount'], $document['currency']) }}</td>
<td class="right">{{ \App\Services\CommercialDocumentData::money($item['total'], $document['currency']) }}</td></tr>
@endforeach
</tbody></table>
<table class="totals">
<tr><td>Subtotal</td><td class="right">{{ \App\Services\CommercialDocumentData::money($document['subtotal'], $document['currency']) }}</td></tr>
<tr><td>Descuentos</td><td class="right">{{ \App\Services\CommercialDocumentData::money($document['discount_total'], $document['currency']) }}</td></tr>
@foreach ($document['charges'] as $charge)<tr><td>{{ $charge['label'] }}</td><td class="right">{{ \App\Services\CommercialDocumentData::money($charge['amount'], $document['currency']) }}</td></tr>@endforeach
@if ($document['charges_total'] && !$document['charges'])<tr><td>Cargos</td><td class="right">{{ \App\Services\CommercialDocumentData::money($document['charges_total'], $document['currency']) }}</td></tr>@endif
<tr class="total"><td>Total {{ $document['currency'] }}</td><td class="right">{{ \App\Services\CommercialDocumentData::money($document['total'], $document['currency']) }}</td></tr>
@if ($document['paid'] !== null)
<tr><td>Abonos completados</td><td class="right">{{ \App\Services\CommercialDocumentData::money($document['paid'], $document['currency']) }}</td></tr>
<tr><td>Saldo</td><td class="right">{{ \App\Services\CommercialDocumentData::money($document['balance'], $document['currency']) }}</td></tr>
@endif
</table>
@if ($document['notes'])<h2>Observaciones de la cotización</h2><p class="notes">{{ $document['notes'] }}</p>@endif
<p class="notice">{{ $document['notice'] }}</p>
<p class="muted">Los productos, referencias e importes corresponden al registro comercial guardado. No se sustituyen por el catálogo actual. Datos de contacto del negocio vigentes al generar el documento.</p>
</body></html>
