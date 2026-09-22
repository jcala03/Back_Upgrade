<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Support\Business\BusinessContext;
use Illuminate\Support\Facades\DB;

class BusinessSettingsService
{
    private const SINGLE_ROW_ID = 1;

    public function get(): ?BusinessSetting
    {
        return BusinessSetting::query()->with('updatedBy:id,name')->find(self::SINGLE_ROW_ID);
    }

    public function update(array $data, User $user): BusinessSetting
    {
        return DB::transaction(function () use ($data, $user) {
            $setting = BusinessSetting::query()->lockForUpdate()->find(self::SINGLE_ROW_ID);
            $values = [...$data, 'updated_by' => $user->id];

            if ($setting) {
                $setting->update($values);
            } else {
                $setting = new BusinessSetting($values);
                $setting->id = self::SINGLE_ROW_ID;
                $setting->save();
            }

            return $setting->fresh()->load('updatedBy:id,name');
        });
    }

    public function response(?BusinessSetting $setting = null): array
    {
        $setting ??= $this->get();

        return [
            'business' => [
                'business_name' => $setting?->business_name ?? config('business.name'),
                'legal_name' => $setting?->legal_name,
                'tax_id' => $setting?->tax_id,
                'phone' => $setting?->phone,
                'email' => $setting?->email,
                'whatsapp' => $setting?->whatsapp ?? config('business.whatsapp'),
                'address' => $setting?->address,
                'city' => $setting?->city,
            ],
            'sales' => [
                'quotation_validity_days' => $setting?->quotation_validity_days ?? (int) config('business.quotation_validity_days', 15),
                'order_notification_email' => $setting?->order_notification_email ?? config('business.order_notification_email'),
            ],
            'regional' => [
                'currency' => BusinessContext::CURRENCY,
                'timezone' => BusinessContext::TIMEZONE,
            ],
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->updatedBy ? [
                'id' => $setting->updatedBy->id,
                'name' => $setting->updatedBy->name,
            ] : null,
        ];
    }

    public function identity(): array
    {
        return $this->response()['business'] + ['currency' => BusinessContext::CURRENCY];
    }

    public function quotationValidityDays(): int
    {
        return $this->get()?->quotation_validity_days ?? (int) config('business.quotation_validity_days', 15);
    }

    public function orderNotificationEmail(): ?string
    {
        return $this->get()?->order_notification_email ?? config('business.order_notification_email');
    }
}
