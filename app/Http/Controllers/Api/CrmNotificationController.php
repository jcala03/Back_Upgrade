<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrmNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CrmNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'notifications.view');
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(['all', 'unread'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $userId = $request->user()->id;
        $base = CrmNotification::query()->where('user_id', $userId);
        $notifications = (clone $base)
            ->when(($filters['filter'] ?? 'all') === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        return response()->json([
            'data' => [
                'unread_count' => (clone $base)->whereNull('read_at')->count(),
                'notifications' => $notifications,
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'notifications.view');

        return response()->json([
            'data' => [
                'count' => CrmNotification::query()
                    ->where('user_id', $request->user()->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markAsRead(Request $request, CrmNotification $crmNotification): JsonResponse
    {
        $this->authorizePermission($request, 'notifications.update');
        abort_unless($crmNotification->user_id !== null && (int) $crmNotification->user_id === $request->user()->id, 404);
        if (! $crmNotification->read_at) {
            $crmNotification->update(['read_at' => now()]);
        }

        return response()->json([
            'message' => 'Notificación marcada como leída.',
            'data' => $crmNotification->fresh(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'notifications.update');
        CrmNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Todas tus notificaciones fueron marcadas como leídas.',
            'data' => ['unread_count' => 0],
        ]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
