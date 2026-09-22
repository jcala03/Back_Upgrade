<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMySalesRequest;
use App\Http\Requests\StoreMySalePaymentRequest;
use App\Http\Requests\StoreMySaleRequest;
use App\Http\Resources\MySaleResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MySaleController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    public function index(ListMySalesRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json([
                'data' => ['employee_linked' => false, 'sales' => []],
            ]);
        }

        $filters = $request->validated();
        $query = Order::query()
            ->where('sales_employee_id', $employee->id)
            ->with([
                'branch:id,code,name,city,is_active',
                'salesEmployee:id,branch_id,name,is_active',
            ])
            ->withCount(['items', 'payments']);
        $results = $this->orders->applyPersonalFilters($query, $filters)
            ->latest()
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
        $results->setCollection(
            $results->getCollection()
                ->map(fn (Order $order) => (new MySaleResource($order))->resolve($request)),
        );

        return response()->json([
            'data' => ['employee_linked' => true, 'sales' => $results],
        ]);
    }

    public function store(StoreMySaleRequest $request): JsonResponse
    {
        $order = $this->orders->createOwn($request->validated(), $request->user());

        return response()->json([
            'message' => 'Venta creada correctamente.',
            'data' => (new MySaleResource($this->orders->loadPersonal($order)))->resolve($request),
        ], 201);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, 'orders.view_own');
        $this->own($request, $order);

        return response()->json([
            'data' => (new MySaleResource($this->orders->loadPersonal($order)))->resolve($request),
        ]);
    }

    public function confirm(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, 'orders.confirm_own');
        $request->validate($this->actionRules());
        $this->own($request, $order);
        $order = $this->orders->transitionOwn($order, Order::STATUS_CONFIRMED, $request->user());

        return response()->json([
            'message' => 'Venta confirmada correctamente.',
            'data' => (new MySaleResource($this->orders->loadPersonal($order)))->resolve($request),
        ]);
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        $this->authorizePermission($request, 'orders.complete_own');
        $request->validate($this->actionRules());
        $this->own($request, $order);
        $order = $this->orders->transitionOwn($order, Order::STATUS_COMPLETED, $request->user());

        return response()->json([
            'message' => 'Venta completada correctamente.',
            'data' => (new MySaleResource($this->orders->loadPersonal($order)))->resolve($request),
        ]);
    }

    public function storePayment(StoreMySalePaymentRequest $request, Order $order): JsonResponse
    {
        $this->own($request, $order);
        $payment = $this->payments->registerOwn($order, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'data' => MySaleResource::payment($payment),
            'sale' => (new MySaleResource(
                $this->orders->loadPersonal($order->fresh()),
            ))->resolve($request),
        ], 201);
    }

    private function own(Request $request, Order $order): void
    {
        $employeeId = $request->user()->employee?->id;

        abort_unless(
            $employeeId !== null && $employeeId === $order->sales_employee_id,
            404,
        );
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless(
            $request->user()?->hasPermission($permission),
            403,
            'No tienes permisos para realizar esta acción.',
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function actionRules(): array
    {
        return [
            'branch_id' => ['prohibited'],
            'sales_employee_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'status' => ['prohibited'],
            'reason' => ['prohibited'],
        ];
    }
}
