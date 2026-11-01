<main class="product-detail">
    <a href="/tienda" class="product-detail__back">Volver a la tienda</a>
    <div class="product-detail__grid">
        <section class="product-detail__gallery" aria-label="Imágenes del producto">
            @if ($seo['image'])
                <img src="{{ $seo['image'] }}" alt="{{ $product['name'] }}" style="width:100%;max-height:540px;object-fit:contain">
            @endif
        </section>
        <section class="product-detail__info">
            <span>{{ $product['product_category']['name'] ?? $product['category'] ?? '' }}</span>
            <h1>{{ $product['name'] }}</h1>
            <p>{{ strip_tags($product['description'] ?? '') }}</p>
            @if ($product['sku'])<p>SKU: {{ $product['sku'] }}</p>@endif
            <p class="product-detail__price">{{ $product['has_variants'] ? 'Desde ' : '' }}${{ number_format($product['has_variants'] ? ($product['lowest_variant_price'] ?? $product['price']) : $product['price'], 0, ',', '.') }} COP</p>
            <p>{{ $product['stock'] > 0 ? 'Disponible en nuestra red · surtido por confirmar' : 'Producto agotado' }}</p>
            @foreach ($product['vehicle_compatibilities'] ?? [] as $compatibility)
                <p>{{ $compatibility['vehicle_label'] ?? '' }}</p>
            @endforeach
            <noscript>Activa JavaScript para seleccionar opciones y agregar al carrito.</noscript>
        </section>
    </div>
</main>
