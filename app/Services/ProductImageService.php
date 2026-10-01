<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductImageService
{
    public function validateUploads(Request $request): array
    {
        $files = $request->file('images', []);
        Validator::make(['images' => $files], [
            'images' => ['array'],
            'images.*' => ['bail', 'required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'images.*.image' => 'El archivo debe contener una imagen JPEG, PNG o WEBP válida.',
            'images.*.mimetypes' => 'Solo se permiten imágenes JPEG, PNG o WEBP.',
            'images.*.extensions' => 'La extensión debe ser JPG, JPEG, PNG o WEBP.',
            'images.*.max' => 'Cada imagen debe pesar como máximo 5 MB.',
            'images.*.uploaded' => 'No se pudo subir la imagen. Comprueba que pese hasta 5 MB.',
            'images.*.file' => 'Selecciona un archivo de imagen válido.',
        ])->validate();

        return $files;
    }

    /** Caller owns the product row lock and transaction; paths were stored by the controller. */
    public function sync(Product $product, Request $request, array $paths, ?string $legacyUpload): void
    {
        $existing = $product->images()->get()->keyBy('id');
        // Compatible with seed/import records that predate the relational gallery.
        if ($existing->isEmpty() && $product->main_image) {
            $image = $product->images()->create(['path' => $product->main_image, 'is_primary' => true]);
            $existing->put($image->id, $image);
        }
        $oldPaths = $existing->pluck('path')->all();
        $canonical = $request->exists('gallery');
        if ($canonical && $legacyUpload) {
            throw ValidationException::withMessages(['gallery' => 'Usa la galería o la imagen individual, no ambas.']);
        }
        if ($request->exists('main_image') && ! $request->file('main_image') && $request->input('main_image') !== $product->main_image) {
            throw ValidationException::withMessages(['main_image' => 'Sube un archivo de imagen; no se aceptan rutas nuevas.']);
        }
        $rows = [];
        $primary = 0;
        if ($canonical) {
            $gallery = $request->input('gallery');
            if (is_string($gallery)) {
                $gallery = json_decode($gallery, true);
            }
            $data = Validator::make(['gallery' => $gallery, 'primary_image_index' => $request->input('primary_image_index')], [
                'gallery' => ['present', 'array', 'list'],
                'gallery.*' => ['array:id,upload_index'],
                'gallery.*.id' => ['integer', 'distinct', 'required_without:gallery.*.upload_index'],
                'gallery.*.upload_index' => ['integer', 'min:0', 'distinct', 'required_without:gallery.*.id'],
                'primary_image_index' => count(is_array($gallery) ? $gallery : []) ? ['required', 'integer', 'min:0', 'max:'.(count($gallery) - 1)] : ['nullable'],
            ])->validate();
            foreach ($data['gallery'] as $row) {
                if (isset($row['id']) === isset($row['upload_index'])) {
                    throw ValidationException::withMessages(['gallery' => 'Cada imagen debe indicar un id existente o un archivo nuevo, no ambos.']);
                }
                if (isset($row['id']) && $existing->has($row['id'])) {
                    $rows[] = $existing->get($row['id']);
                } elseif (isset($row['upload_index']) && array_key_exists($row['upload_index'], $paths)) {
                    $rows[] = new ProductImage(['path' => $paths[$row['upload_index']]]);
                } else {
                    throw ValidationException::withMessages(['gallery' => 'La imagen no pertenece al producto o falta el archivo.']);
                }
            }
            if (count(array_filter($data['gallery'], fn ($row) => isset($row['upload_index']))) !== count($paths)) {
                throw ValidationException::withMessages(['gallery' => 'Todos los archivos deben estar incluidos en la galería.']);
            }
            $primary = (int) ($data['primary_image_index'] ?? 0);
        } else {
            $rows = $existing->values()->all();
            $primary = max(0, (int) $existing->values()->search(fn ($image) => $image->is_primary));
            if ($legacyUpload) {
                if (isset($rows[$primary])) {
                    $rows[$primary]->path = $legacyUpload;
                } else {
                    $rows[] = new ProductImage(['path' => $legacyUpload]);
                }
            }
            foreach ($paths as $path) {
                $rows[] = new ProductImage(['path' => $path]);
            }
        }
        if ($product->is_visible && ! count($rows)) {
            throw ValidationException::withMessages(['gallery' => 'Para publicar el producto necesitas al menos una imagen principal.']);
        }
        // Clear and promote under one parent lock; the unique generated key guards races too.
        $product->images()->update(['is_primary' => false]);
        $ids = [];
        foreach ($rows as $index => $image) {
            // Bulk clearing does not refresh Eloquent originals. Without this, saving an
            // unchanged principal would omit is_primary=true from the UPDATE statement.
            $image->forceFill(['is_primary' => false])->syncOriginalAttribute('is_primary');
            $image->fill(['is_primary' => $index === $primary, 'sort_order' => $index]);
            $product->images()->save($image);
            $ids[] = $image->id;
        }
        $product->images()->whereNotIn('id', $ids)->delete();
        $product->forceFill(['main_image' => $rows[$primary]->path ?? null])->save();
        $removed = array_diff($oldPaths, array_map(fn ($row) => $row->path, $rows));
        DB::afterCommit(fn () => $this->cleanup($removed));
    }

    /** Delete only server-generated uploads that no remaining entity references. */
    public function cleanup(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if (! preg_match('~^products/[a-zA-Z0-9]{40}\.(jpg|jpeg|png|webp)$~', $path)
                || ProductImage::where('path', $path)->exists() || Product::where('main_image', $path)->exists()
                || ProductVariant::where('main_image', $path)->exists()) {
                continue;
            }
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable) {
                Log::warning('Product upload cleanup could not complete; retry storage maintenance.');
            }
        }
    }
}
