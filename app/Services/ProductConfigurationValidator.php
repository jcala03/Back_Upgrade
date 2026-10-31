<?php

namespace App\Services;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Public purchases only. CRM/traditional default variant resolution is not changed. */
class ProductConfigurationValidator
{
    public const CONTEXT_KEYS = ['schema_version', 'profile_id', 'profile_version', ...PublicVehicleCompatibility::KEYS, 'year', 'ram_gb', 'almacenamiento_gb'];

    public function __construct(private readonly ProductConfiguratorRegistry $registry) {}

    public function snapshot(Product $product, ?ProductVariant $variant, array $item): ?array
    {
        $registration = $this->registry->registration($product);
        if ($registration === null) {
            if (array_key_exists('configuration_context', $item)) {
                $this->invalid('Este producto no admite el perfil de configuración enviado.');
            }

            return null;
        }
        if (! $registration['purchase_enabled']) {
            $this->invalid('La compra de este producto configurable no está habilitada.');
        }
        $context = $item['configuration_context'] ?? null;
        if (! is_array($context) || array_diff(array_keys($context), self::CONTEXT_KEYS)
            || ($context['schema_version'] ?? null) !== 1
            || ($context['profile_id'] ?? null) !== $registration['profile_id']
            || ($context['profile_version'] ?? null) !== $registration['profile_version']) {
            $this->invalid('Completa nuevamente la configuración del producto; su contexto no es válido.');
        }
        if (! $product->is_active || ! $product->is_visible || ! $variant?->is_active || ! $variant->is_visible
            || $variant->product_id !== $product->id || ($item['product_variant_id'] ?? null) !== $variant->id) {
            $this->invalid('Selecciona explícitamente una referencia comercial válida para esta configuración.');
        }
        foreach (PublicVehicleCompatibility::KEYS as $key) {
            if (! is_int($context[$key] ?? null) || $context[$key] <= 0) {
                $this->invalid('Completa vehículo, generación y sistema OEM de la configuración.');
            }
        }
        $year = $context['year'] ?? null;
        if ($year !== null && (! is_int($year) || $year < 1900 || $year > 2100)) {
            $this->invalid('El año seleccionado no es válido.');
        }

        $rows = $variant->vehicleCompatibilities()->with(['vehicleBrand', 'vehicleModel', 'vehicleVersion', 'vehicleMultimediaSystem']);
        PublicVehicleCompatibility::matchRow($rows->getQuery(), new Request($context));
        $row = $rows->get()->first(fn ($row) => $row->vehicleBrand?->is_active
            && $row->vehicleModel?->is_active && $row->vehicleVersion?->is_active && $row->vehicleMultimediaSystem?->is_active
            && (int) $row->vehicleModel->vehicle_brand_id === $context['vehicle_brand_id']
            && (int) $row->vehicleVersion->vehicle_model_id === $context['vehicle_model_id']
            && (int) $row->vehicleMultimediaSystem->vehicle_brand_id === $context['vehicle_brand_id']);
        if (! $row) {
            $this->invalid('La referencia no es compatible con ese vehículo, año y sistema OEM en una misma compatibilidad.');
        }

        $variant->loadMissing('specValues.field');
        $memory = [];
        foreach (array_keys(VariantMemoryAttributes::FIELDS) as $key) {
            $values = $variant->specValues->filter(fn ($value) => $value->field?->field_key === $key);
            $value = $values->first();
            $field = $value?->field;
            if ($values->count() !== 1 || ! $field?->is_active || $field->scope !== 'variant' || $field->type !== 'number'
                || $field->filter_unit !== 'GB' || (int) $field->product_category_id !== (int) $product->category_id
                || ! VariantMemoryAttributes::valid($value->value_number)
                || ! is_int($context[$key] ?? null) || $context[$key] !== (int) $value->value_number) {
                $this->invalid('La RAM o el almacenamiento no corresponden a los atributos verificados de esta referencia.');
            }
            $memory[$key] = (int) $value->value_number;
        }

        // Canonical server-only snapshot: no client names, prices, SKU or private data.
        return [
            'schema_version' => 1, 'profile_id' => $registration['profile_id'], 'profile_version' => $registration['profile_version'],
            'vehicle_brand_id' => $row->vehicle_brand_id, 'vehicle_model_id' => $row->vehicle_model_id,
            'vehicle_version_id' => $row->vehicle_version_id, 'vehicle_multimedia_system_id' => $row->vehicle_multimedia_system_id,
            'year' => $year, ...$memory,
            'vehicle_brand_name' => $row->vehicleBrand->name, 'vehicle_model_name' => $row->vehicleModel->name,
            'vehicle_version_name' => $row->vehicleVersion->name, 'vehicle_multimedia_system_name' => $row->vehicleMultimediaSystem->name,
            'variant_id' => $variant->id, 'variant_sku' => $variant->sku, 'variant_name' => $variant->display_name,
        ];
    }

    /** Recheck mutable catalog before FIRST reservation; never rewrite historical snapshots. */
    public function revalidateOrder(Order $order): void
    {
        if ($order->origin !== Order::ORIGIN_ECOMMERCE) {
            return;
        }
        foreach ($order->items()->with(['product', 'productVariant.specValues.field'])->get() as $item) {
            $context = $item->configuration_context;
            if ($context === null && (! $item->product || $this->registry->registration($item->product) === null)) {
                continue;
            }
            if (! $item->product) {
                $this->invalid('El producto configurado ya no está disponible.');
            }
            $this->snapshot($item->product, $item->productVariant, [
                'product_variant_id' => $item->product_variant_id,
                'configuration_context' => is_array($context) ? array_intersect_key($context, array_flip(self::CONTEXT_KEYS)) : null,
            ]);
        }
    }

    /** Preflight only; definitive locked reservation stays in the existing payment flow. */
    public function validateAvailability(array $items, EcommerceFulfillmentBranchResolver $fulfillment): void
    {
        $configured = collect($items)->filter(fn ($item) => isset($item['configuration_context']));
        if ($configured->isEmpty()) {
            return;
        }
        $order = new Order;
        $order->setRelation('items', collect($items)->map(fn ($item) => new OrderItem($item)));
        $branches = $fulfillment->candidates($order)->modelKeys();
        foreach ($configured->groupBy('product_variant_id') as $variantId => $lines) {
            // Intersect with full-order fulfillment, including non-shipping configured SKUs.
            $available = InventoryStock::query()->whereIn('branch_id', $branches)
                ->whereHas('inventoryItem', fn ($q) => $q->where('product_variant_id', $variantId)->whereNull('product_id'))
                ->where('quantity', '>=', $lines->sum('quantity'))->pluck('branch_id')->all();
            $branches = array_values(array_intersect($branches, $available));
            if ($branches === []) {
                $this->invalid('No hay existencias suficientes de la referencia en una sede activa. Revisa cantidades y configuración.');
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
