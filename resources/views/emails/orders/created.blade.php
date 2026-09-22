@component('mail::message')
# Orden creada correctamente

Hola {{ $order->customer_name }},

Gracias por comprar en **{{ $business['business_name'] }}**.  
Hemos recibido tu orden y ya quedó registrada en nuestro sistema.

@component('mail::panel')
**Número de orden:** {{ $order->order_number }}  
**Estado de la orden:** {{ $order->status }}  
**Estado del pago:** {{ $order->payment_status }}  
**Total:** ${{ number_format((int) $order->total, 0, ',', '.') }} {{ $business['currency'] }}
@endcomponent

## Resumen de productos

@component('mail::table')
| Producto | Cantidad | Precio unitario | Total |
|:---|:---:|---:|---:|
@foreach ($order->items as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | ${{ number_format((int) $item->unit_price, 0, ',', '.') }} | ${{ number_format((int) $item->total, 0, ',', '.') }} |
@endforeach
@endcomponent

## Datos de contacto

**Nombre:** {{ $order->customer_name }}  
**Correo:** {{ $order->customer_email }}  
**Celular:** {{ $order->customer_phone }}  
**Ciudad:** {{ $order->customer_city ?: 'No especificada' }}  
**Dirección:** {{ $order->customer_address ?: 'No especificada' }}

@if ($order->customer_notes)
**Notas:**  
{{ $order->customer_notes }}
@endif

@if ($business['whatsapp'])
@component('mail::button', ['url' => 'https://wa.me/' . preg_replace('/\D+/', '', $business['whatsapp']) . '?text=' . urlencode('Hola ' . $business['business_name'] . ', quiero consultar mi orden ' . $order->order_number)])
Contactar por WhatsApp
@endcomponent
@endif

Nuestro equipo revisará tu orden y te contactará para confirmar disponibilidad, instalación, entrega o el siguiente paso del pago.

Gracias,  
**{{ $business['business_name'] }}**
@endcomponent
