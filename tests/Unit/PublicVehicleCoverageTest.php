<?php

namespace Tests\Unit;

use App\Http\Resources\PublicProductResource;
use App\Models\Product;
use App\Models\ProductVariantCompatibility;
use App\Models\VehicleMultimediaSystem;
use App\Models\VehicleVersion;
use App\Services\PublicVehicleCompatibility;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Real Eloquent SQL, evaluated in Python's in-memory SQLite. No PHP DB connection,
 * Laravel kernel, .env, MySQL, persisted fixtures or commercial records.
 */
class PublicVehicleCoverageTest extends TestCase
{
    #[DataProvider('years')]
    public function test_same_row_year_predicate_is_shared_by_listing_and_purchase(int $year, int $system, int $generation, array $expected): void
    {
        $previous = Model::getConnectionResolver();
        $connection = new MySqlConnection(fn () => throw new \LogicException('No database access allowed'));
        Model::setConnectionResolver(new ConnectionResolver(['default' => $connection]));
        Model::getConnectionResolver()->setDefaultConnection('default');
        try {
            $request = new Request(['vehicle_brand_id' => 1, 'vehicle_model_id' => 1,
                'vehicle_version_id' => $generation, 'vehicle_multimedia_system_id' => $system, 'year' => $year]);
            $purchase = ProductVariantCompatibility::query()->select('id')->orderBy('id');
            PublicVehicleCompatibility::matchRow($purchase, $request);
            $variants = PublicVehicleCompatibility::variants($request)->select('id')->orderBy('id');
            $products = Product::query()->select('id');
            PublicVehicleCompatibility::products($products, $request);
            $queries = array_map(fn ($query) => ['sql' => $query->toSql(), 'bindings' => $query->getBindings()], [$purchase, $variants, $products]);
        } finally {
            if ($previous) {
                Model::setConnectionResolver($previous);
            } else {
                Model::unsetConnectionResolver();
            }
        }

        $process = new Process(['python3', '-c', <<<'PY'
import sqlite3,sys,json
db=sqlite3.connect(':memory:')
db.executescript('''
CREATE TABLE products(id INTEGER);
CREATE TABLE product_variants(id INTEGER,product_id INTEGER,is_active INTEGER,is_visible INTEGER);
CREATE TABLE vehicle_versions(id INTEGER,year_from INTEGER,year_to INTEGER);
CREATE TABLE vehicle_multimedia_systems(id INTEGER,is_active INTEGER);
CREATE TABLE product_variant_compatibilities(id INTEGER,product_variant_id INTEGER,vehicle_brand_id INTEGER,vehicle_model_id INTEGER,vehicle_version_id INTEGER,vehicle_multimedia_system_id INTEGER,year_from INTEGER,year_to INTEGER);
CREATE TABLE vehicle_version_multimedia_system(vehicle_version_id INTEGER,vehicle_multimedia_system_id INTEGER,year_from INTEGER,year_to INTEGER);
INSERT INTO products VALUES(1);
INSERT INTO vehicle_versions VALUES(2,2012,2018),(3,2010,2020);
INSERT INTO vehicle_multimedia_systems VALUES(1,1),(2,1),(3,1),(4,1),(5,1);
INSERT INTO vehicle_version_multimedia_system VALUES(2,1,2012,2013),(2,2,2014,NULL),(3,1,2017,2018),(2,4,2012,2013),(2,4,2016,2017),(2,5,NULL,NULL);
''')
rows=[(1,2,1,None,None),(2,2,2,None,None),(3,3,1,None,None),(4,2,1,2013,2015),(5,2,1,2012,2012),(6,2,3,None,None),(7,2,4,None,None),(8,2,5,None,None)]
for ident,generation,system,start,end in rows:
    db.execute('INSERT INTO product_variants VALUES(?,?,1,1)',(ident,1))
    db.execute('INSERT INTO product_variant_compatibilities VALUES(?,?,1,1,?,?,?,?)',(ident,ident,generation,system,start,end))
print(json.dumps([[r[0] for r in db.execute(q['sql'],q['bindings'])] for q in json.load(sys.stdin)]))
PY]);
        $process->setInput(json_encode($queries, JSON_THROW_ON_ERROR));
        $process->mustRun();
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $actual[0], 'Authoritative purchase compatibility row');
        $this->assertSame($expected, $actual[1], 'Public variant selection');
        $this->assertSame($expected === [] ? [] : [1], $actual[2], 'Public parent selection');
    }

    public static function years(): array
    {
        return [
            'CIC lower inclusive' => [2012, 1, 2, [1, 5]],
            'CIC upper inclusive intersects commercial override' => [2013, 1, 2, [1, 4]],
            'CIC after recorded coverage' => [2014, 1, 2, []],
            'CIC before recorded coverage' => [2011, 1, 2, []],
            'NBT not CIC' => [2014, 2, 2, [2]],
            'NBT open upper still bounded by generation' => [2018, 2, 2, [2]],
            'outside generation' => [2019, 2, 2, []],
            'same OEM different generation lower' => [2016, 1, 3, []],
            'same OEM different generation inclusive' => [2017, 1, 3, [3]],
            'no technical pair preserves legacy rule, not certification' => [2016, 3, 2, [6]],
            'disjoint ranges do not fill gap' => [2014, 4, 2, []],
            'disjoint second range' => [2016, 4, 2, [7]],
            'null bounds do not invent dates' => [2015, 5, 2, [8]],
        ];
    }

    public function test_public_row_exposes_only_exact_pair_ranges_without_lazy_queries(): void
    {
        $version = new VehicleVersion(['year_from' => 2012, 'year_to' => 2018]);
        $systems = collect([1, 2])->map(function ($id) {
            $system = new VehicleMultimediaSystem(['name' => 'Synthetic unit fixture']);
            $system->id = $id;
            $system->setRelation('pivot', new Pivot(['year_from' => 2011 + $id, 'year_to' => 2012 + $id]));

            return $system;
        });
        $version->setRelation('multimediaSystems', $systems);
        $row = new ProductVariantCompatibility(['vehicle_version_id' => 2, 'vehicle_multimedia_system_id' => 1]);
        $row->setRelation('vehicleVersion', $version);
        $this->assertSame([['year_from' => 2012, 'year_to' => 2013]], PublicProductResource::variantCompatibility($row)['oem_year_ranges']);
        $row->vehicle_multimedia_system_id = 999;
        $this->assertSame([], PublicProductResource::variantCompatibility($row)['oem_year_ranges']);
        $row->unsetRelation('vehicleVersion');
        $this->assertNull(PublicProductResource::variantCompatibility($row)['oem_year_ranges']);
    }

    public function test_omitting_year_preserves_existing_compatibility_and_does_not_infer_dates(): void
    {
        $previous = Model::getConnectionResolver();
        $connection = new MySqlConnection(fn () => throw new \LogicException('No database access allowed'));
        $resolver = new ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);
        try {
            $query = ProductVariantCompatibility::query();
            PublicVehicleCompatibility::matchRow($query, new Request(['vehicle_multimedia_system_id' => 1]));
            $this->assertStringNotContainsString('oem_coverage', $query->toSql());
            $this->assertSame([1, true], $query->getBindings());
        } finally {
            if ($previous) {
                Model::setConnectionResolver($previous);
            } else {
                Model::unsetConnectionResolver();
            }
        }
    }
}
