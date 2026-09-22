<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MyBusinessOverviewRequest;
use App\Models\Employee;
use App\Services\BusinessOverviewService;
use Illuminate\Http\JsonResponse;

class MyBusinessOverviewController extends Controller
{
    public function __construct(private readonly BusinessOverviewService $overview) {}

    public function __invoke(MyBusinessOverviewRequest $request): JsonResponse
    {
        $employee = Employee::query()
            ->select(['id', 'user_id', 'branch_id', 'name', 'is_active'])
            ->with('branch:id,code,name,city,is_active')
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $employee?->is_active || $employee->branch_id === null || ! $employee->branch) {
            $employee = null;
        }

        return response()->json([
            'data' => $this->overview->build($request->user(), $employee, $request->validated()),
        ]);
    }
}
