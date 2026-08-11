<?php

namespace App\Providers;

use App\Services\FakeCs\FakeCsClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One shared client for the whole request/job lifecycle, pointed at
        // whatever fake-cs URL is configured in .env.
        $this->app->singleton(FakeCsClient::class, fn () => new FakeCsClient(
            config('services.fake_cs.base_url'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
