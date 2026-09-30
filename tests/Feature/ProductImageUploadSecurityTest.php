<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImageUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
    }

    public function test_valid_product_image_is_stored_after_validation(): void
    {
        $response = $this->post('/api/admin/products', [
            'name' => 'Secure image product',
            'main_image' => UploadedFile::fake()->image('product.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $response->json('data.main_image');

        $this->assertIsString($path);
        $this->assertStringStartsWith('products/', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_spoofed_image_mime_is_rejected_without_persisting_a_file(): void
    {
        $this->post('/api/admin/products', [
            'name' => 'Spoofed MIME',
            'main_image' => UploadedFile::fake()->create(
                'spoofed.jpg',
                10,
                'image/jpeg'
            )->mimeType('text/plain'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('main_image');

        $this->assertNoFilesWereStored();
    }

    public function test_valid_image_with_disallowed_extension_is_rejected(): void
    {
        $this->post('/api/admin/products', [
            'name' => 'False extension',
            'main_image' => UploadedFile::fake()->image('payload.php', 100, 100),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('main_image');

        $this->assertNoFilesWereStored();
    }

    public function test_oversized_image_is_rejected(): void
    {
        $this->post('/api/admin/products', [
            'name' => 'Oversized image',
            'main_image' => UploadedFile::fake()
                ->image('oversized.png', 100, 100)
                ->size(5121),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('main_image');

        $this->assertNoFilesWereStored();
    }

    public function test_non_image_file_is_rejected(): void
    {
        $this->post('/api/admin/products', [
            'name' => 'Text attachment',
            'main_image' => UploadedFile::fake()->createWithContent(
                'notes.txt',
                'not an image'
            ),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('main_image');

        $this->assertNoFilesWereStored();
    }

    public function test_malicious_filename_is_replaced_by_a_server_generated_name(): void
    {
        $response = $this->post('/api/admin/products', [
            'name' => 'Safe server filename',
            'main_image' => UploadedFile::fake()->image(
                '../../attempted-traversal.jpg',
                100,
                100
            ),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $response->json('data.main_image');

        $this->assertStringStartsWith('products/', $path);
        $this->assertStringNotContainsString('..', $path);
        $this->assertStringNotContainsString('attempted-traversal', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_valid_upload_still_works_when_updating_an_existing_product(): void
    {
        $product = Product::create([
            'name' => 'Existing product',
            'normalized_name' => 'existing product',
            'slug' => 'existing-product',
            'price' => 100000,
            'main_image' => 'products/existing.jpg',
            'is_active' => true,
            'is_visible' => true,
        ]);

        $response = $this->post("/api/admin/products/{$product->id}", [
            'main_image' => UploadedFile::fake()->image('replacement.webp', 640, 480),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $response->json('data.main_image');

        $this->assertStringStartsWith('products/', $path);
        $this->assertStringEndsWith('.webp', $path);
        Storage::disk('public')->assertExists($path);
    }

    private function assertNoFilesWereStored(): void
    {
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
