<?php

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\AdminInstanceEntitlementController;
use App\Http\Controllers\AdminPlanController;
use App\Http\Controllers\AdminRegistryController;
use App\Http\Controllers\AdminRenewalController;
use App\Http\Controllers\AdminTrialController;
use App\Http\Controllers\AdminUserController;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('home')
    : redirect()->route('login'));

Route::middleware(['auth', 'auth.session', EnsureActiveUser::class])->group(function () {
    Route::get('/account/password', [AccountPasswordController::class, 'edit'])->name('account.password.edit');
    Route::put('/account/password', [AccountPasswordController::class, 'update'])->name('account.password.update');

    Route::middleware(EnsurePasswordChanged::class)->group(function () {
        Route::get('/home', fn (Request $request) => $request->user()->is_admin
            ? redirect()->route('admin.users.index')
            : view('home'))->name('home');

        Route::middleware(EnsureAdmin::class)->prefix('admin')->name('admin.')->group(function () {
            Route::get('/customers', [AdminRegistryController::class, 'customers'])->name('customers.index');
            Route::post('/customers', [AdminRegistryController::class, 'storeCustomer'])->name('customers.store');
            Route::patch('/customers/{customer}', [AdminRegistryController::class, 'updateCustomer'])->name('customers.update');
            Route::patch('/customers/{customer}/active', [AdminRegistryController::class, 'setCustomerActive'])->name('customers.active');
            Route::get('/products', [AdminRegistryController::class, 'products'])->name('products.index');
            Route::post('/products', [AdminRegistryController::class, 'storeProduct'])->name('products.store');
            Route::patch('/products/{product}', [AdminRegistryController::class, 'updateProduct'])->name('products.update');
            Route::patch('/products/{product}/active', [AdminRegistryController::class, 'setProductActive'])->name('products.active');
            Route::get('/instances', [AdminRegistryController::class, 'instances'])->name('instances.index');
            Route::post('/instances', [AdminRegistryController::class, 'storeInstance'])->name('instances.store');
            Route::patch('/instances/{instance}', [AdminRegistryController::class, 'updateInstance'])->name('instances.update');
            Route::patch('/instances/{instance}/active', [AdminRegistryController::class, 'setInstanceActive'])->name('instances.active');
            Route::get('/instances/{instance}/entitlement', [AdminInstanceEntitlementController::class, 'edit'])->name('instances.entitlement.edit');
            Route::put('/instances/{instance}/entitlement', [AdminInstanceEntitlementController::class, 'save'])->name('instances.entitlement.save');
            Route::patch('/instances/{instance}/delivery-target', [AdminInstanceEntitlementController::class, 'changeTarget'])->name('instances.delivery-target.update');
            Route::post('/instances/{instance}/enrollment', [AdminInstanceEntitlementController::class, 'enrollWeb'])->middleware('throttle:5,1')->name('instances.enrollment');
            Route::post('/instances/{instance}/push', [AdminInstanceEntitlementController::class, 'pushWeb'])->middleware('throttle:5,1')->name('instances.push');
            Route::post('/instances/{instance}/local-enrollment', [AdminInstanceEntitlementController::class, 'enrollLocal'])->middleware('throttle:5,1')->name('instances.local-enrollment');
            Route::post('/instances/{instance}/local-push', [AdminInstanceEntitlementController::class, 'pushLocal'])->middleware('throttle:5,1')->name('instances.local-push');
            Route::post('/instances/{instance}/trial', [AdminTrialController::class, 'store'])->name('instances.trial.store');
            Route::post('/instances/{instance}/trial/production', [AdminTrialController::class, 'enableProduction'])->name('instances.trial.production');
            Route::post('/instances/{instance}/trial/convert', [AdminTrialController::class, 'convertToPaid'])->name('instances.trial.convert');
            Route::get('/renewals', [AdminRenewalController::class, 'index'])->name('renewals.index');
            Route::get('/instances/{instance}/renewals/create', [AdminRenewalController::class, 'create'])->name('renewals.create');
            Route::post('/instances/{instance}/renewals', [AdminRenewalController::class, 'store'])->name('renewals.store');
            Route::get('/renewals/{renewal}', [AdminRenewalController::class, 'show'])->name('renewals.show');
            Route::patch('/renewals/{renewal}', [AdminRenewalController::class, 'update'])->name('renewals.update');
            Route::post('/renewals/{renewal}/confirm', [AdminRenewalController::class, 'confirm'])->name('renewals.confirm');
            Route::post('/renewals/{renewal}/apply', [AdminRenewalController::class, 'apply'])->name('renewals.apply');
            Route::post('/renewals/{renewal}/void', [AdminRenewalController::class, 'void'])->name('renewals.void');
            Route::get('/renewals/{renewal}/evidence', [AdminRenewalController::class, 'evidence'])->name('renewals.evidence');
            Route::get('/plans', [AdminPlanController::class, 'index'])->name('plans.index');
            Route::post('/plans', [AdminPlanController::class, 'store'])->name('plans.store');
            Route::patch('/plans/{plan}/active', [AdminPlanController::class, 'setActive'])->name('plans.active');
            Route::get('/audit', [AdminUserController::class, 'auditIndex'])->name('audit.index');
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
            Route::patch('/users/{user}/active', [AdminUserController::class, 'toggleActive'])->name('users.active');
        });
    });
});
