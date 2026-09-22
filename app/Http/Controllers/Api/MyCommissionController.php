<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListMyCommissionsRequest;
use App\Http\Requests\MyCommissionSummaryRequest;
use App\Http\Resources\MyCommissionResource;
use App\Models\EmployeeCommission;
use App\Services\CommissionService;
use Illuminate\Http\JsonResponse;

class MyCommissionController extends Controller
{
    public function __construct(private readonly CommissionService $commissions) {}

    public function index(ListMyCommissionsRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;
        if (! $employee) {
            return response()->json([
                'data' => [
                    'employee_linked' => false,
                    'commissions' => [],
                    'date_basis' => 'created_at',
                ],
            ]);
        }

        $results = $this->commissions->paginateForEmployee($employee, $request->validated());
        $results->setCollection(
            $results->getCollection()->map(
                fn (EmployeeCommission $commission) => (new MyCommissionResource($commission))->resolve($request),
            ),
        );

        return response()->json([
            'data' => [
                'employee_linked' => true,
                'commissions' => $results,
                'date_basis' => 'created_at',
            ],
        ]);
    }

    public function summary(MyCommissionSummaryRequest $request): JsonResponse
    {
        $employee = $request->user()->employee;

        return response()->json([
            'data' => [
                'employee_linked' => $employee !== null,
                ...$this->commissions->currentMonthSummary($employee),
            ],
        ]);
    }
}
