<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAdminCommissionsRequest;
use App\Http\Resources\AdminCommissionResource;
use App\Models\EmployeeCommission;
use App\Services\CommissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function __construct(private readonly CommissionService $commissions) {}

    public function index(ListAdminCommissionsRequest $request): JsonResponse
    {
        $results = $this->commissions->paginateAdmin($request->validated());
        $results->setCollection(
            $results->getCollection()->map(
                fn (EmployeeCommission $commission) => (new AdminCommissionResource($commission))->resolve($request),
            ),
        );

        return response()->json([
            'data' => [
                'commissions' => $results,
                'date_basis' => 'created_at',
            ],
        ]);
    }

    public function show(Request $request, EmployeeCommission $commission): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('commissions.view'), 403);

        return response()->json([
            'data' => (new AdminCommissionResource(
                $this->commissions->load($commission),
            ))->resolve($request),
        ]);
    }
}
