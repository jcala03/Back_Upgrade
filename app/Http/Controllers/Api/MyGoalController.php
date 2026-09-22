<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelPersonalGoalRequest;
use App\Http\Requests\StorePersonalGoalRequest;
use App\Http\Requests\UpdateGoalProgressRequest;
use App\Http\Requests\UpdatePersonalGoalRequest;
use App\Models\Goal;
use App\Services\GoalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyGoalController extends Controller
{
    public function __construct(private readonly GoalService $service) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => ['employee_linked' => false, 'goals' => []]]);
        }
        $filters = $request->validate(GoalController::filterRules());
        $goals = $this->service->paginate($filters, $employee);
        $goals->getCollection()->each(fn (Goal $goal) => $this->sanitize($goal));

        return response()->json(['data' => ['employee_linked' => true, 'goals' => $goals]]);
    }

    public function store(StorePersonalGoalRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->sanitize($this->service->createPersonal($request->validated(), $request->user()))], 201);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->own($request, $goal);

        return response()->json(['data' => $this->sanitize($goal)]);
    }

    public function update(UpdatePersonalGoalRequest $request, Goal $goal): JsonResponse
    {
        $this->own($request, $goal);

        return response()->json(['data' => $this->sanitize($this->service->updatePersonal($goal, $request->validated(), $request->user()))]);
    }

    public function progress(UpdateGoalProgressRequest $request, Goal $goal): JsonResponse
    {
        $this->own($request, $goal);

        return response()->json(['data' => $this->sanitize($this->service->updateProgress($goal, $request->validated('current_value'), $request->user(), true))]);
    }

    public function complete(Request $request, Goal $goal): JsonResponse
    {
        $this->own($request, $goal);

        return response()->json(['data' => $this->sanitize($this->service->complete($goal, $request->user(), true))]);
    }

    public function cancel(CancelPersonalGoalRequest $request, Goal $goal): JsonResponse
    {
        $this->own($request, $goal);

        return response()->json(['data' => $this->sanitize($this->service->cancelPersonal($goal, $request->validated('cancellation_reason'), $request->user()))]);
    }

    private function own(Request $request, Goal $goal): void
    {
        abort_unless($request->user()->employee?->id === $goal->employee_id, 404);
    }

    private function sanitize(Goal $goal): Goal
    {
        foreach (['employee', 'creator', 'updater', 'completer', 'canceller'] as $relation) {
            $goal->unsetRelation($relation);
        }

        return $goal->makeHidden(['employee_id', 'created_by', 'updated_by', 'completed_by', 'cancelled_by', 'cancellation_reason']);
    }
}
