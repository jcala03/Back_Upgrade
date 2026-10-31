<?php

// Explicit preview of the reviewed local QA product, never a category/slug opt-in.
// Other catalogs (including upgrade_test) keep the original empty defaults.
$localQaPreview = env('APP_ENV') === 'local'
    && env('DB_CONNECTION') === 'mysql'
    && env('DB_HOST') === '127.0.0.1'
    && (string) env('DB_PORT', '3306') === '3306'
    && env('DB_DATABASE') === 'upgrade'
    && ! env('DB_URL');

return [
    'schema_version' => 1,

    // Local/test opt-ins only. Production buying remains blocked in the registry.
    'preview_enabled' => $localQaPreview,
    'purchase_enabled' => false,

    // Explicit environment + connection identity, never shared IDs between catalogs.
    // Example shape only: environment, driver, host, port, database (all strings).
    'catalog' => $localQaPreview ? [
        'environment' => 'local', 'driver' => 'mysql', 'host' => '127.0.0.1',
        'port' => '3306', 'database' => 'upgrade',
    ] : null,

    'profiles' => [
        'vehicle-oem-memory-v1' => ['version' => 1, 'enabled' => true],
    ],

    // Reviewed immutable product ID => profile_id/profile_version, within catalog above.
    // Never infer enrollment from name, slug, category, brand or SKU.
    'products' => $localQaPreview ? [
        6 => ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1],
    ] : [],
];
