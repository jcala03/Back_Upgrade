<?php

final class ReleaseMigrationPlan
{
    public const MIGRATION = '2026_10_28_000001_add_configuration_context_to_order_items';

    public static function verify(array $files, array $applied, array $columns, bool $requireCurrent = false): array
    {
        $pending = array_values(array_diff($files, $applied));
        $orphaned = array_values(array_diff($applied, $files));
        $column = count($columns) === 1 && $columns[0]['IS_NULLABLE'] === 'YES'
            && in_array($columns[0]['DATA_TYPE'], ['json', 'longtext'], true);
        if ($orphaned !== [] || ! in_array($pending, [[], [self::MIGRATION]], true)
            || ($pending === [] && ! $column)
            || ($pending !== [] && $columns !== [])
            || ($requireCurrent && $pending !== [])) {
            throw new RuntimeException('Migration history/schema mismatch; only the reviewed nullable configuration_context addition is authorized.');
        }

        return ['status' => 'PASS', 'applied' => count($applied), 'release_migrations' => count($files),
            'pending' => $pending, 'configuration_context_nullable' => $column];
    }
}
