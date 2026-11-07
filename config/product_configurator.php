<?php

// Explicit preview of the reviewed local QA product, never a category/slug opt-in.
// Other catalogs (including upgrade_test) keep the original empty defaults.
$localQaPreview = env('APP_ENV') === 'local'
    && env('DB_CONNECTION') === 'mysql'
    && env('DB_HOST') === '127.0.0.1'
    && (string) env('DB_PORT', '3306') === '3306'
    && env('DB_DATABASE') === 'upgrade'
    && ! env('DB_URL');

// Explicit reviewed enrollment, never automatic by category, operating system or name.
// This only prepares activation; no environment or production record is changed here.
$rawRegistration = env('PRODUCT_CONFIGURATOR_REGISTRATION');
$registration = null;
if (is_string($rawRegistration) && trim($rawRegistration) !== '') {
    $registration = json_decode($rawRegistration, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($registration) || ! is_array($registration['catalog'] ?? null)
        || ! is_array($registration['products'] ?? null)
        || array_diff(array_keys($registration), ['catalog', 'products'])) {
        throw new RuntimeException('Invalid explicit configurator registration.');
    }
}

return [
    'schema_version' => 1,

    'preview_enabled' => (bool) env('PRODUCT_CONFIGURATOR_PREVIEW_ENABLED', $localQaPreview),
    'purchase_enabled' => (bool) env('PRODUCT_CONFIGURATOR_PURCHASE_ENABLED', false),
    'production_approved' => (bool) env('PRODUCT_CONFIGURATOR_PRODUCTION_APPROVED', false),

    // Explicit environment + connection identity, never shared IDs between catalogs.
    // Example shape only: environment, driver, host, port, database (all strings).
    'catalog' => $registration['catalog'] ?? ($localQaPreview ? [
        'environment' => 'local', 'driver' => 'mysql', 'host' => '127.0.0.1',
        'port' => '3306', 'database' => 'upgrade',
    ] : null),

    'profiles' => [
        'vehicle-oem-memory-v1' => ['version' => 1, 'enabled' => true],
        'vehicle-oem-reference-v1' => ['version' => 1, 'enabled' => true],
    ],

    // Reviewed immutable product ID => profile_id/profile_version, within catalog above.
    // Never infer enrollment from name, slug, category, brand or SKU.
    'products' => $registration['products'] ?? ($localQaPreview ? [
        6 => ['profile_id' => 'vehicle-oem-memory-v1', 'profile_version' => 1],
    ] : []),
];
