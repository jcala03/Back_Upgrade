<?php

namespace App\Services;

use App\Models\CrmNotification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class CrmNotificationService
{
    public function distribute(string $permission, array $notification): int
    {
        $this->validate($notification);

        return User::query()
            ->where('is_active', true)
            ->with('capabilities')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($permission))
            ->sum(fn (User $user) => $this->createFor($user, $notification) ? 1 : 0);
    }

    public function createFor(User $user, array $notification): ?CrmNotification
    {
        $this->validate($notification);
        $values = [
            'user_id' => $user->id,
            'type' => $notification['type'],
            'severity' => $notification['severity'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'data' => $notification['data'] ?? null,
            'reference_type' => $notification['reference_type'] ?? null,
            'reference_id' => $notification['reference_id'] ?? null,
            'dedupe_key' => $notification['dedupe_key'] ?? null,
        ];

        if (! $values['dedupe_key']) {
            return CrmNotification::create($values);
        }

        try {
            return CrmNotification::firstOrCreate(
                ['user_id' => $user->id, 'dedupe_key' => $values['dedupe_key']],
                $values,
            );
        } catch (QueryException $exception) {
            $existing = CrmNotification::query()
                ->where('user_id', $user->id)
                ->where('dedupe_key', $values['dedupe_key'])
                ->first();
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
    }

    private function validate(array $notification): void
    {
        if (! in_array($notification['type'] ?? null, CrmNotification::types(), true)) {
            throw ValidationException::withMessages(['type' => 'El tipo de notificación no es válido.']);
        }
        if (! in_array($notification['severity'] ?? null, CrmNotification::severities(), true)) {
            throw ValidationException::withMessages(['severity' => 'La severidad de la notificación no es válida.']);
        }
        foreach (['title', 'message'] as $field) {
            if (trim((string) ($notification[$field] ?? '')) === '') {
                throw ValidationException::withMessages([$field => 'El contenido de la notificación es obligatorio.']);
            }
        }
    }
}
