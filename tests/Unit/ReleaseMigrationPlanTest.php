<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../ops/ReleaseMigrationPlan.php';

class ReleaseMigrationPlanTest extends TestCase
{
    public function test_only_the_reviewed_migration_can_be_pending(): void
    {
        $report = \ReleaseMigrationPlan::verify(['old', \ReleaseMigrationPlan::MIGRATION], ['old'], []);
        $this->assertSame([\ReleaseMigrationPlan::MIGRATION], $report['pending']);
        $this->assertFalse($report['configuration_context_nullable']);
    }

    #[DataProvider('compatibleColumnTypes')]
    public function test_current_schema_accepts_mysql_json_and_mariadb_alias(string $type): void
    {
        $report = \ReleaseMigrationPlan::verify(['old'], ['old'], [['IS_NULLABLE' => 'YES', 'DATA_TYPE' => $type]], true);
        $this->assertSame([], $report['pending']);
        $this->assertTrue($report['configuration_context_nullable']);
    }

    public static function compatibleColumnTypes(): array
    {
        return [['json'], ['longtext']];
    }

    #[DataProvider('unsafePlans')]
    public function test_unsafe_or_incomplete_plans_stop_before_migration(array $files, array $applied, array $columns, bool $requireCurrent): void
    {
        $this->expectException(RuntimeException::class);
        \ReleaseMigrationPlan::verify($files, $applied, $columns, $requireCurrent);
    }

    public static function unsafePlans(): array
    {
        $migration = '2026_10_28_000001_add_configuration_context_to_order_items';
        $valid = [['IS_NULLABLE' => 'YES', 'DATA_TYPE' => 'json']];

        return [
            'unreviewed pending' => [['old', 'other'], ['old'], [], false],
            'orphaned history' => [['old'], ['old', 'missing-file'], $valid, false],
            'column already exists' => [[$migration], [], $valid, false],
            'wrong preexisting column' => [[$migration], [], [['IS_NULLABLE' => 'NO', 'DATA_TYPE' => 'text']], false],
            'recorded without column' => [[$migration], [$migration], [], false],
            'non nullable' => [[$migration], [$migration], [['IS_NULLABLE' => 'NO', 'DATA_TYPE' => 'json']], true],
            'wrong type' => [[$migration], [$migration], [['IS_NULLABLE' => 'YES', 'DATA_TYPE' => 'varchar']], true],
            'authorization omitted' => [[$migration], [], [], true],
        ];
    }
}
