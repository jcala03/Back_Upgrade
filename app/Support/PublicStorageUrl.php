<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/** URL presentation only: never rewrites stored paths or accesses private disks. */
class PublicStorageUrl
{
    public static function image(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('~^(?:https?:)?//~i', $path)) {
            return $path;
        }

        $path = ltrim($path, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Storage::disk('public')->url($path);
    }
}
