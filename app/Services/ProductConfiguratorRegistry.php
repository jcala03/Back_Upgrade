<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Schema;

class ProductConfiguratorRegistry
{
    public function registration(Product $product): ?array
    {
        $entries = config('product_configurator.products', []);
        if (! is_array($entries) || ! array_key_exists($product->id, $entries)) {
            // Mandatory OEM compatibility cannot be bypassed with a traditional selector.
            // This blocks incomplete enrollment; it does NOT enroll/activate a category.
            if ($product->category_id !== null && $product->productCategory?->requires_oem_compatibility) {
                return ['schema_version' => 1, 'required' => true, 'profile_id' => null,
                    'profile_version' => null, 'ui_enabled' => false, 'purchase_enabled' => false];
            }

            return null;
        }

        $entry = $entries[$product->id];
        $id = is_array($entry) ? ($entry['profile_id'] ?? null) : null;
        $version = is_array($entry) ? ($entry['profile_version'] ?? null) : null;
        $profile = is_string($id) ? (config('product_configurator.profiles', [])[$id] ?? null) : null;
        $supported = in_array($id, ['vehicle-oem-memory-v1', 'vehicle-oem-reference-v1'], true) && $version === 1
            && is_array($profile) && ($profile['enabled'] ?? false) === true
            && $version === ($profile['version'] ?? null) && $this->matchesCatalog($product);
        $local = app()->environment(['local', 'testing']);
        $production = app()->environment('production')
            && config('product_configurator.production_approved', false) === true
            && ($entry['reviewed'] ?? false) === true
            && ! preg_match('/(?:^|[\\s_-])(?:QA|DEMO)(?:[\\s_-]|$)/i', $product->name.' '.$product->sku);
        $ui = $supported && ($local || $production) && config('product_configurator.preview_enabled', false) === true;

        // Invalid registrations remain required: never silently return a traditional selector.
        return [
            'schema_version' => 1,
            'required' => true,
            'profile_id' => is_string($id) ? $id : null,
            'profile_version' => is_int($version) ? $version : null,
            'ui_enabled' => $ui,
            'purchase_enabled' => $ui && config('product_configurator.purchase_enabled', false) === true
                && ($local || $this->approvedVariantIds($product) !== [])
                && Schema::connection($product->getConnectionName())->hasColumn('order_items', 'configuration_context'),
            ...(app()->environment('production') ? ['purchasable_variant_ids' => $this->approvedVariantIds($product)] : []),
        ];
    }

    /** Server-owned SKU approval: adding a variant must never inherit a parent's approval. */
    public function variantPurchaseApproved(Product $product, ProductVariant $variant): bool
    {
        return ! app()->environment('production')
            || in_array($variant->id, $this->approvedVariantIds($product), true);
    }

    private function approvedVariantIds(Product $product): array
    {
        $ids = config('product_configurator.products.'.$product->id.'.approved_variant_ids');
        if (! is_array($ids) || ! array_is_list($ids) || $ids === []
            || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            return [];
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                return [];
            }
        }

        return $ids;
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
