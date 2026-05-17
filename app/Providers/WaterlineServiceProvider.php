<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Waterline\Waterline;
use Waterline\WaterlineApplicationServiceProvider;

class WaterlineServiceProvider extends WaterlineApplicationServiceProvider
{
    protected function authorization()
    {
        $this->gate();

        Waterline::auth(function ($request) {
            if (filter_var(config('waterline.allow_unauthenticated'), FILTER_VALIDATE_BOOL)) {
                return true;
            }

            return Gate::check('viewWaterline', [$request->user()]) || app()->environment('local');
        });
    }

    /**
     * Register the Waterline gate.
     *
     * This gate determines who can access Waterline in non-local environments.
     *
     * @return void
     */
    protected function gate()
    {
        Gate::define('viewWaterline', function ($user = null) {
            return in_array($user?->email, [
                //
            ], true);
        });
    }
}
