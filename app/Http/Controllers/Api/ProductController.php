<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminProductResource;
use App\Http\Resources\PublicProductResource;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleModel;
use App\Models\VehicleVersion;
use App\Services\ProductPricingService;
use App\Services\ProductSpecValueService;
use App\Services\ProductVariantCompatibilityService;
use App\Services\ProductVariantSpecValueService;
use App\Support\Catalog\PublicProductStockQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductPricingService $pricingService,
        private readonly ProductSpecValueService $specValueService,
        private readonly ProductVariantSpecValueService $variantSpecValueService,
        private readonly ProductVariantCompatibilityService $variantCompatibilityService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->with($this->publicRelations())
            ->where('is_active', true)
            ->where('is_visible', true);
        PublicProductStockQuery::addProductAggregates($query);

        $this->applyProductFilters($query, $request, true);
        $this->applyProductSorting($query, $request, true);

        return response()->json([
            'data' => PublicProductResource::collection($query->get())->resolve($request),
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        if (! $product->is_active || ! $product->is_visible) {
            abort(404);
        }

        $query = Product::query()->whereKey($product->id);
        PublicProductStockQuery::addProductAggregates($query);
        $product = $query->with($this->publicRelations())->firstOrFail();

        return response()->json([
            'data' => (new PublicProductResource($product))->resolve($request),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'products.view');
        $query = Product::query()
            ->with($this->adminRelations())
            ->withCount('variants');

        $this->applyProductFilters($query, $request, false);
        $this->applyProductSorting($query, $request, false);

        return response()->json([
            'data' => AdminProductResource::collection($query->get())->resolve($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'products.create');
        $imageUpload = $this->validateProductImageUpload($request);
        $payload = $this->normalizeIncomingRequest($request);
        $validated = $this->validateProductData($payload);
        $storedImagePath = null;

        try {
            if ($imageUpload) {
                $storedImagePath = $this->storeProductImage($imageUpload);
                $validated['main_image'] = $storedImagePath;
            }

            $product = DB::transaction(function () use ($validated) {
                $product = Product::create(
                    $this->buildProductData($validated)
                );

                $this->specValueService->sync(
                    $product,
                    $validated['technical_specs'] ?? []
                );

                $compatibilities = $product->usesUniversalCompatibility()
                    ? []
                    : ($validated['vehicle_compatibilities'] ?? []);

                $this->syncVehicleCompatibilities(
                    $product,
                    $compatibilities
                );

                $this->syncVariants(
                    $product,
                    $validated['variants'] ?? []
                );

                return $product;
            });
        } catch (Throwable $exception) {
            if ($storedImagePath) {
                Storage::disk('public')->delete($storedImagePath);
            }

            throw $exception;
        }

        $product = $product->fresh($this->adminRelations());

        return response()->json([
            'message' => 'Artículo creado correctamente.',
            'data' => (new AdminProductResource($product))->resolve($request),
        ], 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizePermission($request, 'products.update');
        $imageUpload = $this->validateProductImageUpload($request);
        $payload = $this->normalizeIncomingRequest($request);
        $validated = $this->validateProductData($payload, $product);
        $storedImagePath = null;

        try {
            if ($imageUpload) {
                $storedImagePath = $this->storeProductImage($imageUpload);
                $validated['main_image'] = $storedImagePath;
            }

            $product = DB::transaction(function () use ($product, $validated) {
                $product->update(
                    $this->buildProductData($validated, $product)
                );

                if (array_key_exists('technical_specs', $validated)) {
                    $this->specValueService->sync(
                        $product,
                        $validated['technical_specs'] ?? []
                    );
                }

                if ($product->usesUniversalCompatibility()) {
                    $this->syncVehicleCompatibilities($product, []);
                } elseif (array_key_exists('vehicle_compatibilities', $validated)) {
                    $this->syncVehicleCompatibilities(
                        $product,
                        $validated['vehicle_compatibilities'] ?? []
                    );
                }

                if (array_key_exists('variants', $validated)) {
                    $this->syncVariants(
                        $product,
                        $validated['variants'] ?? []
                    );
                }

                return $product;
            });
        } catch (Throwable $exception) {
            if ($storedImagePath) {
                Storage::disk('public')->delete($storedImagePath);
            }

            throw $exception;
        }

        $product = $product->fresh($this->adminRelations());

        return response()->json([
            'message' => 'Artículo actualizado correctamente.',
            'data' => (new AdminProductResource($product))->resolve($request),
        ]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizePermission($request, 'products.delete');

        $hasInventoryHistory = $product->inventoryItem()->exists()
            || $product->inventoryMovements()->exists()
            || $product->variants()->where(function (Builder $query) {
                $query->whereHas('inventoryItem')
                    ->orWhereHas('inventoryMovements')
                    ->orWhereHas('inventoryTransferItems');
            })->exists();

        if ($hasInventoryHistory) {
            return response()->json([
                'message' => 'El artículo no puede eliminarse porque tiene historial de inventario.',
            ], 409);
        }

        $product->delete();

        return response()->json([
            'message' => 'Artículo eliminado correctamente.',
        ]);
    }

    private function publicRelations(): array
    {
        return [
            'productCategory.fields',
            'productBrand',
            'specValues.field',
            'vehicleCompatibilities.vehicleBrand',
            'vehicleCompatibilities.vehicleModel',
            'vehicleCompatibilities.vehicleVersion',
            'variants' => function ($query) {
                $query
                    ->where('is_active', true)
                    ->where('is_visible', true)
                    ->with([
                        'product',
                        'vehicleMultimediaSystem.vehicleBrand',
                        'specValues.field',
                        'vehicleCompatibilities.vehicleBrand',
                        'vehicleCompatibilities.vehicleModel',
                        'vehicleCompatibilities.vehicleVersion',
                        'vehicleCompatibilities.vehicleMultimediaSystem',
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id');

                PublicProductStockQuery::addVariantAggregates($query->getQuery());
            },
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }

    private function adminRelations(): array
    {
        return [
            'productCategory.fields',
            'productBrand',
            'specValues.field',
            'vehicleCompatibilities.vehicleBrand',
            'vehicleCompatibilities.vehicleModel',
            'vehicleCompatibilities.vehicleVersion',
            'variants.product',
            'variants.vehicleMultimediaSystem.vehicleBrand',
            'variants.specValues.field',
            'variants.vehicleCompatibilities.vehicleBrand',
            'variants.vehicleCompatibilities.vehicleModel',
            'variants.vehicleCompatibilities.vehicleVersion',
            'variants.vehicleCompatibilities.vehicleMultimediaSystem',
        ];
    }

    private function normalizeIncomingRequest(Request $request): array
    {
        $payload = $request->all();

        foreach (['main_image', 'image'] as $field) {
            if ($request->file($field) !== null) {
                unset($payload[$field]);
            }
        }

        foreach (
            ['technical_specs', 'vehicle_compatibilities', 'variants'] as $field
        ) {
            if (
                array_key_exists($field, $payload) &&
                is_string($payload[$field])
            ) {
                $decoded = json_decode($payload[$field], true);

                $payload[$field] = json_last_error() === JSON_ERROR_NONE
                    ? $decoded
                    : $payload[$field];
            }
        }

        foreach (['is_visible', 'is_featured', 'is_active', 'requires_shipping'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->parseBooleanValue(
                    $payload[$field]
                );
            }
        }

        if (array_key_exists('country_of_origin', $payload) && is_string($payload['country_of_origin'])) {
            $country = Str::upper(trim($payload['country_of_origin']));
            $payload['country_of_origin'] = $country !== '' ? $country : null;
        }

        if (array_key_exists('commission_enabled', $payload)) {
            $normalizedCommissionEnabled = filter_var(
                $payload['commission_enabled'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($normalizedCommissionEnabled !== null) {
                $payload['commission_enabled'] = $normalizedCommissionEnabled;
            }
        }

        if (
            array_key_exists('compatibility_type', $payload) &&
            is_string($payload['compatibility_type'])
        ) {
            $compatibilityType = trim($payload['compatibility_type']);

            $payload['compatibility_type'] = $compatibilityType !== ''
                ? $compatibilityType
                : null;
        }

        if (
            array_key_exists('variants', $payload) &&
            is_array($payload['variants'])
        ) {
            $payload['variants'] = array_map(
                function (array $variant) {
                    foreach (
                        ['is_default', 'is_visible', 'is_active'] as $field
                    ) {
                        if (array_key_exists($field, $variant)) {
                            $variant[$field] = $this->parseBooleanValue(
                                $variant[$field]
                            );
                        }
                    }

                    if (array_key_exists('country_of_origin', $variant) && is_string($variant['country_of_origin'])) {
                        $country = Str::upper(trim($variant['country_of_origin']));
                        $variant['country_of_origin'] = $country !== '' ? $country : null;
                    }

                    return $variant;
                },
                $payload['variants']
            );
        }

        return $payload;
    }

    private function validateProductImageUpload(Request $request): ?UploadedFile
    {
        $uploads = collect(['main_image', 'image'])
            ->mapWithKeys(fn (string $field): array => [
                $field => $request->file($field),
            ])
            ->filter(fn ($file): bool => $file !== null);

        if ($uploads->count() > 1) {
            throw ValidationException::withMessages([
                'main_image' => 'Envía una sola imagen principal.',
            ]);
        }

        if ($uploads->isEmpty()) {
            return null;
        }

        $field = (string) $uploads->keys()->first();
        $upload = $uploads->first();

        Validator::make(
            [$field => $upload],
            [
                $field => [
                    'bail',
                    'required',
                    'file',
                    'image',
                    'mimetypes:image/jpeg,image/png,image/webp',
                    'extensions:jpg,jpeg,png,webp',
                    'max:5120',
                ],
            ],
            [
                "{$field}.mimetypes" => 'La imagen debe ser JPEG, PNG o WebP.',
                "{$field}.extensions" => 'La extensión debe ser jpg, jpeg, png o webp.',
                "{$field}.max" => 'La imagen no puede superar 5 MB.',
            ]
        )->validate();

        return $upload;
    }

    private function storeProductImage(UploadedFile $image): string
    {
        $path = $image->store('products', 'public');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The product image could not be stored.');
        }

        return $path;
    }

    private function validateProductData(
        array $payload,
        ?Product $product = null
    ): array {
        $nameRule = $product ? 'sometimes' : 'required';

        if (
            ! array_key_exists('compatibility_type', $payload) &&
            empty($product?->compatibility_type) &&
            ! empty($payload['vehicle_compatibilities']) &&
            is_array($payload['vehicle_compatibilities'])
        ) {
            $payload['compatibility_type'] =
                Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC;
        }

        $effectiveCompatibilityType =
            $payload['compatibility_type']
            ?? $product?->compatibility_type;

        if (
            $effectiveCompatibilityType ===
            Product::COMPATIBILITY_TYPE_UNIVERSAL
        ) {
            $payload['vehicle_compatibilities'] = [];
        }

        $validator = Validator::make($payload, [
            'name' => [$nameRule, 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:120'],
            'category_id' => [
                'nullable',
                'integer',
                'exists:product_categories,id',
            ],
            'product_brand_id' => [
                'nullable',
                'integer',
                'exists:product_brands,id',
            ],
            'compatibility_type' => [
                'nullable',
                Rule::in([
                    Product::COMPATIBILITY_TYPE_UNIVERSAL,
                    Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC,
                ]),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('products', 'sku')->ignore($product?->id),
            ],

            'price' => ['nullable', 'integer', 'min:0'],
            'cost_price' => ['nullable', 'integer', 'min:0'],
            'tax_amount' => ['nullable', 'integer', 'min:0'],
            'extra_charges' => ['nullable', 'integer', 'min:0'],
            'pricing_mode' => [
                'nullable',
                Rule::in([
                    Product::PRICING_MODE_MANUAL,
                    Product::PRICING_MODE_MARKUP,
                    Product::PRICING_MODE_MARGIN,
                ]),
            ],
            'target_profit_percent' => [
                'nullable',
                'numeric',
                'min:0',
                'max:99.99',
            ],
            'commission_enabled' => ['nullable', 'boolean'],
            'commission_amount' => ['nullable', 'integer', 'min:1'],

            'requires_shipping' => ['nullable', 'boolean'],
            'weight_grams' => ['nullable', 'integer', 'min:1'],
            'length_mm' => ['nullable', 'integer', 'min:1'],
            'width_mm' => ['nullable', 'integer', 'min:1'],
            'height_mm' => ['nullable', 'integer', 'min:1'],
            'country_of_origin' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'hs_code' => ['nullable', 'string', 'max:32'],
            'customs_description' => ['nullable', 'string', 'max:500'],

            'technical_specs' => ['nullable', 'array'],
            'main_image' => ['nullable', 'string', 'max:2048'],

            'is_visible' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],

            'vehicle_compatibilities' => ['nullable', 'array'],
            'vehicle_compatibilities.*.vehicle_brand_id' => [
                'required',
                'integer',
                'exists:vehicle_brands,id',
            ],
            'vehicle_compatibilities.*.vehicle_model_id' => [
                'nullable',
                'integer',
                'exists:vehicle_models,id',
            ],
            'vehicle_compatibilities.*.vehicle_version_id' => [
                'nullable',
                'integer',
                'exists:vehicle_versions,id',
            ],
            'vehicle_compatibilities.*.notes' => [
                'nullable',
                'string',
            ],

            'variants' => ['nullable', 'array'],
            'variants.*.id' => [
                'nullable',
                'integer',
                'exists:product_variants,id',
            ],
            'variants.*.vehicle_multimedia_system_id' => [
                'nullable',
                'integer',
                'exists:vehicle_multimedia_systems,id',
            ],
            'variants.*.compatibility_type' => [
                'nullable',
                Rule::in([
                    Product::COMPATIBILITY_TYPE_UNIVERSAL,
                    Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC,
                ]),
            ],
            'variants.*.name' => [
                'required',
                'string',
                'max:180',
            ],
            'variants.*.sku' => [
                'nullable',
                'string',
                'max:120',
            ],
            'variants.*.attributes' => [
                'nullable',
                'array',
            ],
            'variants.*.specs' => ['nullable', 'array'],
            'variants.*.vehicle_compatibilities' => ['nullable', 'array'],
            'variants.*.vehicle_compatibilities.*.vehicle_brand_id' => [
                'required', 'integer', 'exists:vehicle_brands,id',
            ],
            'variants.*.vehicle_compatibilities.*.vehicle_model_id' => [
                'nullable', 'integer', 'exists:vehicle_models,id',
            ],
            'variants.*.vehicle_compatibilities.*.vehicle_version_id' => [
                'nullable', 'integer', 'exists:vehicle_versions,id',
            ],
            'variants.*.vehicle_compatibilities.*.vehicle_multimedia_system_id' => [
                'nullable', 'integer', 'exists:vehicle_multimedia_systems,id',
            ],
            'variants.*.vehicle_compatibilities.*.year_from' => [
                'nullable', 'integer', 'between:1900,2100',
            ],
            'variants.*.vehicle_compatibilities.*.year_to' => [
                'nullable', 'integer', 'between:1900,2100',
            ],
            'variants.*.vehicle_compatibilities.*.notes' => ['nullable', 'string'],

            'variants.*.cost_price' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'variants.*.tax_amount' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'variants.*.extra_charges' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'variants.*.price' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'variants.*.pricing_mode' => [
                'nullable',
                Rule::in([
                    Product::PRICING_MODE_MANUAL,
                    Product::PRICING_MODE_MARKUP,
                    Product::PRICING_MODE_MARGIN,
                ]),
            ],
            'variants.*.target_profit_percent' => [
                'nullable',
                'numeric',
                'min:0',
                'max:99.99',
            ],
            'variants.*.weight_grams' => ['nullable', 'integer', 'min:1'],
            'variants.*.length_mm' => ['nullable', 'integer', 'min:1'],
            'variants.*.width_mm' => ['nullable', 'integer', 'min:1'],
            'variants.*.height_mm' => ['nullable', 'integer', 'min:1'],
            'variants.*.country_of_origin' => ['nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'variants.*.hs_code' => ['nullable', 'string', 'max:32'],
            'variants.*.customs_description' => ['nullable', 'string', 'max:500'],

            'variants.*.main_image' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'variants.*.is_default' => [
                'nullable',
                'boolean',
            ],
            'variants.*.is_active' => [
                'nullable',
                'boolean',
            ],
            'variants.*.is_visible' => [
                'nullable',
                'boolean',
            ],
            'variants.*.sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        $validated = $validator->validate();

        $commissionEnabled = array_key_exists('commission_enabled', $validated)
            ? (bool) $validated['commission_enabled']
            : (bool) ($product?->commission_enabled ?? false);
        $commissionAmount = array_key_exists('commission_amount', $validated)
            ? $validated['commission_amount']
            : $product?->commission_amount;

        if ($commissionEnabled && $commissionAmount === null) {
            throw ValidationException::withMessages([
                'commission_amount' => 'El valor de la comisión es obligatorio cuando el artículo genera comisión.',
            ]);
        }

        $this->validateCompatibilitySelection(
            $validated,
            $product
        );

        $this->validateVehicleIntegrity(
            $validated['vehicle_compatibilities'] ?? []
        );

        $this->validateVariantSkus(
            $validated['variants'] ?? []
        );

        foreach ($validated['variants'] ?? [] as $index => $variant) {
            if (($variant['compatibility_type'] ?? null) !== Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC) {
                continue;
            }

            if (! array_key_exists('vehicle_compatibilities', $variant) && ! empty($variant['id'])) {
                continue;
            }

            $this->variantCompatibilityService->validateSelection(
                $variant['compatibility_type'],
                $variant['vehicle_compatibilities'] ?? [],
                "variants.{$index}.vehicle_compatibilities"
            );
        }

        return $validated;
    }

    private function validateCompatibilitySelection(
        array $validated,
        ?Product $product = null
    ): void {
        $compatibilityType =
            $validated['compatibility_type']
            ?? $product?->compatibility_type;

        if (
            $compatibilityType !==
            Product::COMPATIBILITY_TYPE_VEHICLE_SPECIFIC
        ) {
            return;
        }

        if (array_key_exists('vehicle_compatibilities', $validated)) {
            $hasCompatibility =
                count($validated['vehicle_compatibilities'] ?? []) > 0;
        } else {
            $hasCompatibility = $product
                ? $product->vehicleCompatibilities()->exists()
                : false;
        }

        if ($hasCompatibility) {
            return;
        }

        throw ValidationException::withMessages([
            'vehicle_compatibilities' => 'Selecciona al menos un vehículo compatible para este artículo.',
        ]);
    }

    private function buildProductData(
        array $validated,
        ?Product $product = null
    ): array {
        $defaults = [
            'name' => '',
            'description' => null,
            'category' => null,
            'category_id' => null,
            'product_brand_id' => null,
            'compatibility_type' => null,
            'sku' => null,
            'technical_specs' => [],
            'main_image' => null,
            'is_visible' => true,
            'is_featured' => false,
            'is_active' => true,
            'commission_enabled' => false,
            'commission_amount' => null,
            'requires_shipping' => true,
            'weight_grams' => null,
            'length_mm' => null,
            'width_mm' => null,
            'height_mm' => null,
            'country_of_origin' => null,
            'hs_code' => null,
            'customs_description' => null,
        ];

        $current = $product
            ? Arr::only(
                $product->toArray(),
                array_keys($defaults)
            )
            : [];

        if ($product) {
            $current['commission_enabled'] = (bool) $product->commission_enabled;
            $current['commission_amount'] = $product->commissionAmount();
        }

        $data = array_merge(
            $defaults,
            $current,
            Arr::only(
                $validated,
                array_keys($defaults)
            )
        );

        $data['name'] = trim((string) $data['name']);
        $data['normalized_name'] = $this->normalizeName(
            $data['name']
        );
        $data['description'] = $this->nullableTrim(
            $data['description']
        );
        $data['category'] = $this->nullableTrim(
            $data['category']
        );
        $data['sku'] = $this->nullableTrim(
            $data['sku']
        );
        $data['technical_specs'] =
            $data['technical_specs'] ?: [];
        $data['requires_shipping'] = (bool) $data['requires_shipping'];
        $data['country_of_origin'] = $this->nullableTrim($data['country_of_origin']);
        $data['hs_code'] = $this->nullableTrim($data['hs_code']);
        $data['customs_description'] = $this->nullableTrim($data['customs_description']);
        $data['commission_enabled'] = (bool) $data['commission_enabled'];
        $data['commission_amount'] = $data['commission_enabled']
            ? (int) $data['commission_amount']
            : null;

        if (! $product || $product->name !== $data['name']) {
            $data['slug'] = $this->generateUniqueSlug(
                $data['name'],
                $product?->id
            );
        }

        $pricingInput = [
            'price' => $validated['price']
                ?? $product?->price
                ?? 0,
            'cost_price' => $validated['cost_price']
                ?? $product?->cost_price
                ?? 0,
            'tax_amount' => $validated['tax_amount']
                ?? $product?->tax_amount
                ?? 0,
            'extra_charges' => $validated['extra_charges']
                ?? $product?->extra_charges
                ?? 0,
            'pricing_mode' => $validated['pricing_mode']
                ?? $product?->pricing_mode
                ?? Product::PRICING_MODE_MANUAL,
            'target_profit_percent' => $validated['target_profit_percent']
                ?? $product?->target_profit_percent
                ?? null,
        ];

        return array_merge(
            $data,
            $this->pricingService->calculate($pricingInput)
        );
    }

    private function syncVehicleCompatibilities(
        Product $product,
        array $compatibilities
    ): void {
        $product->vehicleCompatibilities()->delete();

        foreach ($compatibilities as $compatibility) {
            if (! isset($compatibility['vehicle_brand_id'])) {
                continue;
            }

            $product->vehicleCompatibilities()->create([
                'vehicle_brand_id' => $compatibility['vehicle_brand_id'],
                'vehicle_model_id' => $compatibility['vehicle_model_id'] ?? null,
                'vehicle_version_id' => $compatibility['vehicle_version_id'] ?? null,
                'notes' => $this->nullableTrim(
                    $compatibility['notes'] ?? null
                ),
            ]);
        }
    }

    private function syncVariants(
        Product $product,
        array $variants
    ): void {
        if (count($variants) > 0 && $product->inventoryItem()->exists()) {
            throw ValidationException::withMessages([
                'variants' => 'No puedes convertir en variantes un artículo que ya tiene inventario por sede.',
            ]);
        }

        $variantIds = collect($variants)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $variantsToDelete = $product->variants()
            ->when(
                count($variantIds) > 0,
                fn (Builder $query) => $query->whereNotIn('id', $variantIds)
            );

        if ((clone $variantsToDelete)->where(function (Builder $query) {
            $query->whereHas('inventoryItem')
                ->orWhereHas('inventoryMovements')
                ->orWhereHas('inventoryTransferItems');
        })->exists()) {
            throw ValidationException::withMessages([
                'variants' => 'No puedes eliminar una variante que tiene historial de inventario.',
            ]);
        }

        $variantsToDelete->delete();

        if (count($variants) === 0) {
            return;
        }

        $defaultIndex = collect($variants)->search(
            fn ($variant) => $this->parseBooleanValue(
                $variant['is_default'] ?? false
            )
        );

        if ($defaultIndex === false) {
            $defaultIndex = 0;
        }

        foreach ($variants as $index => $variantData) {
            $name = trim((string) $variantData['name']);

            $pricingInput = [
                'price' => $variantData['price'] ?? 0,
                'cost_price' => $variantData['cost_price'] ?? 0,
                'tax_amount' => $variantData['tax_amount'] ?? 0,
                'extra_charges' => $variantData['extra_charges'] ?? 0,
                'pricing_mode' => $variantData['pricing_mode']
                    ?? Product::PRICING_MODE_MANUAL,
                'target_profit_percent' => $variantData['target_profit_percent'] ?? null,
            ];

            $payload = array_merge(
                [
                    'vehicle_multimedia_system_id' => $variantData[
                            'vehicle_multimedia_system_id'
                        ] ?? null,
                    'name' => $name,
                    'normalized_name' => $this->normalizeName($name),
                    'sku' => $this->nullableTrim(
                        $variantData['sku'] ?? null
                    ),
                    'attributes' => $this->normalizeVariantAttributes(
                        $variantData['attributes'] ?? []
                    ),
                    'weight_grams' => $variantData['weight_grams'] ?? null,
                    'length_mm' => $variantData['length_mm'] ?? null,
                    'width_mm' => $variantData['width_mm'] ?? null,
                    'height_mm' => $variantData['height_mm'] ?? null,
                    'country_of_origin' => $this->nullableTrim($variantData['country_of_origin'] ?? null),
                    'hs_code' => $this->nullableTrim($variantData['hs_code'] ?? null),
                    'customs_description' => $this->nullableTrim($variantData['customs_description'] ?? null),

                    'main_image' => $this->nullableTrim(
                        $variantData['main_image'] ?? null
                    ),

                    'is_default' => (int) $index === (int) $defaultIndex,
                    'is_active' => $this->parseBooleanValue(
                        $variantData['is_active'] ?? true
                    ),
                    'is_visible' => $this->parseBooleanValue(
                        $variantData['is_visible'] ?? true
                    ),
                    'sort_order' => (int) (
                        $variantData['sort_order'] ?? $index
                    ),
                ],
                $this->pricingService->calculate($pricingInput)
            );

            if (array_key_exists('compatibility_type', $variantData)) {
                $payload['compatibility_type'] = $variantData['compatibility_type'];
            }

            if (! empty($variantData['id'])) {
                $variant = $product->variants()
                    ->whereKey($variantData['id'])
                    ->first();

                if (! $variant) {
                    throw ValidationException::withMessages([
                        'variants' => 'Una de las variantes no pertenece a este artículo.',
                    ]);
                }

                $variant->update($payload);

                $this->syncVariantDetails($variant, $variantData);

                continue;
            }

            $variant = $product->variants()->create($payload);
            $this->syncVariantDetails($variant, $variantData);
        }
    }

    private function syncVariantDetails(ProductVariant $variant, array $variantData): void
    {
        if (array_key_exists('specs', $variantData)) {
            $this->variantSpecValueService->sync($variant, $variantData['specs'] ?? []);
        }

        if (! array_key_exists('vehicle_compatibilities', $variantData)
            && ! array_key_exists('compatibility_type', $variantData)) {
            return;
        }

        $compatibilityType = $variantData['compatibility_type']
            ?? $variant->compatibility_type;

        if (! array_key_exists('vehicle_compatibilities', $variantData)) {
            if ($compatibilityType === Product::COMPATIBILITY_TYPE_UNIVERSAL) {
                $this->variantCompatibilityService->sync($variant, []);

                return;
            }

            $this->variantCompatibilityService->validateSelection(
                $compatibilityType,
                $variant->vehicleCompatibilities()->get()->toArray(),
                'variants.vehicle_compatibilities'
            );

            return;
        }

        $compatibilities = $compatibilityType === Product::COMPATIBILITY_TYPE_UNIVERSAL
            ? []
            : ($variantData['vehicle_compatibilities'] ?? []);

        $this->variantCompatibilityService->validateSelection(
            $compatibilityType,
            $compatibilities,
            'variants.vehicle_compatibilities'
        );
        $this->variantCompatibilityService->sync($variant, $compatibilities);
    }

    private function applyProductFilters(
        Builder $query,
        Request $request,
        bool $public
    ): void {
        if ($request->filled('search')) {
            $search = trim(
                (string) $request->input('search')
            );

            $query->where(
                function (Builder $subQuery) use ($search) {
                    $subQuery
                        ->where(
                            'name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'sku',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'category',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'productBrand',
                            function (
                                Builder $brandQuery
                            ) use ($search) {
                                $brandQuery->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                );
                            }
                        )
                        ->orWhereHas(
                            'variants',
                            function (
                                Builder $variantQuery
                            ) use ($search) {
                                $variantQuery
                                    ->where(
                                        'name',
                                        'like',
                                        "%{$search}%"
                                    )
                                    ->orWhere(
                                        'sku',
                                        'like',
                                        "%{$search}%"
                                    );
                            }
                        );
                }
            );
        }

        if ($request->filled('category_id')) {
            $query->where(
                'category_id',
                $request->integer('category_id')
            );
        }

        if ($request->filled('product_brand_id')) {
            $query->where(
                'product_brand_id',
                $request->integer('product_brand_id')
            );
        }

        if ($request->filled('compatibility_type')) {
            $query->where(
                'compatibility_type',
                $request->input('compatibility_type')
            );
        }

        if (! $public && $request->filled('is_active')) {
            $query->where(
                'is_active',
                $this->parseBooleanValue(
                    $request->input('is_active')
                )
            );
        }

        if ($request->filled('is_visible')) {
            $query->where(
                'is_visible',
                $this->parseBooleanValue(
                    $request->input('is_visible')
                )
            );
        }

        if ($request->filled('is_featured')) {
            $query->where(
                'is_featured',
                $this->parseBooleanValue(
                    $request->input('is_featured')
                )
            );
        }

        if ($request->filled('featured')) {
            $query->where(
                'is_featured',
                $this->parseBooleanValue(
                    $request->input('featured')
                )
            );
        }

        if (
            $public &&
            $request->filled('in_stock') &&
            $this->parseBooleanValue(
                $request->input('in_stock')
            )
        ) {
            PublicProductStockQuery::applyInStockFilter($query);
        }

        if (
            $request->filled('min_price') ||
            $request->filled('max_price')
        ) {
            $minPrice = $request->filled('min_price')
                ? (int) $request->input('min_price')
                : null;

            $maxPrice = $request->filled('max_price')
                ? (int) $request->input('max_price')
                : null;

            $query->where(
                function (
                    Builder $priceQuery
                ) use ($minPrice, $maxPrice, $public) {
                    $priceQuery
                        ->where(
                            function (
                                Builder $productPriceQuery
                            ) use ($minPrice, $maxPrice) {
                                if ($minPrice !== null) {
                                    $productPriceQuery->where(
                                        'price',
                                        '>=',
                                        $minPrice
                                    );
                                }

                                if ($maxPrice !== null) {
                                    $productPriceQuery->where(
                                        'price',
                                        '<=',
                                        $maxPrice
                                    );
                                }
                            }
                        )
                        ->orWhereHas(
                            'variants',
                            function (
                                Builder $variantQuery
                            ) use ($minPrice, $maxPrice, $public) {
                                if ($public) {
                                    $variantQuery
                                        ->where('is_active', true)
                                        ->where('is_visible', true);
                                }

                                if ($minPrice !== null) {
                                    $variantQuery->where(
                                        'price',
                                        '>=',
                                        $minPrice
                                    );
                                }

                                if ($maxPrice !== null) {
                                    $variantQuery->where(
                                        'price',
                                        '<=',
                                        $maxPrice
                                    );
                                }
                            }
                        );
                }
            );
        }

        if ($request->filled('vehicle_multimedia_system_id')) {
            $query->whereHas('variants', function (Builder $variantQuery) use ($request, $public) {
                $systemId = $request->integer('vehicle_multimedia_system_id');

                $variantQuery->where(function (Builder $systemQuery) use ($systemId) {
                    $systemQuery
                        ->where('vehicle_multimedia_system_id', $systemId)
                        ->orWhereHas('vehicleCompatibilities', function (Builder $compatibilityQuery) use ($systemId) {
                            $compatibilityQuery->where('vehicle_multimedia_system_id', $systemId);
                        });
                });

                if ($public) {
                    $variantQuery
                        ->where('is_active', true)
                        ->where('is_visible', true);
                }
            });
        }

        if (
            $request->filled('vehicle_brand_id') ||
            $request->filled('vehicle_model_id') ||
            $request->filled('vehicle_version_id') ||
            $request->filled('year')
        ) {
            $this->applyVehicleCompatibilityFilters(
                $query,
                $request
            );
        }

        $this->applySpecFilters($query, $request);
    }

    private function applyVehicleCompatibilityFilters(
        Builder $query,
        Request $request
    ): void {
        $query->where(function (Builder $compatibilityRoot) use ($request) {
            $compatibilityRoot
                ->where('compatibility_type', Product::COMPATIBILITY_TYPE_UNIVERSAL)
                ->orWhereHas('vehicleCompatibilities', function (Builder $compatibilityQuery) use ($request) {
                    $this->applyCompatibilityRowFilters($compatibilityQuery, $request, false);
                })
                ->orWhereHas('variants', function (Builder $variantQuery) use ($request) {
                    $variantQuery
                        ->where('is_active', true)
                        ->where('is_visible', true)
                        ->where(function (Builder $variantCompatibilityQuery) use ($request) {
                            $variantCompatibilityQuery
                                ->where('compatibility_type', Product::COMPATIBILITY_TYPE_UNIVERSAL)
                                ->orWhereHas('vehicleCompatibilities', function (Builder $compatibilityQuery) use ($request) {
                                    $this->applyCompatibilityRowFilters($compatibilityQuery, $request, true);
                                });
                        });
                });
        });
    }

    private function applyCompatibilityRowFilters(
        Builder $query,
        Request $request,
        bool $supportsYearOverrides
    ): void {
        foreach (['vehicle_brand_id', 'vehicle_model_id', 'vehicle_version_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->integer($column));
            }
        }

        if (! $request->filled('year')) {
            return;
        }

        $year = $request->integer('year');

        $query->where(function (Builder $yearQuery) use ($year, $supportsYearOverrides) {
            if ($supportsYearOverrides) {
                $yearQuery->where(function (Builder $overrideQuery) use ($year) {
                    $overrideQuery
                        ->where(function (Builder $hasOverrideQuery) {
                            $hasOverrideQuery
                                ->whereNotNull('year_from')
                                ->orWhereNotNull('year_to');
                        })
                        ->where(function (Builder $fromQuery) use ($year) {
                            $fromQuery->whereNull('year_from')->orWhere('year_from', '<=', $year);
                        })
                        ->where(function (Builder $rangeQuery) use ($year) {
                            $rangeQuery->whereNull('year_to')->orWhere('year_to', '>=', $year);
                        });
                })->orWhere(function (Builder $naturalRangeQuery) use ($year) {
                    $naturalRangeQuery
                        ->whereNull('year_from')
                        ->whereNull('year_to')
                        ->where(function (Builder $versionOrGlobalQuery) use ($year) {
                            $this->applyNaturalVersionYearFilter($versionOrGlobalQuery, $year);
                        });
                });

                return;
            }

            $this->applyNaturalVersionYearFilter($yearQuery, $year);
        });
    }

    private function applyNaturalVersionYearFilter(Builder $query, int $year): void
    {
        $query
            ->whereNull('vehicle_version_id')
            ->orWhereHas('vehicleVersion', function (Builder $versionQuery) use ($year) {
                $versionQuery
                    ->where('year_from', '<=', $year)
                    ->where(function (Builder $rangeQuery) use ($year) {
                        $rangeQuery->whereNull('year_to')->orWhere('year_to', '>=', $year);
                    });
            });
    }

    private function applySpecFilters(
        Builder $query,
        Request $request
    ): void {
        $specs = $request->input(
            'specs',
            $request->input('technical_specs', [])
        );

        if (is_string($specs)) {
            $decoded = json_decode($specs, true);

            $specs = json_last_error() === JSON_ERROR_NONE
                ? $decoded
                : [];
        }

        if (! is_array($specs)) {
            return;
        }

        foreach ($specs as $fieldKey => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $query->whereHas(
                'specValues',
                function (
                    Builder $specQuery
                ) use ($fieldKey, $value) {
                    $specQuery->whereHas(
                        'field',
                        function (
                            Builder $fieldQuery
                        ) use ($fieldKey) {
                            $fieldQuery
                                ->where(
                                    'field_key',
                                    $fieldKey
                                )
                                ->where(
                                    'is_filterable',
                                    true
                                );
                        }
                    );

                    $this->applySpecValueCondition(
                        $specQuery,
                        $value
                    );
                }
            );
        }
    }

    private function applySpecValueCondition(
        Builder $query,
        mixed $value
    ): void {
        if (is_array($value)) {
            $values = collect($value)
                ->filter(
                    fn ($item) => $item !== null &&
                        $item !== ''
                )
                ->values();

            if ($values->isEmpty()) {
                return;
            }

            $query->where(
                function (
                    Builder $valueQuery
                ) use ($values) {
                    $valueQuery
                        ->whereIn(
                            'value_text',
                            $values
                                ->map(
                                    fn ($item) => (string) $item
                                )
                                ->all()
                        )
                        ->orWhereIn(
                            'value_number',
                            $values
                                ->filter(
                                    fn ($item) => is_numeric($item)
                                )
                                ->all()
                        );
                }
            );

            return;
        }

        if (
            is_bool($value) ||
            in_array(
                $value,
                ['1', '0', 1, 0, 'true', 'false'],
                true
            )
        ) {
            $query->where(
                'value_boolean',
                $this->parseBooleanValue($value)
            );

            return;
        }

        if (is_numeric($value)) {
            $query->where(
                function (
                    Builder $valueQuery
                ) use ($value) {
                    $valueQuery
                        ->where(
                            'value_number',
                            (float) $value
                        )
                        ->orWhere(
                            'value_text',
                            (string) $value
                        );
                }
            );

            return;
        }

        $query->where(
            'value_text',
            'like',
            '%'.trim((string) $value).'%'
        );
    }

    private function applyProductSorting(
        Builder $query,
        Request $request,
        bool $public
    ): void {
        $sort = (string) $request->input(
            'sort',
            'featured'
        );

        if ($sort === 'stock' && $public) {
            PublicProductStockQuery::applyStockSort($query);

            return;
        }

        match ($sort) {
            'price_asc' => $query->orderByRaw(
                $this->variantPriceSubquery($public).' asc'
            ),
            'price_desc' => $query->orderByRaw(
                $this->variantPriceSubquery($public).' desc'
            ),
            'name' => $query->orderBy('name'),
            'newest' => $query->latest(),
            default => $query
                ->orderByDesc('is_featured')
                ->latest(),
        };
    }

    private function variantPriceSubquery(bool $public): string
    {
        $visibilityCondition = $public
            ? 'AND product_variants.is_active = 1 AND product_variants.is_visible = 1'
            : '';

        return "COALESCE(
            (
                SELECT MIN(product_variants.price)
                FROM product_variants
                WHERE product_variants.product_id = products.id
                {$visibilityCondition}
                AND product_variants.price > 0
            ),
            products.price
        )";
    }

    private function validateVehicleIntegrity(
        array $compatibilities
    ): void {
        foreach (
            $compatibilities as $index => $compatibility
        ) {
            $vehicleBrandId = (int) (
                $compatibility['vehicle_brand_id'] ?? 0
            );

            $vehicleModelId =
                $compatibility['vehicle_model_id'] ?? null;

            $vehicleVersionId =
                $compatibility['vehicle_version_id'] ?? null;

            if ($vehicleModelId) {
                $model = VehicleModel::find(
                    $vehicleModelId
                );

                if (
                    $model &&
                    (int) $model->vehicle_brand_id !==
                    $vehicleBrandId
                ) {
                    throw ValidationException::withMessages([
                        "vehicle_compatibilities.{$index}.vehicle_model_id" => 'El modelo seleccionado no pertenece a la marca del vehículo.',
                    ]);
                }
            }

            if (
                $vehicleVersionId &&
                ! $vehicleModelId
            ) {
                throw ValidationException::withMessages([
                    "vehicle_compatibilities.{$index}.vehicle_version_id" => 'Para seleccionar una generación debes seleccionar primero el modelo.',
                ]);
            }

            if ($vehicleVersionId) {
                $version = VehicleVersion::find(
                    $vehicleVersionId
                );

                if (
                    $version &&
                    (int) $version->vehicle_model_id !==
                    (int) $vehicleModelId
                ) {
                    throw ValidationException::withMessages([
                        "vehicle_compatibilities.{$index}.vehicle_version_id" => 'La generación seleccionada no pertenece al modelo del vehículo.',
                    ]);
                }
            }
        }
    }

    private function validateVariantSkus(
        array $variants
    ): void {
        $seen = [];

        foreach ($variants as $index => $variant) {
            $sku = $this->nullableTrim(
                $variant['sku'] ?? null
            );

            if (! $sku) {
                continue;
            }

            $normalizedSku = Str::lower($sku);

            if (isset($seen[$normalizedSku])) {
                throw ValidationException::withMessages([
                    "variants.{$index}.sku" => "El SKU {$sku} está duplicado en las versiones.",
                ]);
            }

            $seen[$normalizedSku] = true;

            $exists = ProductVariant::query()
                ->where('sku', $sku)
                ->when(
                    ! empty($variant['id']),
                    fn (Builder $query) => $query->whereKeyNot(
                        $variant['id']
                    )
                )
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    "variants.{$index}.sku" => "El SKU {$sku} ya está usado por otra versión.",
                ]);
            }
        }
    }

    private function normalizeVariantAttributes(
        mixed $attributes
    ): ?array {
        if (! is_array($attributes)) {
            return null;
        }

        $normalized = [];

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return count($normalized) > 0
            ? $normalized
            : null;
    }

    private function generateUniqueSlug(
        string $name,
        ?int $ignoreProductId = null
    ): string {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (
            Product::query()
                ->where('slug', $slug)
                ->when(
                    $ignoreProductId,
                    fn (Builder $query) => $query->whereKeyNot(
                        $ignoreProductId
                    )
                )
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function normalizeName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->ascii()
            ->squish()
            ->toString();
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== ''
            ? $value
            : null;
    }

    private function parseBooleanValue(mixed $value): bool
    {
        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? false;
    }
}
