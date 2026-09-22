<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MyTaskController extends Controller
{
    public function __construct(private readonly TaskService $service) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json(['data' => ['employee_linked' => false, 'tasks' => []]]);
        }
        $filters = $request->validate(['status' => ['nullable', Rule::in(Task::statuses())],
            'priority' => ['nullable', Rule::in(Task::priorities())], 'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        $tasks = $this->service->paginate($filters, $employee);
        $tasks->getCollection()->each(fn (Task $task) => $this->sanitize($task));

        return response()->json(['data' => ['employee_linked' => true, 'tasks' => $tasks]]);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->own($request, $task);

        return response()->json(['data' => $this->sanitize($task->load(['employee:id,name,job_title,specialty', 'branch:id,code,name']))]);
    }

    public function start(Request $request, Task $task): JsonResponse
    {
        $this->own($request, $task);

        return response()->json(['data' => $this->sanitize($this->service->start($task, $request->user()))]);
    }

    public function complete(Request $request, Task $task): JsonResponse
    {
        $this->own($request, $task);

        return response()->json(['data' => $this->sanitize($this->service->complete($task, $request->user()))]);
    }

    private function own(Request $request, Task $task): void
    {
        abort_unless($request->user()->employee?->id === $task->assigned_employee_id, 404);
    }

    private function sanitize(Task $task): Task
    {
        foreach (['creator', 'updater', 'canceller', 'availabilityOverrider'] as $relation) {
            $task->unsetRelation($relation);
        }

        return $task->makeHidden([
            'created_by', 'updated_by', 'cancelled_by', 'availability_overridden_by',
            'availability_override_reason', 'availability_overridden_at',
        ]);
    }
}
