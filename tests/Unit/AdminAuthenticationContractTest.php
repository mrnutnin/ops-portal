<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePasswordChanged;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AdminAuthenticationContractTest extends TestCase
{
    public function test_auth_is_registration_disabled_and_admin_routes_are_protected(): void
    {
        $this->assertSame([Features::resetPasswords()], config('fortify.features'));
        $this->assertNull(Route::getRoutes()->getByName('register'));
        $this->assertContains(StartSession::class, app(Kernel::class)->getMiddlewareGroups()['web']);
        $users = Route::getRoutes()->getByName('admin.users.index');
        $this->assertContains('auth', $users->gatherMiddleware());
        $this->assertContains(EnsureActiveUser::class, $users->gatherMiddleware());
        $this->assertContains(EnsurePasswordChanged::class, $users->gatherMiddleware());
        $this->assertContains(EnsureAdmin::class, $users->gatherMiddleware());
        $this->assertNotNull(Route::getRoutes()->getByName('admin.users.store'));
        $this->assertContains('auth.session', $users->gatherMiddleware());
        $changePassword = Route::getRoutes()->getByName('account.password.edit');
        $this->assertNotContains(EnsurePasswordChanged::class, $changePassword->gatherMiddleware());
        $this->assertContains(EnsurePasswordChanged::class, Route::getRoutes()->getByName('home')->gatherMiddleware());
        $this->assertContains('auth', Route::getRoutes()->getByName('admin.audit.index')->gatherMiddleware());
        foreach ([
            'admin.customers.index', 'admin.customers.store', 'admin.customers.update', 'admin.customers.active',
            'admin.products.index', 'admin.products.store', 'admin.products.update', 'admin.products.active',
            'admin.instances.index', 'admin.instances.store', 'admin.instances.update', 'admin.instances.active',
            'admin.instances.entitlement.edit', 'admin.instances.entitlement.save', 'admin.instances.trial.store',
            'admin.instances.trial.production', 'admin.instances.trial.convert',
            'admin.plans.index', 'admin.plans.store', 'admin.plans.active',
        ] as $routeName) {
            $this->assertContains(EnsureAdmin::class, Route::getRoutes()->getByName($routeName)->gatherMiddleware());
        }
        $this->assertNull(Route::getRoutes()->getByName('owner.users.index'));
        $this->assertNull(app('Illuminate\\Contracts\\Console\\Kernel')->all()['ops:owner:create'] ?? null);
    }
}
