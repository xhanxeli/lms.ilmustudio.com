<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';
    protected $api_namespace = 'App\Http\Controllers\Api';


    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        //

        parent::boot();
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();

        $this->mapAffiliateApiRoutes();

        $this->mapSalesApiRoutes();

        $this->mapWebRoutes();

        $this->mapAdminRoutes();

        $this->mapPanelRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->api_namespace)
            ->group(base_path('routes/api.php'));

    }

    /**
     * Affiliate integration API (access key + secret headers, no global x-api-key).
     */
    protected function mapAffiliateApiRoutes()
    {
        Route::prefix('api')
            ->middleware([
                'throttle:120,1',
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \App\Http\Middleware\Api\SetLocale::class,
                \App\Http\Middleware\Api\CheckMaintenance::class,
                \App\Http\Middleware\Api\CheckRestrictionAPI::class,
                'affiliate.api',
            ])
            ->namespace($this->api_namespace)
            ->group(base_path('routes/api_affiliate.php'));
    }

    /**
     * Sales integration API (access key + secret headers, no global x-api-key).
     */
    protected function mapSalesApiRoutes()
    {
        Route::prefix('api')
            ->middleware([
                'throttle:120,1',
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \App\Http\Middleware\Api\SetLocale::class,
                \App\Http\Middleware\Api\CheckMaintenance::class,
                \App\Http\Middleware\Api\CheckRestrictionAPI::class,
                'sales.api',
            ])
            ->namespace($this->api_namespace)
            ->group(base_path('routes/api_sales.php'));
    }

    /**
     * Define the "admin" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapAdminRoutes()
    {
        Route::namespace($this->namespace)
            ->group(base_path('routes/admin.php'));
    }

    protected function mapPanelRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/panel.php'));
    }
}
