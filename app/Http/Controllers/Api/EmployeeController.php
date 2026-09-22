<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GrantEmployeeCrmAccessRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'employees.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:160'],
            'is_active' => ['nullable', 'boolean'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'specialty' => ['nullable', 'string', 'max:160'],
            'linked_user' => ['nullable', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sort' => ['nullable', Rule::in(['name', 'job_title', 'hire_date', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        $employees = Employee::query()
            ->with([
                'user:id,name,email,is_active',
                'branch:id,code,name,city,is_active',
            ])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('job_title', 'like', "%{$search}%")
                ->orWhere('specialty', 'like', "%{$search}%")))
            ->when(array_key_exists('is_active', $filters), fn (Builder $query) => $query->where('is_active', $filters['is_active']))
            ->when($filters['job_title'] ?? null, fn (Builder $query, string $value) => $query->where('job_title', $value))
            ->when($filters['specialty'] ?? null, fn (Builder $query, string $value) => $query->where('specialty', $value))
            ->when(array_key_exists('linked_user', $filters), fn (Builder $query) => $filters['linked_user'] ? $query->whereNotNull('user_id') : $query->whereNull('user_id'))
            ->when($filters['branch_id'] ?? null, fn (Builder $query, int $branchId) => $query->where('branch_id', $branchId))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        $employees->through(fn (Employee $employee) => $this->payload($employee));

        return response()->json(['data' => $employees]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $employee = $this->employees->create($data, $request->user());

        return response()->json([
            'message' => 'Empleado creado correctamente.',
            'data' => $this->payload($this->loadRelations($employee)),
        ], 201);
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizePermission($request, 'employees.view');

        return response()->json(['data' => $this->payload($this->loadRelations($employee))]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $updated = $this->employees->update($employee, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Empleado actualizado correctamente.',
            'data' => $this->payload($this->loadRelations($updated)),
        ]);
    }

    public function destroy(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizePermission($request, 'employees.delete');
        $employee->update(['is_active' => false]);

        return response()->json([
            'message' => 'Empleado desactivado correctamente.',
            'data' => $this->payload($this->loadRelations($employee->fresh())),
        ]);
    }

    public function grantCrmAccess(GrantEmployeeCrmAccessRequest $request, Employee $employee): JsonResponse
    {
        $data = $request->validated();
        $updated = DB::transaction(function () use ($employee, $data) {
            $locked = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            if ($locked->user_id !== null) {
                throw ValidationException::withMessages(['employee' => 'Este empleado ya tiene un usuario CRM vinculado.']);
            }
            $user = User::create([
                'name' => $locked->name,
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => User::ROLE_USER,
                'is_active' => true,
            ]);
            $locked->update(['user_id' => $user->id]);

            return $this->loadRelations($locked->fresh());
        });

        return response()->json([
            'message' => 'Acceso CRM creado correctamente.',
            'data' => $this->payload($updated),
        ], 201);
    }

    private function payload(Employee $employee): array
    {
        return [
            ...$employee->only([
                'id', 'user_id', 'branch_id', 'name', 'phone', 'job_title', 'specialty', 'notes', 'is_active',
                'hire_date', 'created_at', 'updated_at',
            ]),
            'user' => $employee->user?->only(['id', 'name', 'email', 'is_active']),
            'branch' => $employee->branch?->only(['id', 'code', 'name', 'city', 'is_active']),
        ];
    }

    private function loadRelations(Employee $employee): Employee
    {
        return $employee->load([
            'user:id,name,email,is_active',
            'branch:id,code,name,city,is_active',
        ]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403, 'No tienes permisos para realizar esta acción.');
    }
}
