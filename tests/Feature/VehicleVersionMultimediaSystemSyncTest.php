<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VehicleBrand;
use App\Models\VehicleModel;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleVersionMultimediaSystemSyncTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $connection = getenv('TEST_DB_CONNECTION');

        if ($connection) {
            $app['config']->set('database.default', $connection);
            $app['config']->set(
                "database.connections.{$connection}.database",
                getenv('TEST_DB_DATABASE') ?: 'upgrade_vehicle_oem_test'
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_it_associates_a_system_with_a_vehicle_version(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [$this->entry($systems[0])])->assertOk();

        $this->assertDatabaseHas('vehicle_version_multimedia_system', [
            'vehicle_version_id' => $version->id,
            'vehicle_multimedia_system_id' => $systems[0]->id,
        ]);
    }

    public function test_it_associates_multiple_systems_from_the_same_brand(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [
            $this->entry($systems[0]),
            $this->entry($systems[1], 2014, 2018),
        ])->assertOk()->assertJsonCount(2, 'data.multimedia_systems');
    }

    public function test_it_updates_an_existing_pivot_range(): void
    {
        [$version, $systems] = $this->catalog();
        $this->sync($version, [$this->entry($systems[0], 2013, 2016)])->assertOk();

        $this->sync($version, [$this->entry($systems[0], 2015, 2019)])->assertOk();

        $this->assertDatabaseHas('vehicle_version_multimedia_system', [
            'vehicle_version_id' => $version->id,
            'vehicle_multimedia_system_id' => $systems[0]->id,
            'year_from' => 2015,
            'year_to' => 2019,
        ]);
    }

    public function test_sync_removes_an_omitted_association(): void
    {
        [$version, $systems] = $this->catalog();
        $this->sync($version, [$this->entry($systems[0]), $this->entry($systems[1])]);

        $this->sync($version, [$this->entry($systems[1])])->assertOk();

        $this->assertDatabaseMissing('vehicle_version_multimedia_system', [
            'vehicle_version_id' => $version->id,
            'vehicle_multimedia_system_id' => $systems[0]->id,
        ]);
    }

    public function test_an_empty_array_removes_all_associations(): void
    {
        [$version, $systems] = $this->catalog();
        $this->sync($version, [$this->entry($systems[0]), $this->entry($systems[1])]);

        $this->sync($version, [])->assertOk()->assertJsonCount(0, 'data.multimedia_systems');

        $this->assertDatabaseMissing('vehicle_version_multimedia_system', [
            'vehicle_version_id' => $version->id,
        ]);
    }

    public function test_a_system_from_another_brand_is_rejected(): void
    {
        [$version] = $this->catalog('BMW');
        [, $otherSystems] = $this->catalog('Mercedes');

        $this->sync($version, [$this->entry($otherSystems[0])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(
                'multimedia_systems.0.vehicle_multimedia_system_id'
            );
    }

    public function test_year_from_greater_than_year_to_is_rejected(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [$this->entry($systems[0], 2018, 2016)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('multimedia_systems.0.year_to');
    }

    public function test_a_year_below_the_vehicle_version_range_is_rejected(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [$this->entry($systems[0], 2010, null)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('multimedia_systems.0.year_from');
    }

    public function test_a_year_above_the_vehicle_version_range_is_rejected(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [$this->entry($systems[0], null, 2021)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('multimedia_systems.0.year_to');
    }

    public function test_the_saved_relationship_is_returned_in_the_response(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [$this->entry($systems[0])])
            ->assertOk()
            ->assertJsonPath('data.multimedia_systems.0.id', $systems[0]->id)
            ->assertJsonPath(
                'data.multimedia_systems.0.vehicle_brand_id',
                $systems[0]->vehicle_brand_id
            );
    }

    public function test_pivot_year_attributes_are_returned_in_the_response(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [$this->entry($systems[0], 2014, 2017)])
            ->assertOk()
            ->assertJsonPath('data.multimedia_systems.0.pivot.year_from', 2014)
            ->assertJsonPath('data.multimedia_systems.0.pivot.year_to', 2017);
    }

    public function test_detaching_does_not_delete_the_multimedia_system(): void
    {
        [$version, $systems] = $this->catalog();
        $this->sync($version, [$this->entry($systems[0])]);

        $this->sync($version, [])->assertOk();

        $this->assertDatabaseHas('vehicle_multimedia_systems', [
            'id' => $systems[0]->id,
        ]);
    }

    public function test_identical_duplicate_entries_are_normalized(): void
    {
        [$version, $systems] = $this->catalog();
        $entry = $this->entry($systems[0], 2014, 2017);

        $this->sync($version, [$entry, $entry])
            ->assertOk()
            ->assertJsonCount(1, 'data.multimedia_systems');

        $this->assertDatabaseCount('vehicle_version_multimedia_system', 1);
    }

    public function test_the_same_system_with_different_ranges_is_rejected(): void
    {
        [$version, $systems] = $this->catalog();

        $this->sync($version, [
            $this->entry($systems[0], 2014, 2016),
            $this->entry($systems[0], 2017, 2019),
        ])->assertUnprocessable()->assertJsonValidationErrors(
            'multimedia_systems.1.vehicle_multimedia_system_id'
        );
    }

    private function sync(VehicleVersion $version, array $systems)
    {
        return $this->putJson(
            "/api/admin/vehicle-versions/{$version->id}/multimedia-systems",
            ['multimedia_systems' => $systems]
        );
    }

    private function entry(
        VehicleMultimediaSystem $system,
        ?int $yearFrom = null,
        ?int $yearTo = null
    ): array {
        return [
            'vehicle_multimedia_system_id' => $system->id,
            'year_from' => $yearFrom,
            'year_to' => $yearTo,
        ];
    }

    private function catalog(string $prefix = 'BMW'): array
    {
        $slug = strtolower($prefix).'-'.fake()->unique()->numerify('####');
        $brand = VehicleBrand::create([
            'name' => "{$prefix} {$slug}",
            'slug' => $slug,
            'is_active' => true,
        ]);
        $model = VehicleModel::create([
            'vehicle_brand_id' => $brand->id,
            'name' => "Model {$slug}",
            'slug' => "model-{$slug}",
            'is_active' => true,
        ]);
        $version = VehicleVersion::create([
            'vehicle_model_id' => $model->id,
            'name' => 'Generation',
            'year_from' => 2012,
            'year_to' => 2020,
            'is_active' => true,
        ]);
        $systems = collect(['System A', 'System B'])->map(function ($name) use ($brand, $slug) {
            return VehicleMultimediaSystem::create([
                'vehicle_brand_id' => $brand->id,
                'name' => "{$name} {$slug}",
                'slug' => str($name)->slug()."-{$slug}",
                'code' => str($name)->slug()->upper(),
                'is_active' => true,
            ]);
        })->all();

        return [$version, $systems];
    }
}
