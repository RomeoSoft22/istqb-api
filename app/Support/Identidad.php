<?php

namespace App\Support;

use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Cliente de identidad.juarezcampos.net: valida los JWT RS256 con la llave pública (JWKS),
 * canjea códigos y refresh tokens, y mantiene el espejo local del usuario.
 */
class Identidad
{
    private const CACHE_JWKS = 'identidad.jwks';

    public static function config(string $key): mixed
    {
        return config('services.identidad.'.$key);
    }

    /**
     * Verifica firma, vencimiento, emisor y audiencia. Devuelve los claims.
     *
     * @throws UnexpectedValueException
     */
    public static function verificar(string $jwt): array
    {
        JWT::$leeway = 30;

        try {
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(self::jwks(), 'RS256'));
        } catch (UnexpectedValueException $e) {
            // Llave desconocida: puede que identidad haya rotado su llave. Se recarga el JWKS una vez.
            if (! str_contains($e->getMessage(), '"kid"')) {
                throw $e;
            }
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(self::jwks(true), 'RS256'));
        }

        if (($claims['iss'] ?? null) !== self::config('issuer')) {
            throw new UnexpectedValueException('Emisor inválido.');
        }
        if (! in_array(self::config('audience'), (array) ($claims['aud'] ?? []), true)) {
            throw new UnexpectedValueException('El token no es para este sistema.');
        }
        if (empty($claims['sub'])) {
            throw new UnexpectedValueException('Token sin sujeto.');
        }

        return $claims;
    }

    public static function jwks(bool $recargar = false): array
    {
        if ($recargar) {
            Cache::forget(self::CACHE_JWKS);
        }

        return Cache::remember(self::CACHE_JWKS, 3600, fn () => Http::timeout(10)
            ->get(self::config('api_url').'/jwks.json')
            ->throw()
            ->json());
    }

    /**
     * Busca o crea el usuario espejo y sincroniza su rol local con los roles del token.
     */
    public static function usuarioDesde(array $claims): User
    {
        return DB::transaction(function () use ($claims) {
            $email = Str::lower((string) ($claims['email'] ?? ''));
            $user = User::withTrashed()->where('identidad_sub', $claims['sub'])->first()
                ?? ($email !== '' ? User::withTrashed()->whereNull('identidad_sub')->where('email', $email)->first() : null)
                ?? new User(['password' => Str::random(64)]);

            $user->forceFill([
                'identidad_sub' => $claims['sub'],
                'name' => $claims['name'] ?? $user->name ?? $email,
                'email' => $email !== '' ? $email : $user->email,
                'status' => 'active',
                'deleted_at' => null,
            ]);
            if ($user->isDirty()) {
                $user->save();
            }

            $rolesLocales = DB::table('roles')
                ->whereIn('name', collect((array) ($claims['roles'] ?? []))->map(fn ($r) => self::config('roles')[$r] ?? null)->filter())
                ->pluck('id')->sort()->values()->all();
            $actuales = DB::table('user_roles')->where('user_id', $user->id)->orderBy('role_id')->pluck('role_id')->all();
            if ($rolesLocales != $actuales) {
                DB::table('user_roles')->where('user_id', $user->id)->delete();
                DB::table('user_roles')->insert(array_map(fn ($id) => ['user_id' => $user->id, 'role_id' => $id], $rolesLocales));
            }

            return $user;
        });
    }

    public static function canjearCodigo(string $code, string $verifier): Response
    {
        return self::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => self::config('redirect_uri'),
        ]);
    }

    public static function renovar(string $refreshToken): Response
    {
        return self::token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private static function token(array $datos): Response
    {
        return Http::asForm()->acceptJson()->timeout(15)->post(self::config('api_url').'/oauth/token', $datos + [
            'client_id' => self::config('client_id'),
            'client_secret' => self::config('client_secret'),
        ]);
    }
}
