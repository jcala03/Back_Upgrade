<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAdminCommercialProductsRequest;
use App\Http\Requests\ListMyCommercialProductsRequest;
use App\Models\Branch;
use App\Services\CommercialEmployeeContext;
use App\Services\CommercialProductCatalogService;
use Illuminate\Http\JsonResponse;

class CommercialProductController extends Controller
{
    public function __construct(
        private readonly CommercialProductCatalogService $catalog,
        private readonly CommercialEmployeeContext $commercialContext,
    ) {}

    public function admin(ListAdminCommercialProductsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branch = Branch::query()
            ->select(['id', 'code', 'name'])
            ->whereKey($validated['branch_id'])
            ->firstOrFail();

        return response()->json([
            'data' => $this->catalog->forBranch($branch, $validated),
        ]);
    }

    public function mine(ListMyCommercialProductsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $context = $this->commercialContext->resolve($request->user());

        return response()->json([
            'data' => $this->catalog->forBranch($context['branch'], $validated),
        ]);
    }
}
