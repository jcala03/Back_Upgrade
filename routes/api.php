<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BusinessSettingsController;
use App\Http\Controllers\Api\CalendarController;
use App\Http\Controllers\Api\CheckoutOrderController;
use App\Http\Controllers\Api\CheckoutPaymentController;
use App\Http\Controllers\Api\CommercialProductController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CrmNotificationController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerVehicleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmployeeAvailabilityController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeLeaveController;
use App\Http\Controllers\Api\EmployeeScheduleController;
use App\Http\Controllers\Api\EmployeeScheduleOverrideController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InventoryTransferController;
use App\Http\Controllers\Api\MyAppointmentController;
use App\Http\Controllers\Api\MyBusinessOverviewController;
use App\Http\Controllers\Api\MyCalendarController;
use App\Http\Controllers\Api\MyCommissionController;
use App\Http\Controllers\Api\MyGoalController;
use App\Http\Controllers\Api\MyQuotationController;
use App\Http\Controllers\Api\MySaleController;
use App\Http\Controllers\Api\MyTaskController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PersonalCommercialDiscoveryController;
use App\Http\Controllers\Api\ProductBrandController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuotationController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleBrandController;
use App\Http\Controllers\Api\VehicleModelController;
use App\Http\Controllers\Api\VehicleMultimediaSystemController;
use App\Http\Controllers\Api\VehicleVersionController;
use App\Http\Controllers\Api\WompiWebhookController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Exact stateless route: no Sanctum session/cookies/CSRF pipeline, including first-party Origin.
Route::post('/webhooks/wompi', WompiWebhookController::class)
    ->middleware('throttle:wompi-webhook')
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'message' => 'UP GRADE 79 API running',
    ]);
});

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

/*
|--------------------------------------------------------------------------
| Public ecommerce
|--------------------------------------------------------------------------
*/

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product:slug}', [ProductController::class, 'show']);

Route::get('/product-categories', [ProductCategoryController::class, 'index']);
Route::get('/product-brands', [ProductBrandController::class, 'index']);

Route::get('/vehicle-brands', [VehicleBrandController::class, 'index']);
Route::get('/vehicle-models', [VehicleModelController::class, 'index']);
Route::get('/vehicle-versions', [VehicleVersionController::class, 'index']);
Route::get('/vehicle-multimedia-systems', [VehicleMultimediaSystemController::class, 'index']);

Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:public-orders');
Route::get('/checkout/orders/{publicToken}', [CheckoutOrderController::class, 'show']);
Route::post('/checkout/orders/{publicToken}/payments/wompi', [CheckoutPaymentController::class, 'store'])->middleware('throttle:checkout-payment');
Route::put('/checkout/orders/{publicToken}/shipping-address', [CheckoutOrderController::class, 'address'])->middleware('throttle:checkout-mutations');
Route::post('/checkout/orders/{publicToken}/shipping-quotes', [CheckoutOrderController::class, 'quotes'])->middleware('throttle:checkout-shipping');
Route::post('/checkout/orders/{publicToken}/shipping-quotes/apply', [CheckoutOrderController::class, 'applyQuote'])->middleware('throttle:checkout-shipping');
Route::get('/checkout/orders/{publicToken}/pickup-branches', [CheckoutOrderController::class, 'pickupBranches']);
Route::post('/checkout/orders/{publicToken}/pickup', [CheckoutOrderController::class, 'applyPickup'])->middleware('throttle:checkout-mutations');

