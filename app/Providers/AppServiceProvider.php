<?php

namespace App\Providers;

use App\Support\Identidad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
        // Guard "api": acepta el JWT RS256 emitido por identidad.juarezcampos.net para ISTQB.
        Auth::viaRequest('identidad', function (Request $request) {
            $token = $request->bearerToken();
            if (! $token) {
                return null;
            }

            try {
                return Identidad::usuarioDesde(Identidad::verificar($token));
            } catch (Throwable) {
                return null;
            }
        });
    }
}
