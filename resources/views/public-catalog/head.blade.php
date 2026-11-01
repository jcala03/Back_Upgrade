<title>{{ $seo['title'] }}</title>
<meta name="description" content="{{ $seo['description'] }}" data-public-seo>
<meta name="robots" content="{{ $seo['robots'] }}" data-public-seo>
<link rel="canonical" href="{{ $seo['canonical'] }}" data-public-seo>
@foreach (['title', 'description', 'type'] as $key)
<meta property="og:{{ $key }}" content="{{ $seo[$key] }}" data-public-seo>
@endforeach
<meta property="og:url" content="{{ $seo['canonical'] }}" data-public-seo>
<meta property="og:site_name" content="UP GRADE 79" data-public-seo>
<meta property="og:locale" content="es_CO" data-public-seo>
<meta name="twitter:card" content="{{ $seo['image'] ? 'summary_large_image' : 'summary' }}" data-public-seo>
<meta name="twitter:title" content="{{ $seo['title'] }}" data-public-seo>
<meta name="twitter:description" content="{{ $seo['description'] }}" data-public-seo>
@if ($seo['image'])
<meta property="og:image" content="{{ $seo['image'] }}" data-public-seo>
<meta property="og:image:alt" content="{{ $seo['title'] }}" data-public-seo>
<meta name="twitter:image" content="{{ $seo['image'] }}" data-public-seo>
<meta name="twitter:image:alt" content="{{ $seo['title'] }}" data-public-seo>
@endif
@foreach ($seo['structuredData'] as $schema)
<script type="application/ld+json" data-public-seo>{!! json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
@endforeach
