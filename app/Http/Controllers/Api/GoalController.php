<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelAssignedGoalRequest;
use App\Http\Requests\StoreAssignedGoalRequest;
use App\Http\Requests\UpdateAdminGoalRequest;
use App\Http\Requests\UpdateGoalProgressRequest;
use App\Models\Goal;
use App\Services\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalController extends Controller
{
    public function __construct(private readonly GoalService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('goals.view'), 403);
        $filters = $request->validate($this->filterRules(true));

        return response()->json(['data' => $this->service->paginate($filters)]);
    }

    public function store(StoreAssignedGoalRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->service->createAssigned($request->validated(), $request->user())], 201);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($request->user()->hasPermission('goals.view'), 403);

        return response()->json(['data' => $this->service->load($goal)]);
    }

    public function update(UpdateAdminGoalRequest $request, Goal $goal): JsonResponse
    {
        return response()->json(['data' => $this->service->updateAdmin($goal, $request->validated(), $request->user())]);
    }

    public function progress(UpdateGoalProgressRequest $request, Goal $goal): JsonResponse
    {
        abort_unless($request->user()->hasPermission('goals.update'), 403);

        return response()->json(['data' => $this->service->updateProgress($goal, $request->validated('current_value'), $request->user(), false)]);
    }

    public function complete(Request $request, Goal $goal): JsonResponse
    {
        abort_unless($request->user()->hasPermission('goals.update'), 403);

        return response()->json(['data' => $this->service->complete($goal, $request->user(), false)]);
    }

    public function cancel(CancelAssignedGoalRequest $request, Goal $goal): JsonResponse
    {
        return response()->json(['data' => $this->service->cancelAssigned($goal, $request->validated('cancellation_reason'), $request->user())]);
    }

    public static function filterRules(bool $admin = false): array
    {
        return [...($admin ? ['employee_id' => ['nullable', 'integer', 'exists:employees,id']] : ['employee_id' => ['prohibited']]),
            'source' => ['nullable', Rule::in(Goal::sources())], 'status' => ['nullable', Rule::in(Goal::effectiveStatuses())],
            'due_from' => ['nullable', 'date'], 'due_to' => ['nullable', 'date'],
            ...($admin ? ['starts_from' => ['nullable', 'date'], 'starts_to' => ['nullable', 'date']]
                : ['starts_from' => ['prohibited'], 'starts_to' => ['prohibited']]),
            'search' => ['nullable', 'string', 'max:180'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']];
    }
}
