@component('mail::message')
# Nueva orden recibida

Se ha creado una nueva orden en **{{ $business['business_name'] }}**.

@component('mail::panel')
**Número de orden:** {{ $order->order_number }}  
**Estado:** {{ $order->status }}  
**Pago:** {{ $order->payment_status }}  
**Total:** ${{ number_format((int) $order->total, 0, ',', '.') }} {{ $business['currency'] }}
@endcomponent

## Cliente

**Nombre:** {{ $order->customer_name }}  
**Correo:** {{ $order->customer_email }}  
**Celular:** {{ $order->customer_phone }}  
**Ciudad:** {{ $order->customer_city ?: 'No especificada' }}  
**Dirección:** {{ $order->customer_address ?: 'No especificada' }}

@if ($order->customer_notes)
**Notas del cliente:**  
{{ $order->customer_notes }}
@endif

## Productos solicitados

@component('mail::table')
| Producto | Cantidad | Precio unitario | Total |
|:---|:---:|---:|---:|
@foreach ($order->items as $item)
| {{ $item->product_name }} | {{ $item->quantity }} | ${{ number_format((int) $item->unit_price, 0, ',', '.') }} | ${{ number_format((int) $item->total, 0, ',', '.') }} |
@endforeach
@endcomponent

@component('mail::button', ['url' => 'https://wa.me/' . preg_replace('/\D+/', '', $order->customer_phone)])
Contactar cliente por WhatsApp
@endcomponent

Esta orden está pendiente de revisión, confirmación de disponibilidad y pago.

Gracias,  
**Sistema {{ $business['business_name'] }}**
@endcomponent
