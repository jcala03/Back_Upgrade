<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tasks.view'), 403);
        $filters = $request->validate($this->filterRules());

        return response()->json(['data' => $this->service->paginate($filters)]);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->service->create($request->validated(), $request->user())], 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tasks.view'), 403);

        return response()->json(['data' => $task->load(['employee', 'branch', 'creator', 'updater', 'canceller', 'availabilityOverrider'])]);
    }

    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        return response()->json(['data' => $this->service->update($task, $request->validated(), $request->user())]);
    }

    public function start(Request $request, Task $task): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tasks.update'), 403);

        return response()->json(['data' => $this->service->start($task, $request->user())]);
    }

    public function complete(Request $request, Task $task): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tasks.update'), 403);

        return response()->json(['data' => $this->service->complete($task, $request->user())]);
    }

    public function cancel(CancelTaskRequest $request, Task $task): JsonResponse
    {
        return response()->json(['data' => $this->service->cancel($task, $request->validated('reason'), $request->user())]);
    }

    private function filterRules(): array
    {
        return ['employee_id' => ['nullable', 'integer', 'exists:employees,id'], 'branch_id' => ['nullable', 'integer', 'exists:branches,id'], 'status' => ['nullable', Rule::in(Task::statuses())],
            'priority' => ['nullable', Rule::in(Task::priorities())], 'due_from' => ['nullable', 'date'], 'due_to' => ['nullable', 'date'],
            'scheduled_from' => ['nullable', 'date'], 'scheduled_to' => ['nullable', 'date'], 'search' => ['nullable', 'string', 'max:180'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']];
    }
}
