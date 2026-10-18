<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Identidad;
use Illuminate\Http\Client\Response as RespuestaIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * El ingreso ocurre en identidad.juarezcampos.net. Este controlador solo canjea el código,
 * renueva el access token con el refresh token (guardado en cookie HttpOnly) y cierra sesión.
 */
class AuthController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'authorize_endpoint' => Identidad::config('url').'/authorize',
            'client_id' => Identidad::config('client_id'),
            'redirect_uri' => Identidad::config('redirect_uri'),
        ]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
        ]);

        return $this->responderTokens($request, Identidad::canjearCodigo($data['code'], $data['code_verifier']));
    }

    public function refresh(Request $request): JsonResponse
    {
        $refresh = $request->cookie(Identidad::config('refresh_cookie'));
        if (! $refresh) {
            return response()->json(['message' => 'No hay sesión.'], 401);
        }

        return $this->responderTokens($request, Identidad::renovar($refresh));
    }

    public function me(): JsonResponse
    {
        $user = auth('api')->user();
        $roles = DB::table('user_roles as ur')->join('roles as r', 'r.id', '=', 'ur.role_id')->where('ur.user_id', $user->id)->pluck('r.name');
        $permissions = DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('ur.user_id', $user->id)->distinct()->pluck('p.name');

        return response()->json(['user' => $user->only(['id', 'name', 'email']), 'roles' => $roles, 'permissions' => $permissions]);
    }

    public function logout(Request $request): JsonResponse
    {
        $destino = Identidad::config('post_logout_redirect_uri');
        $url = Identidad::config('url').'/logout'.($destino ? '?post_logout_redirect_uri='.urlencode($destino) : '');

        return response()->json(['logout_url' => $url])->withCookie($this->olvidarCookie($request));
    }

    private function responderTokens(Request $request, RespuestaIdentidad $respuesta): JsonResponse
    {
        $tokens = $respuesta->json();
        if (! $respuesta->successful() || empty($tokens['access_token'])) {
            Log::info('identidad rechazó la emisión de token', ['status' => $respuesta->status(), 'error' => $tokens['error'] ?? null]);

            return response()->json(['message' => 'La sesión no es válida o expiró. Vuelve a ingresar.'], 401)
                ->withCookie($this->olvidarCookie($request));
        }

        try {
            $user = Identidad::usuarioDesde(Identidad::verificar($tokens['access_token']));
        } catch (Throwable $e) {
            Log::warning('token de identidad inválido', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'No se pudo validar el token de identidad.'], 401);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $respuestaJson = response()->json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) ($tokens['expires_in'] ?? 900),
            'user' => $user->only(['id', 'name', 'email']),
        ]);

        return empty($tokens['refresh_token'])
            ? $respuestaJson
            : $respuestaJson->withCookie($this->cookie($request, $tokens['refresh_token'], Identidad::config('refresh_minutes')));
    }

    private function cookie(Request $request, string $valor, int $minutos): Cookie
    {
        return cookie(Identidad::config('refresh_cookie'), $valor, $minutos, '/', null, $request->isSecure(), true, false, 'strict');
    }

    private function olvidarCookie(Request $request): Cookie
    {
        return $this->cookie($request, '', -2628000);
    }
}
