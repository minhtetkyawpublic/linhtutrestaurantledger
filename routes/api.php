<?php

use App\Http\Controllers\AdminPermissionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurryItemController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function (Request $request) {
    try {
        DB::select('SELECT 1');
    } catch (Throwable) {
        return response()->json([
            'ok' => false,
            'app' => config('app.name'),
            'timezone' => config('app.timezone'),
            'database_ok' => false,
            'path' => $request->path(),
        ], 503);
    }

    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'timezone' => config('app.timezone'),
        'database_ok' => true,
        'path' => $request->path(),
    ]);
});

Route::middleware('web')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::get('/auth/session', [AuthController::class, 'session']);

    Route::middleware(['auth', 'active'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/locale', [AuthController::class, 'setLocale']);

        Route::get('/dashboard', [DashboardController::class, 'overview'])
            ->middleware('permission:view_dashboard');

        Route::prefix('admin')->group(function () {
            Route::middleware('permission:manage_staff_and_permissions')->group(function () {
                Route::get('/roles', [AdminPermissionController::class, 'roles']);
                Route::get('/permissions', [AdminPermissionController::class, 'permissions']);
                Route::get('/staff', [AdminPermissionController::class, 'staff']);
                Route::post('/staff', [AdminPermissionController::class, 'createStaff']);
                Route::put('/staff/{user}', [AdminPermissionController::class, 'updateStaff']);
                Route::put('/staff/{user}/password', [AdminPermissionController::class, 'resetPassword']);
                Route::put('/roles/{role}/permissions', [AdminPermissionController::class, 'updateRolePermissions']);
                Route::put('/users/{user}/disabled', [AdminPermissionController::class, 'toggleUserDisable']);
            });
            Route::get('/audit-history', [AdminPermissionController::class, 'auditHistory'])
                ->middleware('permission:view_audit_history');
        });

        Route::middleware('permission:manage_curry_items')->group(function () {
            Route::get('/curry-items', [CurryItemController::class, 'index']);
            Route::post('/curry-items', [CurryItemController::class, 'store']);
            Route::put('/curry-items/{curry_item}', [CurryItemController::class, 'update']);
            Route::post('/curry-items/{curry_item}/archive', [CurryItemController::class, 'archive']);
        });

        Route::middleware('permission:view_customers')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger'])
                ->middleware('permission:view_customer_statements');
        });

        Route::middleware('permission:create_edit_customers')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        });

        Route::middleware('permission:record_customer_payment')->group(function () {
            Route::post('/customers/{customer}/payments', [CustomerController::class, 'recordPayment']);
        });

        Route::middleware('permission:record_money_given_lent')->group(function () {
            Route::post('/customers/{customer}/money-lent', [CustomerController::class, 'recordMoneyLent']);
        });

        Route::middleware('permission:correct_reverse_ledger')->group(function () {
            Route::post('/customers/{customer}/ledger/{ledger_entry}/correct', [CustomerController::class, 'correctLedgerEntry']);
            Route::post('/customers/{customer}/ledger/{ledger_entry}/reverse', [CustomerController::class, 'reverseLedgerEntry']);
        });

        Route::middleware('permission:view_sales_history')->group(function () {
            Route::get('/histories', [HistoryController::class, 'index']);
            Route::get('/histories/filter-options', [HistoryController::class, 'filterOptions']);
            Route::get('/histories/sales/{sale}', [HistoryController::class, 'sale']);
        });

        Route::middleware('permission:create_sale')->group(function () {
            Route::get('/sales/create-options', [SaleController::class, 'createOptions']);
            Route::post('/sales', [SaleController::class, 'store']);
        });

        Route::middleware('permission:edit_sale')->group(function () {
            Route::get('/sales/edit-options', [SaleController::class, 'editOptions']);
            Route::put('/sales/{sale}', [SaleController::class, 'update']);
        });

        Route::middleware('permission:delete_reverse_sale')->group(function () {
            Route::post('/sales/{sale}/reverse', [SaleController::class, 'reverse']);
        });

        Route::middleware('permission:view_reports')->group(function () {
            Route::get('/reports/filter-options', [ReportController::class, 'filterOptions']);
            Route::get('/reports/sales-summary', [ReportController::class, 'salesSummary']);
            Route::get('/reports/customer-balances', [ReportController::class, 'customerBalances']);
            Route::get('/reports/top-curries', [ReportController::class, 'topCurries']);
        });
    });
});
