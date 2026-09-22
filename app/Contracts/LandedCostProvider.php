<?php

namespace App\Contracts;

use App\Support\LandedCost\LandedCostRequest;
use App\Support\LandedCost\LandedCostResult;

interface LandedCostProvider
{
    public function estimate(LandedCostRequest $request): LandedCostResult;
}
