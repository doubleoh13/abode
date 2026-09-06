<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Financial\Account;
use App\Models\Financial\Commodity;
use App\Models\Financial\Institution;
use App\Models\Financial\Payee;
use App\Models\Financial\Transaction;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => $user->hasPermission($permission),
            );
        }

        Relation::enforceMorphMap([
            'user' => User::class,
            'financial.account' => Account::class,
            'financial.commodity' => Commodity::class,
            'financial.institution' => Institution::class,
            'financial.payee' => Payee::class,
            'financial.transaction' => Transaction::class,
        ]);
    }
}
