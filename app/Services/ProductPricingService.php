<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

class ProductPricingService
{
    public function calculate(array $data): array
    {
        $costPrice = (int) ($data['cost_price'] ?? 0);
        $taxAmount = (int) ($data['tax_amount'] ?? 0);
        $extraCharges = (int) ($data['extra_charges'] ?? 0);
        $totalCost = $costPrice + $taxAmount + $extraCharges;

        $pricingMode = $data['pricing_mode'] ?? Product::PRICING_MODE_MANUAL;

        if (! in_array($pricingMode, [
            Product::PRICING_MODE_MANUAL,
            Product::PRICING_MODE_MARKUP,
            Product::PRICING_MODE_MARGIN,
        ], true)) {
            $pricingMode = Product::PRICING_MODE_MANUAL;
        }

        $targetProfitPercent = isset($data['target_profit_percent'])
            ? (float) $data['target_profit_percent']
            : null;

        $salePrice = match ($pricingMode) {
            Product::PRICING_MODE_MARKUP => $this->calculateByMarkup($totalCost, $targetProfitPercent),
            Product::PRICING_MODE_MARGIN => $this->calculateByMargin($totalCost, $targetProfitPercent),
            default => (int) ($data['price'] ?? 0),
        };

        $profitAmount = $salePrice - $totalCost;

        $profitMarginPercent = $salePrice > 0
            ? round(($profitAmount / $salePrice) * 100, 2)
            : 0;

        $markupPercent = $totalCost > 0
            ? round(($profitAmount / $totalCost) * 100, 2)
            : 0;

        return [
            'price' => $salePrice,
            'cost_price' => $costPrice,
            'tax_amount' => $taxAmount,
            'extra_charges' => $extraCharges,
            'total_cost' => $totalCost,
            'profit_amount' => $profitAmount,
            'profit_margin_percent' => $profitMarginPercent,
            'markup_percent' => $markupPercent,
            'pricing_mode' => $pricingMode,
            'target_profit_percent' => $targetProfitPercent,
        ];
    }

    private function calculateByMarkup(int $totalCost, ?float $targetProfitPercent): int
    {
        if ($targetProfitPercent === null) {
            throw ValidationException::withMessages([
                'target_profit_percent' => 'Debes indicar el porcentaje de ganancia sobre costo.',
            ]);
        }

        if ($targetProfitPercent < 0) {
            throw ValidationException::withMessages([
                'target_profit_percent' => 'El porcentaje de ganancia no puede ser negativo.',
            ]);
        }

        return (int) round($totalCost * (1 + ($targetProfitPercent / 100)));
    }

    private function calculateByMargin(int $totalCost, ?float $targetProfitPercent): int
    {
        if ($targetProfitPercent === null) {
            throw ValidationException::withMessages([
                'target_profit_percent' => 'Debes indicar el margen real deseado.',
            ]);
        }

        if ($targetProfitPercent < 0 || $targetProfitPercent >= 100) {
            throw ValidationException::withMessages([
                'target_profit_percent' => 'El margen real debe ser mayor o igual a 0 y menor que 100.',
            ]);
        }

        return (int) round($totalCost / (1 - ($targetProfitPercent / 100)));
    }
}
