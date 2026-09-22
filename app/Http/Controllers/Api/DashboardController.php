<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardCompareRequest;
use App\Http\Requests\DashboardRequest;
use App\Models\Branch;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(DashboardRequest $request): JsonResponse
    {
        $branch = isset($request->validated()['branch_id'])
            ? Branch::query()->findOrFail($request->integer('branch_id'))
            : null;

        return response()->json([
            'data' => $this->dashboard->build($request->user(), $branch),
        ]);
    }

    public function compare(DashboardCompareRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->dashboard->compare($request->validated('branch_ids')),
        ]);
    }
}
