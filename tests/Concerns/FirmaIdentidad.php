<?php

namespace Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Simula identidad.juarezcampos.net en las pruebas: genera un par RSA, publica la llave
 * pública como JWKS (Http::fake) y firma tokens RS256 como lo hace identidad.
 */
trait FirmaIdentidad
{
    protected const IDENTIDAD = 'https://identidad.test';
    protected const KID = 'llave-prueba';

    protected string $llavePrivada = '';
    protected array $respuestaToken = [];
    protected int $estadoToken = 200;

    protected function prepararIdentidad(): void
    {
        // Llave fija solo para pruebas (tests/fixtures); no se usa en ningún entorno real.
        $this->llavePrivada = file_get_contents(base_path('tests/fixtures/identidad-prueba.key'));
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($this->llavePrivada))['rsa'];
        $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        config(['services.identidad' => array_merge(config('services.identidad'), [
            'url' => self::IDENTIDAD,
            'api_url' => self::IDENTIDAD.'/api',
            'issuer' => self::IDENTIDAD,
            'client_id' => 'cliente-istqb',
            'client_secret' => 'secreto-istqb',
            'redirect_uri' => 'https://istqb.test/callback',
            'post_logout_redirect_uri' => 'https://istqb.test/',
            'audience' => 'ISTQB',
        ])]);
        Cache::forget('identidad.jwks');

        $jwks = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => self::KID, 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]];
        Http::fake([
            self::IDENTIDAD.'/api/jwks.json' => Http::response($jwks),
            self::IDENTIDAD.'/api/oauth/token' => fn () => Http::response($this->respuestaToken, $this->estadoToken),
        ]);
    }

    protected function tokenIdentidad(array $roles = ['ADMIN'], array $claims = [], ?string $llave = null): string
    {
        $ahora = time();

        return JWT::encode(array_merge([
            'iss' => self::IDENTIDAD,
            'aud' => 'ISTQB',
            'sub' => '9b1c7c2e-0000-4000-8000-000000000001',
            'name' => 'Ana Pérez',
            'email' => 'ana@juarezcampos.net',
            'roles' => $roles,
            'iat' => $ahora,
            'nbf' => $ahora,
            'exp' => $ahora + 900,
            'jti' => Str::random(20),
        ], $claims), $llave ?? $this->llavePrivada, 'RS256', self::KID);
    }
}
