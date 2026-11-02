<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../ops/ReleaseWompiPolicy.php';

class ReleaseWompiPolicyTest extends TestCase
{
    private static function validConfiguration(): array
    {
        return [
            'public_key' => 'pub_prod_syntheticQA',
            'private_key' => 'prv_prod_syntheticQA',
            'integrity_secret' => 'prod_integrity_syntheticQA',
            'events_secret' => 'prod_events_syntheticQA',
            'reservation_minutes' => 30,
        ];
    }

    public function test_complete_production_configuration_passes_without_provider_requests(): void
    {
        $checks = \ReleaseWompiPolicy::checks(self::validConfiguration());
        $this->assertCount(5, $checks);
        $this->assertNotContains(false, $checks);
        $this->assertNotContains('prv_prod_syntheticQA', array_keys($checks));
    }

    #[DataProvider('invalidCredentials')]
    public function test_missing_mixed_or_malformed_credentials_are_refused(string $key, mixed $value): void
    {
        $configuration = self::validConfiguration();
        $configuration[$key] = $value;
        $this->assertContains(false, \ReleaseWompiPolicy::checks($configuration));
    }

    public static function invalidCredentials(): array
    {
        $cases = [];
        foreach (['public_key' => 'pub', 'private_key' => 'prv', 'integrity_secret' => 'integrity', 'events_secret' => 'events'] as $key => $type) {
            $testPrefix = in_array($type, ['pub', 'prv'], true) ? $type.'_test_' : 'test_'.$type.'_';
            $prodPrefix = in_array($type, ['pub', 'prv'], true) ? $type.'_prod_' : 'prod_'.$type.'_';
            foreach ([null, '', false, [], $testPrefix.'synthetic', $prodPrefix, $prodPrefix."synthetic\n", ' '.$prodPrefix.'synthetic', $prodPrefix.'with space'] as $index => $value) {
                $cases[$key.' invalid '.$index] = [$key, $value];
            }
        }

        return $cases;
    }

    public function test_all_credentials_absent_is_not_a_ready_production_checkout(): void
    {
        $this->assertContains(false, \ReleaseWompiPolicy::checks([]));
    }

    #[DataProvider('invalidReservationMinutes')]
    public function test_reservation_setting_matches_runtime_constraints(mixed $value): void
    {
        $configuration = self::validConfiguration();
        $configuration['reservation_minutes'] = $value;
        $this->assertFalse(\ReleaseWompiPolicy::checks($configuration)['WOMPI_RESERVATION_MINUTES_valid']);
    }

    public static function invalidReservationMinutes(): array
    {
        return [[null], [0], [-1], [1441], ['30'], [30.5], [true]];
    }

    public function test_boundary_reservations_and_non_fixed_key_lengths_are_supported(): void
    {
        foreach ([1, 1440] as $minutes) {
            $configuration = self::validConfiguration();
            $configuration['reservation_minutes'] = $minutes;
            $configuration['public_key'] = 'pub_prod_a-b_c';
            $this->assertNotContains(false, \ReleaseWompiPolicy::checks($configuration));
        }
    }
}
