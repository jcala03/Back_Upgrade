<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicCatalogRuntimeTest extends TestCase
{
    use DatabaseTransactions;

    private string $frontend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('upgrade_test', DB::selectOne('SELECT DATABASE() AS db')->db);
        $this->frontend = sys_get_temp_dir().'/upgrade79-catalog-'.Str::uuid();
        mkdir($this->frontend.'/seo/pages', 0700, true);
        file_put_contents($this->frontend.'/seo/template.html', '<!doctype html><html lang="es"><head><!--seo-head--></head><body><div id="root"></div><script type="module" src="/assets/test.js"></script></body></html>');
        config(['public_catalog.frontend_path' => $this->frontend]);
    }

    protected function tearDown(): void
    {
        if (isset($this->frontend)) {
            foreach (glob($this->frontend.'/seo/pages/*') as $file) {
                unlink($file);
            }
            foreach (glob($this->frontend.'/seo/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->frontend.'/seo/pages');
            rmdir($this->frontend.'/seo');
            rmdir($this->frontend);
        }
        parent::tearDown();
    }

    private function product(array $overrides = []): Product
    {
        $tag = 'runtime-'.Str::uuid();

        return Product::create(array_merge([
            'name' => 'Producto sintético runtime '.$tag, 'slug' => $tag, 'sku' => $tag,
            'description' => 'Descripción de prueba <strong>segura</strong>', 'price' => 123456,
            'cost_price' => 54321, 'main_image' => 'products/synthetic-test.png',
            'compatibility_type' => Product::COMPATIBILITY_TYPE_UNIVERSAL,
            'is_visible' => true, 'is_active' => true,
        ], $overrides));
    }

    private function snapshot(Product $product): string
    {
        $data = $this->getJson('/api/products/'.$product->slug)->assertOk()->json('data');
        $path = '/tienda/'.$product->slug;
        $file = 'seo/pages/'.substr(hash('sha256', $path), 0, 24).'.html';
        $bootstrap = ['path' => $path, 'product' => $data, 'prerendered' => true,
            'seo' => ['canonical' => 'https://upgradecolombia.com'.$path]];
        file_put_contents($this->frontend.'/seo/routes.json', json_encode([
            'siteUrl' => 'https://upgradecolombia.com', 'pages' => [['path' => $path, 'file' => $file]],
        ]));
        $html = '<html><head><title>Snapshot probado</title></head><body><div id="root" data-public-status="200"><h1>Snapshot probado</h1></div><script id="public-bootstrap" type="application/json">'.json_encode($bootstrap).'</script></body></html>';
        file_put_contents($this->frontend.'/'.$file, $html);

        return $html;
    }

    public function test_product_published_after_build_has_real_html_seo_public_data_and_no_business_writes(): void
    {
        file_put_contents($this->frontend.'/seo/routes.json', json_encode(['siteUrl' => 'https://upgradecolombia.com', 'pages' => []]));
        $product = $this->product();
        $before = $product->fresh()->getAttributes();
        $stocks = DB::table('inventory_stocks')->count();
        $orders = DB::table('orders')->count();
        $response = $this->get('/tienda/'.$product->slug)->assertOk()
            ->assertHeader('X-Public-Catalog', 'dynamic')->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('<h1>'.$product->name.'</h1>', false)
            ->assertSee('rel="canonical" href="https://upgradecolombia.com/tienda/'.$product->slug.'"', false)
            ->assertSee('property="og:image"', false)->assertSee('application/ld+json', false)
            ->assertSee('"@type":"Product"', false)->assertSee('"priceCurrency":"COP"', false)
            ->assertSee('"renderMode":"client"', false)->assertSee('/assets/test.js', false)
            ->assertDontSee('cost_price', false)->assertDontSee('profit_amount', false);
        preg_match('~<script id="public-bootstrap" type="application/json">(.*?)</script>~s', $response->getContent(), $match);
        $bootstrap = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($this->getJson('/api/products/'.$product->slug)->json('data'), $bootstrap['product']);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame($stocks, DB::table('inventory_stocks')->count());
        $this->assertSame($orders, DB::table('orders')->count());
    }

    public function test_unchanged_build_snapshot_is_reused_but_price_edits_are_visible_immediately(): void
    {
        $product = $this->product();
        $html = $this->snapshot($product);
        $response = $this->get('/tienda/'.$product->slug)->assertOk()->assertHeader('X-Public-Catalog', 'prerendered');
        $this->assertSame($html, $response->getContent());
        $product->update(['price' => 456789]);
        $this->get('/tienda/'.$product->slug)->assertOk()->assertHeader('X-Public-Catalog', 'dynamic')
            ->assertSee('456.789', false)->assertSee('"price":456789', false)->assertDontSee('Snapshot probado');
    }

    public function test_hidden_inactive_and_missing_slugs_are_real_404_even_with_old_snapshot(): void
    {
        $product = $this->product();
        $this->snapshot($product);
        foreach ([['is_visible' => false], ['is_visible' => true, 'is_active' => false]] as $change) {
            $product->update($change);
            $this->get('/tienda/'.$product->slug)->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertDontSee($product->name)->assertDontSee('rel="canonical"', false)->assertDontSee('Snapshot probado');
        }
        $this->get('/tienda/not-a-real-product-'.Str::uuid())->assertNotFound();
    }

    public function test_sitemap_updates_after_create_hide_and_deactivate_without_rebuild(): void
    {
        $product = $this->product();
        $path = 'https://upgradecolombia.com/tienda/'.$product->slug;
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8')->assertSee($path, false);
        $new = $this->product();
        $this->get('/sitemap.xml')->assertOk()->assertSee('/tienda/'.$new->slug, false);
        $product->update(['is_visible' => false]);
        $new->update(['is_active' => false]);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee($path, false)->assertDontSee('/tienda/'.$new->slug, false)
            ->assertDontSee('/crm')->assertDontSee('/checkout');
    }

    public function test_untrusted_content_is_escaped_and_query_cannot_leak_private_data_or_redirect_canonical(): void
    {
        $product = $this->product(['name' => 'QA </script><script>alert(1)</script>', 'description' => 'Texto <img src=x onerror=alert(2)>']);
        $response = $this->get('/tienda/'.$product->slug.'?redirect=https://evil.test&token=private-test-value&year[]=2020')
            ->assertOk()->assertDontSee('private-test-value')->assertDontSee('evil.test')
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false);
        $this->assertStringContainsString('\\u003C/script\\u003E', $response->getContent());
        $this->get('/tienda/'.$product->slug.'?year=2020')->assertOk()->assertSee('"query":"year=2020"', false);
    }

    public function test_missing_frontend_template_returns_503_not_a_fake_404_or_success(): void
    {
        $product = $this->product();
        config(['public_catalog.frontend_path' => $this->frontend.'/missing']);
        $this->get('/tienda/'.$product->slug)->assertStatus(503)->assertHeader('Retry-After', '60')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        // Sitemap does not need build-time pages or the template.
        $this->get('/sitemap.xml')->assertOk()->assertSee('/tienda/'.$product->slug, false);
    }

    public function test_legacy_public_product_without_image_is_not_a_false_404_and_filtered_misses_are_not_503(): void
    {
        $product = $this->product(['main_image' => null]);
        $this->get('/tienda/'.$product->slug)->assertOk()->assertSee($product->name)
            ->assertSee('content="noindex, follow"', false);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/tienda/'.$product->slug, false);
        $product->update(['compatibility_type' => Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC]);
        $this->get('/tienda/'.$product->slug.'?year=2020')->assertNotFound();
    }

    public function test_variant_seo_uses_only_public_api_prices_and_never_private_variants_or_costs(): void
    {
        $product = $this->product();
        $product->variants()->create(['name' => 'Kit público', 'normalized_name' => 'kit publico', 'sku' => $product->sku.'-PUB', 'price' => 234567, 'cost_price' => 12345, 'is_active' => true, 'is_visible' => true, 'is_default' => true]);
        $product->variants()->create(['name' => 'PRIVATE-QA-KIT', 'normalized_name' => 'private-qa-kit', 'sku' => $product->sku.'-HID', 'price' => 1, 'is_active' => true, 'is_visible' => false]);
        $this->get('/tienda/'.$product->slug)->assertOk()->assertSee('Desde $234.567', false)
            ->assertSee('"price":234567', false)->assertSee('https://schema.org/OutOfStock', false)
            ->assertDontSee('PRIVATE-QA-KIT')->assertDontSee('cost_price');
    }

    public function test_public_html_routes_do_not_change_api_authentication_or_allow_writes(): void
    {
        $product = $this->product();
        $this->getJson('/api/products/'.$product->slug)->assertOk()->assertJsonPath('data.slug', $product->slug);
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->post('/tienda/'.$product->slug)->assertStatus(405);
        $this->post('/sitemap.xml')->assertStatus(405);
    }
}
