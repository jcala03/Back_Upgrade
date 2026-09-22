<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCategoryField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ProductCategory::query()
            ->with([
                'fields' => function ($query) {
                    $query
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
            ])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'products.view');

        $categories = ProductCategory::query()
            ->with([
                'fields' => function ($query) {
                    $query
                        ->orderBy('sort_order')
                        ->orderBy('id');
                },
            ])
            ->latest()
            ->get();

        return response()->json([
            'data' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'products.create');

        $validated = $this->validateCategory($request);

        $category = ProductCategory::create([
            'name' => $validated['name'],
            'slug' => $this->generateUniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->syncFields($category, $validated['fields'] ?? null);

        return response()->json([
            'message' => 'Categoría creada correctamente.',
            'data' => $category->fresh()->load('fields'),
        ], 201);
    }

    public function update(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $this->authorizePermission($request, 'products.update');

        $validated = $this->validateCategory($request, $productCategory);

        $newSlug = $productCategory->name !== $validated['name']
            ? $this->generateUniqueSlug($validated['name'], $productCategory->id)
            : $productCategory->slug;

        $productCategory->update([
            'name' => $validated['name'],
            'slug' => $newSlug,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->syncFields($productCategory, $validated['fields'] ?? null);

        return response()->json([
            'message' => 'Categoría actualizada correctamente.',
            'data' => $productCategory->fresh()->load('fields'),
        ]);
    }

    public function destroy(Request $request, ProductCategory $productCategory): JsonResponse
    {
        $this->authorizePermission($request, 'products.delete');

        $productsCount = Product::query()
            ->where('category_id', $productCategory->id)
            ->count();

        if ($productsCount > 0) {
            $productCategory->update([
                'is_active' => false,
            ]);

            return response()->json([
                'message' => "La categoría tiene {$productsCount} producto(s) asociados. No se eliminó físicamente; quedó desactivada para proteger inventario y tienda.",
                'action' => 'deactivated',
                'data' => $productCategory->fresh()->load('fields'),
            ]);
        }

        DB::transaction(function () use ($productCategory) {
            $productCategory->fields()->delete();
            $productCategory->delete();
        });

        return response()->json([
            'message' => 'Categoría eliminada correctamente.',
            'action' => 'deleted',
        ]);
    }

    private function validateCategory(Request $request, ?ProductCategory $category = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('product_categories', 'name')->ignore($category?->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],

            'fields' => ['nullable', 'array'],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.name' => ['required', 'string', 'max:120'],
            'fields.*.type' => ['required', 'string', Rule::in(ProductCategoryField::types())],
            'fields.*.scope' => ['nullable', 'string', Rule::in(ProductCategoryField::scopes())],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.options.*' => ['nullable', 'string', 'max:120'],
            'fields.*.is_required' => ['nullable', 'boolean'],
            'fields.*.is_active' => ['nullable', 'boolean'],
            'fields.*.is_filterable' => ['nullable', 'boolean'],
            'fields.*.filter_label' => ['nullable', 'string', 'max:120'],
            'fields.*.filter_unit' => ['nullable', 'string', 'max:30'],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    private function syncFields(ProductCategory $category, ?array $fields): void
    {
        if ($fields === null) {
            return;
        }

        $incomingIds = collect($fields)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $deleteQuery = $category->fields();

        if (! empty($incomingIds)) {
            $deleteQuery->whereNotIn('id', $incomingIds);
        }

        $deleteQuery->delete();

        foreach ($fields as $index => $fieldData) {
            $field = isset($fieldData['id'])
                ? $category->fields()->whereKey($fieldData['id'])->first()
                : null;

            $type = $fieldData['type'];
            $options = $this->sanitizeFieldOptions($type, $fieldData['options'] ?? []);

            $payload = [
                'name' => $fieldData['name'],
                'field_key' => $field?->field_key ?? $this->generateUniqueFieldKey($category, $fieldData['name']),
                'type' => $type,
                'scope' => $fieldData['scope'] ?? ProductCategoryField::SCOPE_PRODUCT,
                'options' => $options,
                'is_required' => filter_var($fieldData['is_required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'is_active' => filter_var($fieldData['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'is_filterable' => filter_var($fieldData['is_filterable'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'filter_label' => $fieldData['filter_label'] ?? null,
                'filter_unit' => $fieldData['filter_unit'] ?? null,
                'sort_order' => $fieldData['sort_order'] ?? $index + 1,
            ];

            if ($field) {
                $field->update($payload);
            } else {
                $category->fields()->create($payload);
            }
        }
    }

    private function sanitizeFieldOptions(string $type, array $options): ?array
    {
        if ($type !== ProductCategoryField::TYPE_SELECT) {
            return null;
        }

        $cleanOptions = collect($options)
            ->map(fn ($option) => trim((string) $option))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($cleanOptions)) {
            throw ValidationException::withMessages([
                'fields' => 'Los campos tipo selector deben tener al menos una opción.',
            ]);
        }

        return $cleanOptions;
    }

    private function generateUniqueFieldKey(ProductCategory $category, string $name): string
    {
        $baseKey = Str::slug($name, '_') ?: 'campo';
        $key = $baseKey;
        $counter = 2;

        while (
            $category->fields()
                ->where('field_key', $key)
                ->exists()
        ) {
            $key = "{$baseKey}_{$counter}";
            $counter++;
        }

        return $key;
    }

    private function generateUniqueSlug(string $name, ?int $ignoreCategoryId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (
            ProductCategory::query()
                ->where('slug', $slug)
                ->when($ignoreCategoryId, function ($query) use ($ignoreCategoryId) {
                    $query->where('id', '!=', $ignoreCategoryId);
                })
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }
    }
}
