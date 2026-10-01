<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
    }

    private function createGallery(): Product
    {
        $id = $this->post('/api/admin/products', [
            'name' => 'Gallery product', 'images' => [UploadedFile::fake()->image('one.png'), UploadedFile::fake()->image('two.jpg')],
            'gallery' => json_encode([['upload_index' => 0], ['upload_index' => 1]]), 'primary_image_index' => 1,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'data.images')->json('data.id');

        return Product::findOrFail($id);
    }

    public function test_published_create_requires_image_and_hidden_create_does_not(): void
    {
        $this->postJson('/api/admin/products', ['name' => 'No image'])->assertUnprocessable()->assertJsonValidationErrors('gallery');
        $this->assertDatabaseCount('products', 0);
        $id = $this->postJson('/api/admin/products', ['name' => 'Hidden product', 'is_visible' => false])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/products/'.$id, ['is_visible' => true])->assertUnprocessable();
        $this->assertFalse(Product::find($id)->is_visible);
    }

    public function test_primary_path_and_order_are_serialized_publicly_without_private_fields(): void
    {
        $product = $this->createGallery();
        $images = $product->images()->get();
        $this->assertSame(1, $images->where('is_primary', true)->count());
        $this->assertSame($images[1]->path, $product->main_image);
        $response = $this->getJson('/api/products/'.$product->slug)->assertOk()->assertJsonPath('data.images.1.is_primary', true);
        $this->assertSame([0, 1], array_column($response->json('data.images'), 'sort_order'));
        $this->assertArrayNotHasKey('primary_product_id', $response->json('data.images.0'));
        $this->assertArrayNotHasKey('cost_price', $response->json('data'));
    }

    public function test_editing_other_fields_preserves_existing_images_without_reupload(): void
    {
        $product = $this->createGallery();
        $before = $product->images()->pluck('path')->all();
        $this->postJson('/api/admin/products/'.$product->id, ['description' => 'Updated'])->assertOk();
        $this->assertSame($before, $product->images()->pluck('path')->all());
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        $this->assertSame('Updated', $product->fresh()->description);
    }

    public function test_reorder_change_primary_and_remove_primary_are_consistent(): void
    {
        $product = $this->createGallery();
        $images = $product->images()->get();
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [['id' => $images[1]->id], ['id' => $images[0]->id]], 'primary_image_index' => 1])
            ->assertOk()->assertJsonPath('data.images.1.is_primary', true);
        $this->assertSame($images[0]->path, $product->fresh()->main_image);
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [['id' => $images[1]->id]], 'primary_image_index' => 0])->assertOk();
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
        Storage::disk('public')->assertMissing($images[0]->path);
        Storage::disk('public')->assertExists($images[1]->path);
    }

    public function test_cannot_remove_last_published_image_and_rollback_preserves_all_state(): void
    {
        $product = $this->createGallery();
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [], 'description' => 'Must roll back'])->assertUnprocessable();
        $this->assertDatabaseCount('product_images', 2);
        $this->assertNull($product->fresh()->description);
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [], 'is_visible' => false])->assertOk();
        $this->assertDatabaseCount('product_images', 0);
        $this->assertNull($product->fresh()->main_image);
    }

    public function test_image_ids_cannot_cross_products_and_duplicate_or_mixed_references_fail(): void
    {
        $product = $this->createGallery();
        $other = Product::create(['name' => 'Other', 'slug' => 'other', 'price' => 0, 'is_visible' => false]);
        $id = $product->images()->first()->id;
        $this->postJson('/api/admin/products/'.$other->id, ['gallery' => [['id' => $id]], 'primary_image_index' => 0])->assertUnprocessable();
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [['id' => $id], ['id' => $id]], 'primary_image_index' => 0])->assertUnprocessable();
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [['id' => $id, 'upload_index' => 0]], 'primary_image_index' => 0])->assertUnprocessable();
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [['id' => $id]], 'primary_image_index' => 3])->assertUnprocessable();
        $this->assertDatabaseCount('product_images', 2);
    }

    public function test_adding_files_keeps_old_images_and_rejected_mutations_cleanup_new_uploads(): void
    {
        $product = $this->createGallery();
        $images = $product->images()->get();
        $this->post('/api/admin/products/'.$product->id, [
            'images' => [UploadedFile::fake()->image('three.webp')],
            'gallery' => json_encode([['id' => $images[0]->id], ['id' => $images[1]->id], ['upload_index' => 0]]), 'primary_image_index' => 2,
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(3, 'data.images');
        $files = Storage::disk('public')->allFiles();
        $this->post('/api/admin/products/'.$product->id, [
            'images' => [UploadedFile::fake()->image('unused.png')], 'gallery' => '[]',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame($files, Storage::disk('public')->allFiles());
    }

    public function test_gallery_uploads_enforce_mime_extension_size_and_server_filename(): void
    {
        foreach ([UploadedFile::fake()->image('payload.php'), UploadedFile::fake()->image('big.png')->size(5121),
            UploadedFile::fake()->createWithContent('fake.jpg', '<script>alert(1)</script>')->mimeType('text/html'),
            UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')->mimeType('image/svg+xml')] as $file) {
            $response = $this->post('/api/admin/products', ['name' => 'Unsafe', 'images' => [$file]], ['Accept' => 'application/json']);
            $this->assertSame(422, $response->status(), $file->getClientOriginalName());
            $response->assertJsonValidationErrors('images.0');
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $response = $this->post('/api/admin/products', ['name' => 'Safe', 'images' => [UploadedFile::fake()->image('../../name.png')]], ['Accept' => 'application/json'])->assertCreated();
        $this->assertMatchesRegularExpression('~^products/[A-Za-z0-9]{40}\.png$~', $response->json('data.main_image'));
    }

    public function test_new_arbitrary_paths_cannot_bypass_upload_validation(): void
    {
        $this->postJson('/api/admin/products', ['name' => 'Forged path', 'main_image' => '../payload.php'])->assertUnprocessable();
        $product = $this->createGallery();
        $this->postJson('/api/admin/products/'.$product->id, ['main_image' => 'https://evil.test/payload.svg'])->assertUnprocessable();
    }

    public function test_unique_key_rejects_two_primary_images_even_outside_the_service(): void
    {
        $product = $this->createGallery();
        $this->expectException(UniqueConstraintViolationException::class);
        ProductImage::create(['product_id' => $product->id, 'path' => 'legacy.png', 'is_primary' => true]);
    }

    public function test_old_upload_contract_keeps_additional_images_and_variant_image_is_untouched(): void
    {
        $product = $this->createGallery();
        $variant = $product->variants()->create(['name' => 'Variant', 'normalized_name' => 'variant', 'price' => 0, 'main_image' => 'legacy/variant.png']);
        $this->post('/api/admin/products/'.$product->id, ['image' => UploadedFile::fake()->image('replacement.png')], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'data.images');
        $this->assertSame('legacy/variant.png', $variant->fresh()->main_image);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    public function test_shared_paths_and_legacy_paths_are_not_removed_from_storage(): void
    {
        $product = $this->createGallery();
        $path = $product->main_image;
        ProductVariant::create(['product_id' => $product->id, 'name' => 'Uses primary', 'normalized_name' => 'uses primary', 'price' => 0, 'main_image' => $path]);
        $this->postJson('/api/admin/products/'.$product->id, ['gallery' => [], 'is_visible' => false])->assertOk();
        Storage::disk('public')->assertExists($path);
    }

    public function test_migration_backfills_legacy_paths_once_without_changing_variant_images(): void
    {
        $product = Product::create(['name' => 'Legacy image', 'slug' => 'legacy-image', 'price' => 0, 'main_image' => 'qa-ux/legacy.svg', 'is_visible' => true]);
        $migration = require database_path('migrations/2026_10_01_000001_create_product_images_table.php');
        $migration->backfillLegacyImages();
        $migration->backfillLegacyImages();
        $this->assertSame(1, $product->images()->count());
        $this->assertSame('qa-ux/legacy.svg', $product->images()->sole()->path);
        $this->assertTrue($product->images()->sole()->is_primary);
        $this->assertSame(0, $product->images()->sole()->sort_order);
        $this->assertSame('qa-ux/legacy.svg', $product->fresh()->main_image);
    }

    public function test_migration_preflight_refuses_inconsistent_published_data_without_mutating_it(): void
    {
        $product = Product::create(['name' => 'Invalid legacy', 'slug' => 'invalid-legacy', 'price' => 0, 'is_visible' => true]);
        $migration = require database_path('migrations/2026_10_01_000001_create_product_images_table.php');
        try {
            $migration->up();
            $this->fail('Migration must not silently hide or delete inconsistent products.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Published products require image repair before migration: '.$product->id, $exception->getMessage());
        }
        $this->assertTrue($product->fresh()->is_visible);
        $this->assertDatabaseCount('product_images', 0);
    }
}
