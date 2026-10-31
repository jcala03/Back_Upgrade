<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Schema;

class ProductConfiguratorRegistry
{
    public function registration(Product $product): ?array
    {
        $entries = config('product_configurator.products', []);
        if (! is_array($entries) || ! array_key_exists($product->id, $entries)) {
            return null;
        }

        $entry = $entries[$product->id];
        $id = is_array($entry) ? ($entry['profile_id'] ?? null) : null;
        $version = is_array($entry) ? ($entry['profile_version'] ?? null) : null;
        $profile = is_string($id) ? (config('product_configurator.profiles', [])[$id] ?? null) : null;
        $supported = $id === 'vehicle-oem-memory-v1' && $version === 1
            && is_array($profile) && ($profile['enabled'] ?? false) === true
            && $version === ($profile['version'] ?? null) && $this->matchesCatalog($product);
        $local = app()->environment(['local', 'testing']);
        $ui = $supported && $local && config('product_configurator.preview_enabled', false) === true;

        // Invalid registrations remain required: never silently return a traditional selector.
        return [
            'schema_version' => 1,
            'required' => true,
            'profile_id' => is_string($id) ? $id : null,
            'profile_version' => is_int($version) ? $version : null,
            'ui_enabled' => $ui,
            'purchase_enabled' => $ui && config('product_configurator.purchase_enabled', false) === true
                && Schema::connection($product->getConnectionName())->hasColumn('order_items', 'configuration_context'),
        ];
    }

    public function catalogIdentity(Product $product): array
    {
        $connection = $product->getConnection();

        return [
            'environment' => app()->environment(),
            'driver' => (string) $connection->getConfig('driver'),
            'host' => (string) $connection->getConfig('host'),
            'port' => (string) $connection->getConfig('port'),
            'database' => $connection->getDatabaseName(),
        ];
    }

    private function matchesCatalog(Product $product): bool
    {
        $catalog = config('product_configurator.catalog');
        if (! is_array($catalog)) {
            return false;
        }
        foreach ($this->catalogIdentity($product) as $key => $value) {
            if (($catalog[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
