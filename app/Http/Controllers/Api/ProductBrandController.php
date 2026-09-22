<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductBrand;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductBrandController extends Controller
{
    public function index()
    {
        $brands = ProductBrand::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $brands,
        ]);
    }

    public function adminIndex(Request $request)
    {
        $this->authorizePermission($request, 'products.view');

        $brands = ProductBrand::query()
            ->latest()
            ->get();

        return response()->json([
            'data' => $brands,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizePermission($request, 'products.create');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:product_brands,name'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $brand = ProductBrand::create([
            'name' => $validated['name'],
            'slug' => $this->generateUniqueSlug($validated['name']),
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Marca de producto creada correctamente.',
            'data' => $brand,
        ], 201);
    }

    public function update(Request $request, ProductBrand $productBrand)
    {
        $this->authorizePermission($request, 'products.update');

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('product_brands', 'name')->ignore($productBrand->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $newSlug = $productBrand->name !== $validated['name']
            ? $this->generateUniqueSlug($validated['name'], $productBrand->id)
            : $productBrand->slug;

        $productBrand->update([
            'name' => $validated['name'],
            'slug' => $newSlug,
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Marca de producto actualizada correctamente.',
            'data' => $productBrand->fresh(),
        ]);
    }

    public function destroy(Request $request, ProductBrand $productBrand)
    {
        $this->authorizePermission($request, 'products.delete');

        $productBrand->update([
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Marca de producto desactivada correctamente.',
        ]);
    }

    private function generateUniqueSlug(string $name, ?int $ignoreBrandId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 2;

        while (
            ProductBrand::query()
                ->where('slug', $slug)
                ->when($ignoreBrandId, function ($query) use ($ignoreBrandId) {
                    $query->where('id', '!=', $ignoreBrandId);
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
