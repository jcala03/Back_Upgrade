<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\EcommerceShippingException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminOrderRequest;
use App\Http\Requests\StoreOrderPaymentRequest;
use App\Http\Requests\StorePublicOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\PublicOrderResource;
use App\Mail\NewOrderNotificationMail;
use App\Mail\OrderCreatedMail;
use App\Models\Order;
use App\Services\BusinessSettingsService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\PublicCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly BusinessSettingsService $settings,
        private readonly PublicCheckoutService $checkout,
    ) {}

    public function store(StorePublicOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        $key = $data['idempotency_key'];
        unset($data['idempotency_key']);

        try {
            [$order, $publicToken, $replayed] = $this->checkout->create($data, $key);
        } catch (EcommerceShippingException $exception) {
            return response()->json(['message' => 'No fue posible crear la orden.', 'code' => $exception->errorCode], 422);
        }
        if (! $replayed) {
            $this->sendOrderEmails($order);
        }

        return response()->json([
            'message' => $replayed ? 'Orden recuperada correctamente.' : 'Orden creada correctamente.',
            'public_token' => $publicToken,
            'data' => (new PublicOrderResource($order))->resolve($request),
        ], $replayed ? 200 : 201);
    }

    public function adminStore(StoreAdminOrderRequest $request): JsonResponse
    {
        $order = $this->orders->createAdmin($request->validated(), $request->user());

        return response()->json(['message' => 'Venta creada correctamente.', 'data' => $order], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');
        $filters = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sales_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'status' => ['nullable', Rule::in(Order::statuses())],
            'origin' => ['nullable', Rule::in(Order::origins())],
        ]);
        $orders = Order::query()->with([
            'items.productVariant', 'items.service.category', 'payments', 'charges', 'shippingAddress', 'customer',
            'customerVehicle.vehicleBrand', 'customerVehicle.vehicleModel',
            'customerVehicle.vehicleVersion',
            'branch:id,code,name,city,is_active',
            'salesEmployee:id,name,branch_id',
            'salesEmployee.branch:id,code,name,city,is_active',
        ])
            ->when($filters['branch_id'] ?? null, fn ($query, int $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['sales_employee_id'] ?? null, fn ($query, int $employeeId) => $query->where('sales_employee_id', $employeeId))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['origin'] ?? null, fn ($query, string $origin) => $query->where('origin', $origin))
            ->latest()
            ->get();

        return response()->json(['data' => $orders]);
    }

    public function adminShow(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view');

        return response()->json(['data' => $this->orders->loadOrder($order)]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $data = $request->validated();
        $assignment = array_intersect_key($data, array_flip(['branch_id', 'sales_employee_id']));
        $updated = $this->orders->transition(
            $order,
            $data['status'],
            $request->user(),
            $data['reason'] ?? null,
            $assignment,
        );

        return response()->json(['message' => 'Estado actualizado correctamente.', 'data' => $updated]);
    }

    public function storePayment(StoreOrderPaymentRequest $request, Order $order): JsonResponse
    {
        $payment = $this->payments->register($order, $request->validated(), $request->user());

        return response()->json(['message' => 'Pago registrado correctamente.', 'data' => $payment->load('creator'), 'order' => $this->orders->loadOrder($order->fresh())], 201);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }

    private function sendOrderEmails(Order $order): void
    {
        $business = $this->settings->identity();
        if ($order->customer_email) {
            try {
                Mail::to($order->customer_email)->send(new OrderCreatedMail($order, $business));
            } catch (\Throwable $exception) {
                Log::warning('No se pudo enviar el correo al cliente.', ['order_id' => $order->id, 'error' => $exception->getMessage()]);
            }
        }
        $notificationEmail = $this->settings->orderNotificationEmail();
        if ($notificationEmail) {
            try {
                Mail::to($notificationEmail)->send(new NewOrderNotificationMail($order, $business));
            } catch (\Throwable $exception) {
                Log::warning('No se pudo enviar el correo interno de nueva orden.', ['order_id' => $order->id, 'error' => $exception->getMessage()]);
            }
        }
    }
}