/*
|--------------------------------------------------------------------------
| Protected CRM / Admin
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/auth/me', [AuthController::class, 'updateProfile']);
    Route::patch('/auth/me/password', [AuthController::class, 'updatePassword']);

    Route::get('/my/appointments', [MyAppointmentController::class, 'index']);
    Route::get('/my/business-overview', MyBusinessOverviewController::class);
    Route::get('/my/appointments/{appointment}', [MyAppointmentController::class, 'show']);
    Route::get('/my/calendar', [MyCalendarController::class, 'index']);
    Route::get('/my/goals', [MyGoalController::class, 'index']);
    Route::post('/my/goals', [MyGoalController::class, 'store']);
    Route::get('/my/goals/{goal}', [MyGoalController::class, 'show']);
    Route::patch('/my/goals/{goal}', [MyGoalController::class, 'update']);
    Route::post('/my/goals/{goal}/progress', [MyGoalController::class, 'progress']);
    Route::post('/my/goals/{goal}/complete', [MyGoalController::class, 'complete']);
    Route::post('/my/goals/{goal}/cancel', [MyGoalController::class, 'cancel']);

    Route::get('/my/tasks', [MyTaskController::class, 'index']);
    Route::get('/my/tasks/{task}', [MyTaskController::class, 'show']);
    Route::post('/my/tasks/{task}/start', [MyTaskController::class, 'start']);
    Route::post('/my/tasks/{task}/complete', [MyTaskController::class, 'complete']);

    Route::get('/my/customer-options', [PersonalCommercialDiscoveryController::class, 'customers']);
    Route::get('/my/service-options', [PersonalCommercialDiscoveryController::class, 'services']);
    Route::get('/my/commercial-products', [CommercialProductController::class, 'mine']);

    Route::get('/my/quotations', [MyQuotationController::class, 'index']);
    Route::post('/my/quotations', [MyQuotationController::class, 'store']);
    Route::get('/my/quotations/{quotation}', [MyQuotationController::class, 'show']);
    Route::patch('/my/quotations/{quotation}', [MyQuotationController::class, 'update']);
    Route::post('/my/quotations/{quotation}/send', [MyQuotationController::class, 'send']);
    Route::post('/my/quotations/{quotation}/convert', [MyQuotationController::class, 'convert']);

    Route::get('/my/sales', [MySaleController::class, 'index']);
    Route::post('/my/sales', [MySaleController::class, 'store']);
    Route::get('/my/sales/{order}', [MySaleController::class, 'show']);
    Route::post('/my/sales/{order}/confirm', [MySaleController::class, 'confirm']);
    Route::post('/my/sales/{order}/complete', [MySaleController::class, 'complete']);
    Route::post('/my/sales/{order}/payments', [MySaleController::class, 'storePayment']);

    Route::get('/my/commissions', [MyCommissionController::class, 'index']);
    Route::get('/my/commissions/summary', [MyCommissionController::class, 'summary']);

    Route::prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/compare', [DashboardController::class, 'compare']);
        Route::get('/calendar', [CalendarController::class, 'index']);

        Route::get('/commissions', [CommissionController::class, 'index']);
        Route::get('/commissions/{commission}', [CommissionController::class, 'show']);

        Route::get('/goals', [GoalController::class, 'index']);
        Route::post('/goals', [GoalController::class, 'store']);
        Route::get('/goals/{goal}', [GoalController::class, 'show']);
        Route::patch('/goals/{goal}', [GoalController::class, 'update']);
        Route::post('/goals/{goal}/progress', [GoalController::class, 'progress']);
        Route::post('/goals/{goal}/complete', [GoalController::class, 'complete']);
        Route::post('/goals/{goal}/cancel', [GoalController::class, 'cancel']);

        Route::get('/settings', [BusinessSettingsController::class, 'show']);
        Route::patch('/settings', [BusinessSettingsController::class, 'update']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}/capabilities', [UserController::class, 'capabilities']);
        Route::put('/users/{user}/capabilities', [UserController::class, 'replaceCapabilities']);
        Route::patch('/users/{user}', [UserController::class, 'update']);

        Route::get('/branches', [BranchController::class, 'index']);
        Route::post('/branches', [BranchController::class, 'store']);
        Route::get('/branches/{branch}', [BranchController::class, 'show']);
        Route::patch('/branches/{branch}', [BranchController::class, 'update']);

        Route::get('/employees/availability', [EmployeeAvailabilityController::class, 'index']);
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
        Route::patch('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
        Route::post('/employees/{employee}/crm-access', [EmployeeController::class, 'grantCrmAccess']);

        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
        Route::patch('/appointments/{appointment}', [AppointmentController::class, 'update']);
        Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'status']);
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);

        Route::get('/tasks', [TaskController::class, 'index']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::get('/tasks/{task}', [TaskController::class, 'show']);
        Route::patch('/tasks/{task}', [TaskController::class, 'update']);
        Route::post('/tasks/{task}/start', [TaskController::class, 'start']);
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete']);
        Route::post('/tasks/{task}/cancel', [TaskController::class, 'cancel']);

        Route::get('/employee-schedules', [EmployeeScheduleController::class, 'index']);
        Route::post('/employee-schedules', [EmployeeScheduleController::class, 'store']);
        Route::patch('/employee-schedules/{schedule}', [EmployeeScheduleController::class, 'update']);
        Route::delete('/employee-schedules/{schedule}', [EmployeeScheduleController::class, 'destroy']);

        Route::get('/employee-schedule-overrides', [EmployeeScheduleOverrideController::class, 'index']);
        Route::post('/employee-schedule-overrides', [EmployeeScheduleOverrideController::class, 'store']);
        Route::patch('/employee-schedule-overrides/{override}', [EmployeeScheduleOverrideController::class, 'update']);
        Route::delete('/employee-schedule-overrides/{override}', [EmployeeScheduleOverrideController::class, 'destroy']);

        Route::get('/employee-leaves', [EmployeeLeaveController::class, 'index']);
        Route::post('/employee-leaves', [EmployeeLeaveController::class, 'store']);
        Route::get('/employee-leaves/{leave}', [EmployeeLeaveController::class, 'show']);
        Route::patch('/employee-leaves/{leave}', [EmployeeLeaveController::class, 'update']);
        Route::post('/employee-leaves/{leave}/cancel', [EmployeeLeaveController::class, 'cancel']);

        Route::prefix('reports')->group(function () {
            Route::get('/sales', [ReportsController::class, 'sales']);
            Route::get('/sales/export', [ReportsController::class, 'exportSales']);
            Route::get('/products', [ReportsController::class, 'products']);
            Route::get('/products/export', [ReportsController::class, 'exportProducts']);
            Route::get('/payments', [ReportsController::class, 'payments']);
            Route::get('/payments/export', [ReportsController::class, 'exportPayments']);
            Route::get('/receivables', [ReportsController::class, 'receivables']);
            Route::get('/receivables/export', [ReportsController::class, 'exportReceivables']);
            Route::get('/inventory', [ReportsController::class, 'inventory']);
            Route::get('/inventory/export', [ReportsController::class, 'exportInventory']);
            Route::get('/inventory-movements', [ReportsController::class, 'inventoryMovements']);
            Route::get('/inventory-movements/export', [ReportsController::class, 'exportInventoryMovements']);
            Route::get('/quotations', [ReportsController::class, 'quotations']);
            Route::get('/quotations/export', [ReportsController::class, 'exportQuotations']);
            Route::get('/customers', [ReportsController::class, 'customers']);
            Route::get('/customers/export', [ReportsController::class, 'exportCustomers']);
        });

        /*
        |--------------------------------------------------------------------------
        | Products / Inventory products
        |--------------------------------------------------------------------------
        */

        Route::get('/commercial-products', [CommercialProductController::class, 'admin']);

        Route::get('/products', [ProductController::class, 'adminIndex']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::post('/products/{product:id}', [ProductController::class, 'update']);
        Route::delete('/products/{product:id}', [ProductController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Services catalog
        |--------------------------------------------------------------------------
        */

        Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
        Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
        Route::patch('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update']);
        Route::delete('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'destroy']);

        Route::get('/services', [ServiceController::class, 'index']);
        Route::post('/services', [ServiceController::class, 'store']);
        Route::get('/services/{service}', [ServiceController::class, 'show']);
        Route::patch('/services/{service}', [ServiceController::class, 'update']);
        Route::delete('/services/{service}', [ServiceController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Product categories
        |--------------------------------------------------------------------------
        */

        Route::get('/product-categories', [ProductCategoryController::class, 'adminIndex']);
        Route::post('/product-categories', [ProductCategoryController::class, 'store']);
        Route::post('/product-categories/{productCategory}', [ProductCategoryController::class, 'update']);
        Route::delete('/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Product brands
        |--------------------------------------------------------------------------
        */

        Route::get('/product-brands', [ProductBrandController::class, 'adminIndex']);
        Route::post('/product-brands', [ProductBrandController::class, 'store']);
        Route::post('/product-brands/{productBrand}', [ProductBrandController::class, 'update']);
        Route::delete('/product-brands/{productBrand}', [ProductBrandController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Vehicle brands
        |--------------------------------------------------------------------------
        */

        Route::get('/vehicle-brands', [VehicleBrandController::class, 'adminIndex']);
        Route::post('/vehicle-brands', [VehicleBrandController::class, 'store']);
        Route::post('/vehicle-brands/{vehicleBrand}', [VehicleBrandController::class, 'update']);
        Route::delete('/vehicle-brands/{vehicleBrand}', [VehicleBrandController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Vehicle models
        |--------------------------------------------------------------------------
        */

        Route::get('/vehicle-models', [VehicleModelController::class, 'adminIndex']);
        Route::post('/vehicle-models', [VehicleModelController::class, 'store']);
        Route::post('/vehicle-models/{vehicleModel}', [VehicleModelController::class, 'update']);
        Route::delete('/vehicle-models/{vehicleModel}', [VehicleModelController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Vehicle versions / generations
        |--------------------------------------------------------------------------
        */

        Route::get('/vehicle-versions', [VehicleVersionController::class, 'adminIndex']);
        Route::post('/vehicle-versions', [VehicleVersionController::class, 'store']);
        Route::post('/vehicle-versions/{vehicleVersion}', [VehicleVersionController::class, 'update']);
        Route::put('/vehicle-versions/{vehicleVersion}/multimedia-systems', [VehicleVersionController::class, 'syncMultimediaSystems']);
        Route::delete('/vehicle-versions/{vehicleVersion}', [VehicleVersionController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Vehicle multimedia systems
        |--------------------------------------------------------------------------
        */

        Route::get('/vehicle-multimedia-systems', [VehicleMultimediaSystemController::class, 'adminIndex']);
        Route::post('/vehicle-multimedia-systems', [VehicleMultimediaSystemController::class, 'store']);
        Route::post('/vehicle-multimedia-systems/{vehicleMultimediaSystem}', [VehicleMultimediaSystemController::class, 'update']);
        Route::delete('/vehicle-multimedia-systems/{vehicleMultimediaSystem}', [VehicleMultimediaSystemController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | Inventory
        |--------------------------------------------------------------------------
        */

        Route::get('/inventory', [InventoryController::class, 'overview']);
        Route::get('/inventory/stocks', [InventoryController::class, 'stocks']);
        Route::patch('/inventory/stocks/minimum', [InventoryController::class, 'updateMinimum']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::post('/inventory/movements', [InventoryController::class, 'storeMovement']);

        Route::get('/inventory/transfers', [InventoryTransferController::class, 'index']);
        Route::post('/inventory/transfers', [InventoryTransferController::class, 'store']);
        Route::get('/inventory/transfers/{transfer}', [InventoryTransferController::class, 'show']);
        Route::post('/inventory/transfers/{transfer}/dispatch', [InventoryTransferController::class, 'dispatch']);
        Route::post('/inventory/transfers/{transfer}/receive', [InventoryTransferController::class, 'receive']);
        Route::post('/inventory/transfers/{transfer}/cancel', [InventoryTransferController::class, 'cancel']);

        /*
        |--------------------------------------------------------------------------
        | Orders
        |--------------------------------------------------------------------------
        */

        Route::get('/orders', [OrderController::class, 'adminIndex']);
        Route::post('/orders', [OrderController::class, 'adminStore']);
        Route::get('/orders/{order}', [OrderController::class, 'adminShow']);
        Route::post('/orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::post('/orders/{order}/payments', [OrderController::class, 'storePayment']);

        /*
        |--------------------------------------------------------------------------
        | Quotations
        |--------------------------------------------------------------------------
        */

        Route::get('/quotations', [QuotationController::class, 'index']);
        Route::post('/quotations', [QuotationController::class, 'store']);
        Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
        Route::match(['put', 'patch'], '/quotations/{quotation}', [QuotationController::class, 'update']);
        Route::post('/quotations/{quotation}/status', [QuotationController::class, 'updateStatus']);
        Route::post('/quotations/{quotation}/convert', [QuotationController::class, 'convert']);

        /*
        |--------------------------------------------------------------------------
        | Customers / customer vehicles
        |--------------------------------------------------------------------------
        */

        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update']);
        Route::get('/customers/{customer}/orders', [CustomerController::class, 'orders']);

        Route::get('/customers/{customer}/vehicles', [CustomerVehicleController::class, 'index']);
        Route::post('/customers/{customer}/vehicles', [CustomerVehicleController::class, 'store']);
        Route::get('/customers/{customer}/vehicles/{vehicle}', [CustomerVehicleController::class, 'show']);
        Route::match(['put', 'patch'], '/customers/{customer}/vehicles/{vehicle}', [CustomerVehicleController::class, 'update']);
        Route::get('/customers/{customer}/vehicles/{vehicle}/orders', [CustomerVehicleController::class, 'orders']);

        /*
        |--------------------------------------------------------------------------
        | Notifications
        |--------------------------------------------------------------------------
        */

        Route::get('/notifications', [CrmNotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [CrmNotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [CrmNotificationController::class, 'markAllAsRead']);
        Route::post('/notifications/{crmNotification}/read', [CrmNotificationController::class, 'markAsRead']);
    });
});

Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
