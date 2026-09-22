<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CustomersReportRequest;
use App\Http\Requests\Reports\InventoryMovementsReportRequest;
use App\Http\Requests\Reports\InventoryReportRequest;
use App\Http\Requests\Reports\PaymentsReportRequest;
use App\Http\Requests\Reports\ProductsReportRequest;
use App\Http\Requests\Reports\QuotationsReportRequest;
use App\Http\Requests\Reports\ReceivablesReportRequest;
use App\Http\Requests\Reports\SalesReportRequest;
use App\Services\Reports\CustomerReportService;
use App\Services\Reports\InventoryReportService;
use App\Services\Reports\PaymentReportService;
use App\Services\Reports\QuotationReportService;
use App\Services\Reports\ReportExportService;
use App\Services\Reports\SalesReportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportsController extends Controller
{
    public function __construct(
        private readonly SalesReportService $sales,
        private readonly PaymentReportService $payments,
        private readonly InventoryReportService $inventory,
        private readonly QuotationReportService $quotations,
        private readonly CustomerReportService $customers,
        private readonly ReportExportService $exports,
    ) {}

    public function sales(SalesReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->sales->sales($request->filters(), $request->user())]);
    }

    public function products(ProductsReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->sales->products($request->filters(), $request->user())]);
    }

    public function payments(PaymentsReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->payments->payments($request->filters())]);
    }

    public function receivables(ReceivablesReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->payments->receivables($request->filters())]);
    }

    public function inventory(InventoryReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->inventory->inventory($request->filters(), $request->user())]);
    }

    public function inventoryMovements(InventoryMovementsReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->inventory->movements($request->filters())]);
    }

    public function quotations(QuotationsReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->quotations->report($request->filters())]);
    }

    public function customers(CustomersReportRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->customers->report($request->filters())]);
    }

    public function exportSales(SalesReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('sales', $request->filters(), $request->user());
    }

    public function exportProducts(ProductsReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('products', $request->filters(), $request->user());
    }

    public function exportPayments(PaymentsReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('payments', $request->filters(), $request->user());
    }

    public function exportReceivables(ReceivablesReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('receivables', $request->filters(), $request->user());
    }

    public function exportInventory(InventoryReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('inventory', $request->filters(), $request->user());
    }

    public function exportInventoryMovements(InventoryMovementsReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('inventory-movements', $request->filters(), $request->user());
    }

    public function exportQuotations(QuotationsReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('quotations', $request->filters(), $request->user());
    }

    public function exportCustomers(CustomersReportRequest $request): BinaryFileResponse
    {
        return $this->exports->download('customers', $request->filters(), $request->user());
    }
}
