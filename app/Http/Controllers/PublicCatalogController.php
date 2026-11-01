<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\ProductController;
use App\Models\Product;
use App\Services\PublicCatalogPage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class PublicCatalogController extends Controller
{
    public function show(Request $request, string $slug, ProductController $products, PublicCatalogPage $page): Response
    {
        try {
            $product = Product::query()->where('slug', $slug)->where('is_active', true)->where('is_visible', true)->first();
            if (! $product) {
                return $page->error(404);
            }
            // Reuse exactly the public API resource, price, stock and OEM filters.
            // Never serialize an administrative model or forward visitor cookies.
            $query = [];
            foreach (['vehicle_brand_id', 'vehicle_model_id', 'vehicle_version_id', 'vehicle_multimedia_system_id', 'year'] as $key) {
                $value = $request->query()[$key] ?? null;
                if (is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0) {
                    $query[$key] = (int) $value;
                }
            }
            if (! isset($query['vehicle_brand_id'])) {
                unset($query['vehicle_model_id'], $query['vehicle_version_id'], $query['vehicle_multimedia_system_id']);
            }
            if (! isset($query['vehicle_model_id'])) {
                unset($query['vehicle_version_id'], $query['vehicle_multimedia_system_id']);
            }
            if (! isset($query['vehicle_version_id'])) {
                unset($query['vehicle_multimedia_system_id']);
            }
            $publicRequest = Request::create('/api/products/'.rawurlencode($slug), 'GET', $query);
            $data = $products->show($publicRequest, $product)->getData(true)['data'];

            return $page->product($data, http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        } catch (ModelNotFoundException|NotFoundHttpException) {
            return $page->error(404);
        } catch (Throwable $exception) {
            report($exception);

            return $page->error(503);
        }
    }

    public function sitemap(ProductController $products, PublicCatalogPage $page): Response
    {
        try {
            $data = $products->index(Request::create('/api/products', 'GET', ['in_stock' => 0]))->getData(true)['data'];

            return $page->sitemap($data);
        } catch (Throwable $exception) {
            report($exception);

            return response('Sitemap temporalmente no disponible.', 503, [
                'Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow', 'Retry-After' => '60',
            ]);
        }
    }
}
