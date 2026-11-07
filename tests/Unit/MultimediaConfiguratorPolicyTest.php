<?php

namespace Tests\Unit;

use App\Services\VehicleYearCoverage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../ops/ReleaseConfiguratorPolicy.php';

class MultimediaConfiguratorPolicyTest extends TestCase
{
    #[DataProvider('coverage')]
    public function test_only_partial_coverage_requires_a_real_year(?int $from, ?int $to, array $technical, bool $required): void
    {
        $this->assertSame($required, VehicleYearCoverage::requiresYear(2012, 2018, $from, $to, $technical));
    }

    public static function coverage(): array
    {
        return [
            'legacy whole generation' => [null, null, [], false],
            'whole explicit interval' => [2012, 2018, [], false],
            'commercial lower bound' => [2013, null, [], true],
            'commercial upper bound' => [null, 2017, [], true],
            'technical partial CIC' => [null, null, [['year_from' => 2012, 'year_to' => 2013]], true],
            'technical complete union' => [null, null, [['year_from' => 2014, 'year_to' => 2018], ['year_from' => 2012, 'year_to' => 2013]], false],
            'technical gap' => [null, null, [['year_from' => 2012, 'year_to' => 2013], ['year_from' => 2015, 'year_to' => 2018]], true],
            'unknown bounds do not manufacture years' => [null, null, [['year_from' => null, 'year_to' => null]], false],
        ];
    }

    private function configuration(): array
    {
        return [
            'preview_enabled' => true, 'purchase_enabled' => true, 'production_approved' => true,
            'catalog' => ['environment' => 'production', 'driver' => 'mysql', 'host' => 'fixture.local',
                'port' => '3306', 'database' => 'isolated_production_identity'],
            'profiles' => [
                'vehicle-oem-memory-v1' => ['version' => 1, 'enabled' => true],
                'vehicle-oem-reference-v1' => ['version' => 1, 'enabled' => true],
            ],
            'products' => [123 => ['profile_id' => 'vehicle-oem-reference-v1', 'profile_version' => 1, 'reviewed' => true, 'approved_variant_ids' => [456]]],
        ];
    }

    private function database(): array
    {
        return ['host' => 'fixture.local', 'port' => '3306', 'database' => 'isolated_production_identity'];
    }

    public function test_disabled_defaults_and_explicit_reviewed_activation_are_distinct(): void
    {
        $this->assertTrue(\ReleaseConfiguratorPolicy::accepts([], []));
        $this->assertTrue(\ReleaseConfiguratorPolicy::accepts($this->configuration(), $this->database()));
        $config = $this->configuration();
        $config['products'][123]['profile_id'] = 'vehicle-oem-memory-v1';
        $this->assertTrue(\ReleaseConfiguratorPolicy::accepts($config, $this->database()));
    }

    public function test_partial_unreviewed_wrong_catalog_or_unknown_profile_never_passes(): void
    {
        foreach ([
            ['production_approved', false], ['preview_enabled', false], ['purchase_enabled', 'true'],
            ['products', []], ['catalog', null], ['profiles', []],
        ] as [$key, $value]) {
            $config = $this->configuration();
            $config[$key] = $value;
            $this->assertFalse(\ReleaseConfiguratorPolicy::accepts($config, $this->database()), $key);
        }
        foreach (['host', 'port', 'database', 'driver', 'environment'] as $key) {
            $config = $this->configuration();
            $config['catalog'][$key] = 'not-the-reviewed-catalog';
            $this->assertFalse(\ReleaseConfiguratorPolicy::accepts($config, $this->database()), $key);
        }
        foreach ([['reviewed', false], ['profile_id', 'invented-profile'], ['profile_version', 2]] as [$key, $value]) {
            $config = $this->configuration();
            $config['products'][123][$key] = $value;
            $this->assertFalse(\ReleaseConfiguratorPolicy::accepts($config, $this->database()), $key);
        }
        $db = $this->database();
        $db['database'] = 'upgrade';
        $config = $this->configuration();
        $config['catalog']['database'] = 'upgrade';
        $this->assertFalse(\ReleaseConfiguratorPolicy::accepts($config, $db));
    }

    public function test_purchase_requires_explicit_strict_variant_allowlist_but_preview_does_not(): void
    {
        foreach ([null, [], ['456'], [0], [-1], [456, 456], ['arbitrary' => 456]] as $ids) {
            $config = $this->configuration();
            $config['products'][123]['approved_variant_ids'] = $ids;
            $this->assertFalse(\ReleaseConfiguratorPolicy::accepts($config, $this->database()));
            $config['purchase_enabled'] = false;
            $this->assertTrue(\ReleaseConfiguratorPolicy::accepts($config, $this->database()));
        }
    }
}
