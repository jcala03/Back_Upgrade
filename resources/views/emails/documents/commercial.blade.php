<!doctype html><html lang="es"><head><meta charset="utf-8"></head>
<body style="font-family: sans-serif; color: #243746; line-height: 1.6">
<h1 style="font-size: 22px; color: #117da7">{{ $document['business']['business_name'] }}</h1>
<p>Hola, {{ $document['customer']['name'] }}:</p>
<p>Adjuntamos {{ $document['title'] }} <strong>{{ $document['code'] }}</strong>, referencia {{ $document['number'] }}, con el detalle de productos o servicios e importes registrados.</p>
<p>{{ $document['notice'] }}</p>
<p>Si necesitas aclaraciones, comunícate con nuestro equipo por los canales habituales.</p>
<p>Gracias por contar con {{ $document['business']['business_name'] }}.</p>
</body></html>
