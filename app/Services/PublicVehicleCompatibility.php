<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class PublicVehicleCompatibility
{
    public const KEYS = ['vehicle_brand_id', 'vehicle_model_id', 'vehicle_version_id', 'vehicle_multimedia_system_id'];

    public static function active(Request $request): bool
    {
        return $request->anyFilled([...self::KEYS, 'year']);
    }

    public static function variants(Request $request): Builder
    {
        $query = ProductVariant::query()->where('is_active', true)->where('is_visible', true);
        if (self::active($request)) {
            self::matchVariant($query, $request);
        }

        return $query;
    }

    public static function matchVariant(Builder $query, Request $request): void
    {
        if (! self::active($request)) {
            return;
        }
        // OEM-required categories cannot infer a system from a year or generation.
        if (collect(self::KEYS)->contains(fn ($key) => ! $request->filled($key))) {
            $query->whereHas('product', fn ($product) => self::withoutOemCategory($product));
        }
        $query->where(function (Builder $match) use ($request) {
            $match->whereHas('vehicleCompatibilities', fn ($row) => self::row($row, $request, true));
            if (! $request->filled('vehicle_multimedia_system_id')) {
                $match->orWhere(function (Builder $inherited) use ($request) {
                    $inherited->whereHas('product', fn ($product) => self::withoutOemCategory($product))
                        ->where(function (Builder $type) use ($request) {
                            $type->where('compatibility_type', Product::COMPATIBILITY_TYPE_UNIVERSAL)
                                ->orWhere(function (Builder $general) use ($request) {
                                    $general->whereNull('compatibility_type')
                                        ->whereHas('product', fn ($product) => self::general($product, $request));
                                });
                        });
                });
            }
        });
    }

    public static function products(Builder $query, Request $request): void
    {
        if (! self::active($request)) {
            return;
        }
        $query->where(function (Builder $match) use ($request) {
            $match->whereHas('variants', fn ($variant) => $variant
                ->where('is_active', true)->where('is_visible', true)
                ->where(fn ($selected) => self::matchVariant($selected, $request)));
            if (! $request->filled('vehicle_multimedia_system_id')) {
                $match->orWhere(function (Builder $simple) use ($request) {
                    $simple->whereDoesntHave('variants', fn ($variant) => $variant->where('is_active', true)->where('is_visible', true));
                    self::withoutOemCategory($simple);
                    self::general($simple, $request);
                });
            }
        });
    }

    private static function withoutOemCategory(Builder $query): void
    {
        $query->whereDoesntHave('productCategory', fn ($category) => $category->where('requires_oem_compatibility', true));
    }

    private static function general(Builder $query, Request $request): void
    {
        $query->where(fn ($match) => $match
            ->where('compatibility_type', Product::COMPATIBILITY_TYPE_UNIVERSAL)
            ->orWhereHas('vehicleCompatibilities', fn ($row) => self::row($row, $request, false)));
    }

    private static function row(Builder $query, Request $request, bool $variant): void
    {
        foreach (self::KEYS as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->integer($column));
            }
        }
        if ($request->filled('vehicle_multimedia_system_id')) {
            $query->whereHas('vehicleMultimediaSystem', fn ($system) => $system->where('is_active', true));
        }
        if ($request->filled('year')) {
            $year = $request->integer('year');
            $query->where(function ($range) use ($year, $variant) {
                $natural = fn ($row) => $row->where(fn ($version) => $version
                    ->whereNull('vehicle_version_id')
                    ->orWhereHas('vehicleVersion', fn ($v) => $v->where('year_from', '<=', $year)
                        ->where(fn ($end) => $end->whereNull('year_to')->orWhere('year_to', '>=', $year))));
                if (! $variant) {
                    $natural($range);

                    return;
                }
                $range->where(fn ($override) => $override
                    ->where(fn ($has) => $has->whereNotNull('year_from')->orWhereNotNull('year_to'))
                    ->where(fn ($from) => $from->whereNull('year_from')->orWhere('year_from', '<=', $year))
                    ->where(fn ($to) => $to->whereNull('year_to')->orWhere('year_to', '>=', $year)))
                    ->orWhere(function ($row) use ($natural) {
                        $row->whereNull('year_from')->whereNull('year_to');
                        $natural($row);
                    });
            });
        }
    }
}
