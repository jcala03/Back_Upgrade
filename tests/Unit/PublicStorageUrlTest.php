<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\PublicStorageUrl;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** No kernel boot, DB, network, uploads or file modifications. */
class PublicStorageUrlTest extends TestCase
{
    private $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('config', new Repository(['filesystems' => ['disks' => ['public' => [
            'driver' => 'local', 'root' => sys_get_temp_dir(),
            'url' => 'https://crmupgradecol.com/storage/',
        ]]]]));
        $app->register(FilesystemServiceProvider::class);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    #[DataProvider('paths')]
    public function test_real_filesystem_url_and_model_accessors_preserve_stored_paths(?string $path, ?string $expected): void
    {
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

    public static function paths(): array
    {
        $url = 'https://crmupgradecol.com/storage/products/OriginalCase.png';

        return [
            ['products/OriginalCase.png', $url],
            ['/storage/products/OriginalCase.png', $url],
            ['storage/products/OriginalCase.png', $url],
            ['/products/OriginalCase.png', $url],
            ['https://cdn.example.test/item.png?v=2', 'https://cdn.example.test/item.png?v=2'],
            ['http://127.0.0.1:8000/storage/a.png', 'http://127.0.0.1:8000/storage/a.png'],
            ['//cdn.example.test/item.png', '//cdn.example.test/item.png'],
            [null, null], ['', null],
        ];
    }
}
