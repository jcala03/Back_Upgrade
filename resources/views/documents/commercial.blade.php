<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>{{ $document['title'] }} {{ $document['code'] }}</title>
<style>
@page { margin: 38px 40px 56px; }
body { margin: 0; background: #ffffff; font-family: "DejaVu Sans", sans-serif; color: #222222; font-size: 10px; line-height: 1.35; }
table { width: 100%; border-collapse: collapse; }
.header { color: #111111; border-bottom: 1px solid #cccccc; }
.header td { padding: 8px 0 12px; vertical-align: middle; }
.header td + td { padding-left: 18px; }
.header div { color: #444444; font-size: 8.2px; line-height: 1.3; }
.logo { display: block; width: 96px; height: 96px; object-fit: contain; }
h1 { font-size: 23px; line-height: 1.25; font-weight: bold; margin: 0 0 8px; color: #111111; }
h2 { font-size: 10px; font-weight: bold; margin: 14px 0 7px; color: #333333; }
.document-code { font-size: 13px; margin-right: 7px; color: #111111; }
p { margin: 3px 0; }
.muted { color: #555555; font-size: 8.5px; }
.columns { margin-top: 13px; border-bottom: 1px solid #dddddd; }
.columns td { vertical-align: top; width: 50%; padding: 11px 0; word-wrap: break-word; }
.columns td + td { padding-left: 22px; }
.columns h2 { margin: 0 0 6px; }
.columns p + h2 { margin-top: 12px; }
.items { table-layout: fixed; margin-top: 16px; }
.items thead { display: table-header-group; }
.items th { color: #333333; border-top: 1px solid #dddddd; border-bottom: 1px solid #aaaaaa; padding: 8px 7px; text-align: left; font-size: 8.2px; font-weight: bold; }
.items td { border-bottom: 1px solid #dddddd; padding: 9px 7px; vertical-align: top; font-size: 9px; overflow-wrap: break-word; word-wrap: break-word; }
.items td:first-child strong { font-size: 9.5px; color: #222222; }
.items th:nth-child(2), .items td:nth-child(2) { text-align: center; }
.items tr { page-break-inside: avoid; }
.right { text-align: right !important; white-space: nowrap; }
.totals { width: 50%; margin: 18px 0 0 auto; page-break-inside: avoid; }
.totals td { padding: 5px 9px; border-bottom: 1px solid #dddddd; font-size: 9px; }
.totals .total { color: #111111; font-weight: bold; }
.totals .total td { font-size: 13px; padding-top: 9px; padding-bottom: 9px; border-top: 1px solid #aaaaaa; border-bottom: 1px solid #cccccc; }
.notice { margin-top: 16px; padding: 10px 0 0; border-top: 1px solid #cccccc; color: #333333; font-size: 9px; line-height: 1.5; page-break-inside: avoid; }
.notice + .muted { margin-top: 10px; font-size: 8px; line-height: 1.6; }
.notes { white-space: pre-line; word-wrap: break-word; line-height: 1.65; }
</style></head>
<body>
<table class="header"><tr><td style="width: 120px"><img class="logo" src="{{ $logo }}" alt="Upgrade La 79" width="150" height="150"></td><td>
<strong style="font-size: 16px">{{ $document['business']['business_name'] }}</strong>
@foreach (['legal_name', 'tax_id', 'address', 'city', 'phone', 'email'] as $field)
@if (!empty($document['business'][$field]))<div>{{ $field === 'tax_id' ? 'NIT: ' : '' }}{{ $document['business'][$field] }}</div>@endif
@endforeach
</td></tr></table>
<h1 style="margin-top: 22px">{{ $document['title'] }}</h1>
<strong class="document-code">{{ $document['code'] }}</strong> <span class="muted">Referencia del sistema: {{ $document['number'] }}</span>
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
