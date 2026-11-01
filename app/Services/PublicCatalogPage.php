<?php

namespace App\Services;

use Illuminate\Http\Response;
use RuntimeException;

class PublicCatalogPage
{
    public function product(array $product, string $query = ''): Response
    {
        $path = '/tienda/'.rawurlencode($product['slug']);
        if ($query === '' && ($snapshot = $this->snapshot($path, $product)) !== null) {
            return $this->response($snapshot, 200, 'prerendered');
        }
        $seo = $this->seo($product);
        $bootstrap = ['path' => $path, 'query' => $query, 'product' => $product, 'seo' => $seo, 'renderMode' => 'client'];
        $head = view('public-catalog.head', compact('seo'))->render();
        $content = view('public-catalog.product', compact('product', 'seo'))->render();
        $json = json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $html = str_replace(['<!--seo-head-->', '<div id="root"></div>'], [
            $head, '<div id="root" data-public-status="200">'.$content.'</div><script id="public-bootstrap" type="application/json">'.$json.'</script>',
        ], $this->template());

        return $this->response($html, 200, 'dynamic');
    }

    public function error(int $status): Response
    {
        $title = $status === 503 ? 'Temporalmente no disponible' : 'Página no encontrada';
        $head = '<title>'.$title.' | UP GRADE 79</title><meta name="robots" content="noindex, nofollow">';
        $content = '<main><h1>'.$title.'</h1><p>Consulta los artículos disponibles en nuestra tienda.</p><a href="/tienda">Volver a la tienda</a></main>';
        try {
            $template = $this->template();
        } catch (RuntimeException) {
            $status = 503;
            $head = '<title>Temporalmente no disponible | UP GRADE 79</title><meta name="robots" content="noindex, nofollow">';
            $content = '<main><h1>Temporalmente no disponible</h1></main>';
            $template = '<!doctype html><html lang="es"><head><!--seo-head--></head><body><div id="root"></div></body></html>';
        }
        $html = str_replace(['<!--seo-head-->', '<div id="root"></div>'], [$head, '<div id="root" data-public-status="'.$status.'">'.$content.'</div>'], $template);

        return $this->response($html, $status, 'dynamic');
    }

    public function sitemap(array $products): Response
    {
        $paths = ['/', '/tienda', '/politica-de-datos'];
        foreach ($products as $product) {
            if ($product['is_active'] && $product['is_visible'] && $this->images($product)) {
                $paths[] = '/tienda/'.rawurlencode($product['slug']);
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_unique($paths) as $path) {
            $xml .= '<url><loc>'.htmlspecialchars($this->origin().$path, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function origin(): string
    {
        return rtrim(config('public_catalog.site_url'), '/');
    }

    private function template(): string
    {
        $file = config('public_catalog.frontend_path').'/seo/template.html';
        $template = is_file($file) ? file_get_contents($file) : false;
        if ($template === false || ! str_contains($template, '<!--seo-head-->') || ! str_contains($template, '<div id="root"></div>')) {
            throw new RuntimeException('Frontend SEO template unavailable; publish the matching frontend build.');
        }

        return $template;
    }

    private function snapshot(string $path, array $product): ?string
    {
        $directory = config('public_catalog.frontend_path');
        $manifest = $directory.'/seo/routes.json';
        $routes = is_file($manifest) ? json_decode(file_get_contents($manifest), true) : null;
        if (($routes['siteUrl'] ?? null) !== $this->origin()) {
            return null;
        }
        foreach ($routes['pages'] ?? [] as $page) {
            if (($page['path'] ?? null) !== $path || ! preg_match('~^seo/pages/[a-f0-9]{24}\.html$~', $page['file'] ?? '')) {
                continue;
            }
            $file = $directory.'/'.$page['file'];
            $html = is_file($file) ? file_get_contents($file) : false;
            if ($html && preg_match('~<script id="public-bootstrap" type="application/json">(.*?)</script>~s', $html, $match)) {
                $data = json_decode($match[1], true);
                if (($data['path'] ?? null) === $path && ($data['product'] ?? null) === $product
                    && ($data['seo']['canonical'] ?? null) === $this->origin().$path
                    && str_contains($html, 'data-public-status="200"')) {
                    return $html;
                }
            }
        }

        return null;
    }

    private function response(string $html, int $status, string $mode): Response
    {
        return response($html, $status, array_filter([
            'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer',
            'X-Public-Catalog' => $mode, 'X-Robots-Tag' => $status >= 400 ? 'noindex, nofollow' : null,
            'Retry-After' => $status === 503 ? '60' : null,
        ]));
    }

    private function images(array $product): array
    {
        $gallery = $product['images'] ?? [];
        $images = [$product['image_url'] ?? null];
        foreach ([true, false] as $primary) {
            foreach ($gallery as $image) {
                if ((bool) $image['is_primary'] === $primary) {
                    $images[] = $image['image_url'];
                }
            }
        }

        return array_values(array_unique(array_filter($images, fn ($url) => is_string($url) && preg_match('~^https?://~', $url))));
    }

    private function seo(array $product): array
    {
        $canonical = $this->origin().'/tienda/'.rawurlencode($product['slug']);
        $description = trim(preg_replace('/\s+/u', ' ', preg_replace('/<[^>]*>/', ' ', $product['description'] ?? '')));
        $description = $description ?: implode('. ', array_filter([$product['name'], $product['product_category']['name'] ?? $product['category'] ?? null, $product['product_brand']['name'] ?? null, ! empty($product['is_universal']) ? 'Compatibilidad universal' : null]));
        $images = $this->images($product);
        $variants = array_values(array_filter($product['variants'] ?? [], fn ($variant) => $variant['is_active'] && $variant['is_visible']));
        $sources = $variants ?: (! empty($product['has_variants']) ? [] : [$product]);
        $offers = [];
        foreach ($sources as $source) {
            if (is_numeric($source['price'] ?? null) && $source['price'] > 0) {
                $offer = ['@type' => 'Offer', 'url' => $canonical, 'priceCurrency' => 'COP', 'price' => 0 + $source['price'], 'availability' => 'https://schema.org/'.(($source['stock'] ?? 0) > 0 ? 'InStock' : 'OutOfStock')];
                if ($source !== $product) {
                    $offer['name'] = $source['name'];
                }
                $offers[] = $offer;
            }
        }
        $schema = ['@context' => 'https://schema.org', '@type' => 'Product', '@id' => $canonical.'#product', 'name' => $product['name'], 'url' => $canonical, 'description' => $description, 'image' => $images];
        if ($product['sku'] ?? null) {
            $schema['sku'] = $product['sku'];
        }
        if ($product['product_brand']['name'] ?? null) {
            $schema['brand'] = ['@type' => 'Brand', 'name' => $product['product_brand']['name']];
        }
        if ($offers) {
            $schema['offers'] = count($offers) === 1 ? $offers[0] : $offers;
        }
        $breadcrumbs = [];
        foreach ([['Inicio', '/'], ['Tienda', '/tienda'], [$product['name'], '/tienda/'.rawurlencode($product['slug'])]] as $index => [$name, $path]) {
            $breadcrumbs[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $name, 'item' => $this->origin().$path];
        }

        return ['title' => $product['name'].' | UP GRADE 79', 'description' => mb_substr($description, 0, 170), 'canonical' => $canonical, 'robots' => $images ? 'index, follow' : 'noindex, follow', 'image' => $images[0] ?? null, 'type' => 'product', 'structuredData' => [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $breadcrumbs], $schema]];
    }
}
