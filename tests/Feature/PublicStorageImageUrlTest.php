<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PublicCatalogPage;
use App\Support\PublicStorageUrl;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Rollback-only fixtures in upgrade_test. No uploads, migrations or real media writes. */
class PublicStorageImageUrlTest extends TestCase
{
    use DatabaseTransactions;

    private function storageUrl(string $url): void
    {
        config(['filesystems.disks.public.url' => $url]);
        Storage::forgetDisk('public');
    }

    #[DataProvider('imagePaths')]
    public function test_all_image_models_share_the_disk_url_without_changing_paths(?string $path, ?string $expected): void
    {
        $this->storageUrl('https://crmupgradecol.com/storage/');
        $this->assertSame($expected, PublicStorageUrl::image($path));
        foreach ([new Product(['main_image' => $path]), new ProductVariant(['main_image' => $path])] as $model) {
            $before = $model->getAttributes();
            $this->assertSame($expected, $model->image_url);
            $this->assertSame($before, $model->getAttributes());
        }
        $image = new ProductImage(['path' => $path]);
        $this->assertSame($expected ?? '', $image->imageUrl());
        $this->assertSame($path, $image->path);
    }

    public static function imagePaths(): array
    {
        $url = 'https://crmupgradecol.com/storage/products/OriginalCase.png';

        return [
            'stored path' => ['products/OriginalCase.png', $url],
            'root relative storage' => ['/storage/products/OriginalCase.png', $url],
            'relative storage' => ['storage/products/OriginalCase.png', $url],
            'leading slash' => ['/products/OriginalCase.png', $url],
            'valid CDN absolute' => ['https://cdn.example.test/item.png?v=2', 'https://cdn.example.test/item.png?v=2'],
            'valid HTTP local' => ['http://127.0.0.1:8000/storage/a.png', 'http://127.0.0.1:8000/storage/a.png'],
            'network URL' => ['//cdn.example.test/item.png', '//cdn.example.test/item.png'],
            'null' => [null, null],
            'empty' => ['', null],
        ];
    }

    private function product(): Product
    {
        $tag = 'image-url-'.Str::uuid();
        $product = Product::create([
            'name' => 'URL fixture '.$tag, 'slug' => $tag, 'sku' => $tag,
            'price' => 123456, 'main_image' => 'products/primary-test.png',
            'compatibility_type' => 'universal', 'is_visible' => true, 'is_active' => true,
        ]);
        $product->images()->create(['path' => 'products/primary-test.png', 'is_primary' => true, 'sort_order' => 0]);
        $product->images()->create(['path' => 'storage/products/gallery-test.png', 'is_primary' => false, 'sort_order' => 1]);
        $product->variants()->create([
            'name' => 'Variant fixture', 'sku' => $tag.'-V1', 'price' => 234567,
            'main_image' => '/storage/products/variant-test.png', 'is_default' => true,
            'is_visible' => true, 'is_active' => true, 'sort_order' => 0,
        ]);

        return $product;
    }

    public function test_public_list_detail_and_admin_resources_ignore_the_bridge_host(): void
    {
        $this->storageUrl('https://crmupgradecol.com/storage');
        $product = $this->product();
        $before = $product->getAttributes();
        $paths = $product->images()->pluck('path')->all();
        $response = $this->getJson('https://upgradecolombia.com/api/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.image_url', 'https://crmupgradecol.com/storage/products/primary-test.png')
            ->assertJsonPath('data.images.1.image_url', 'https://crmupgradecol.com/storage/products/gallery-test.png')
            ->assertJsonPath('data.variants.0.image_url', 'https://crmupgradecol.com/storage/products/variant-test.png');
        $this->assertArrayNotHasKey('cost_price', $response->json('data'));
        $this->getJson('/api/products?in_stock=0&search='.$product->sku)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.image_url', $response->json('data.image_url'));
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->getJson('/api/admin/products?search='.$product->sku)->assertOk()
            ->assertJsonPath('data.0.image_url', $response->json('data.image_url'))
            ->assertJsonPath('data.0.images.1.image_url', $response->json('data.images.1.image_url'))
            ->assertJsonPath('data.0.variants.0.image_url', $response->json('data.variants.0.image_url'));
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame($paths, $product->images()->pluck('path')->all());
    }

    public function test_local_origin_is_configured_independently_of_frontend_host_and_hidden_products_stay_hidden(): void
    {
        $this->storageUrl('http://127.0.0.1:8000/storage');
        $product = $this->product();
        $this->getJson('http://127.0.0.1:5173/api/products/'.$product->slug)->assertOk()
            ->assertJsonPath('data.image_url', 'http://127.0.0.1:8000/storage/products/primary-test.png');
        $product->update(['is_visible' => false]);
        $this->getJson('/api/products/'.$product->slug)->assertNotFound();
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_dynamic_html_open_graph_and_json_ld_use_the_same_correct_storage_origin(): void
    {
        $this->storageUrl('https://crmupgradecol.com/storage');
        $product = $this->product();
        $data = $this->getJson('/api/products/'.$product->slug)->assertOk()->json('data');
        $directory = sys_get_temp_dir().'/upgrade79-image-url-'.Str::uuid();
        mkdir($directory.'/seo', 0700, true);
        file_put_contents($directory.'/seo/template.html', '<html><head><!--seo-head--></head><body><div id="root"></div></body></html>');
        config(['public_catalog.frontend_path' => $directory]);
        try {
            $html = app(PublicCatalogPage::class)->product($data)->getContent();
            $this->assertStringContainsString('property="og:image" content="https://crmupgradecol.com/storage/products/primary-test.png"', $html);
            $this->assertStringContainsString('"image":["https://crmupgradecol.com/storage/products/primary-test.png","https://crmupgradecol.com/storage/products/gallery-test.png"]', $html);
            $this->assertStringContainsString('https://upgradecolombia.com/tienda/'.$product->slug, $html);
            $this->assertStringNotContainsString('https://upgradecolombia.com/storage/', $html);
        } finally {
            unlink($directory.'/seo/template.html');
            rmdir($directory.'/seo');
            rmdir($directory);
        }
    }
}
