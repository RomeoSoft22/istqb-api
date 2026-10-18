<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FirmaIdentidad;
use Tests\TestCase;

class IdentidadAuthTest extends TestCase
{
    use RefreshDatabase, FirmaIdentidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->prepararIdentidad();
    }

    public function test_token_valido_crea_usuario_espejo_con_rol_y_permisos(): void
    {
        $this->withToken($this->tokenIdentidad(['ADMIN']))->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('roles', ['Administrador'])
            ->assertJsonFragment(['reports.view']);

        $user = User::where('identidad_sub', '9b1c7c2e-0000-4000-8000-000000000001')->firstOrFail();
        $this->assertSame('ana@juarezcampos.net', $user->email);
    }

    public function test_usuario_local_existente_se_vincula_por_correo(): void
    {
        $antes = User::count();
        $this->withToken($this->tokenIdentidad(['ADMIN'], ['email' => 'admin@istqb.local', 'sub' => 'aaaa1111-0000-4000-8000-000000000002']))
            ->getJson('/api/auth/me')->assertOk();

        $this->assertSame($antes, User::count());
        $this->assertSame('aaaa1111-0000-4000-8000-000000000002', User::where('email', 'admin@istqb.local')->value('identidad_sub'));
    }

    public function test_rechaza_tokens_invalidos(): void
    {
        $otraLlave = file_get_contents(base_path('tests/fixtures/otra-llave-prueba.key'));

        $invalidos = [
            'sin token' => null,
            'otro sistema' => $this->tokenIdentidad(claims: ['aud' => 'PANEL']),
            'otro emisor' => $this->tokenIdentidad(claims: ['iss' => 'https://falso.test']),
            'vencido' => $this->tokenIdentidad(claims: ['exp' => time() - 120, 'iat' => time() - 1200, 'nbf' => time() - 1200]),
            'firma ajena' => $this->tokenIdentidad(llave: $otraLlave),
            'basura' => 'no.es.jwt',
        ];

        foreach ($invalidos as $caso => $token) {
            $this->app['auth']->forgetGuards();
            $peticion = $token ? $this->withToken($token) : $this;
            $this->assertSame(401, $peticion->getJson('/api/auth/me')->getStatusCode(), "Caso: {$caso}");
            $this->flushHeaders();
        }
    }

    public function test_permisos_siguen_al_rol_del_token(): void
    {
        $auditor = $this->tokenIdentidad(['AUDITOR']);
        $this->withToken($auditor)->getJson('/api/admin/reports')->assertOk();
        $this->withToken($auditor)->getJson('/api/admin/questions')->assertForbidden();

        // Si identidad le quita el rol, el siguiente token ya no da permisos.
        $this->app['auth']->forgetGuards(); // en producción cada petición resuelve el guard de nuevo
        $this->withToken($this->tokenIdentidad([]))->getJson('/api/admin/reports')->assertForbidden();
        $user = User::where('identidad_sub', '9b1c7c2e-0000-4000-8000-000000000001')->first();
        $this->assertSame(0, DB::table('user_roles')->where('user_id', $user->id)->count());
    }

    public function test_canje_de_codigo_devuelve_jwt_y_guarda_refresh_en_cookie_httponly(): void
    {
        $this->respuestaToken = ['access_token' => $this->tokenIdentidad(), 'refresh_token' => 'rt-1', 'expires_in' => 900, 'token_type' => 'Bearer'];

        $respuesta = $this->postJson('/api/auth/exchange', ['code' => 'codigo-1', 'code_verifier' => str_repeat('v', 64)])
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@juarezcampos.net')
            ->assertJsonPath('expires_in', 900);

        $cookie = collect($respuesta->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'istqb_refresh');
        $this->assertSame('rt-1', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());

        Http::assertSent(fn (PeticionHttp $r) => $r->url() === self::IDENTIDAD.'/api/oauth/token'
            && $r['grant_type'] === 'authorization_code'
            && $r['client_secret'] === 'secreto-istqb'
            && $r['code_verifier'] === str_repeat('v', 64)
            && $r['redirect_uri'] === 'https://istqb.test/callback');
    }

    public function test_canje_rechazado_por_identidad_responde_401(): void
    {
        $this->estadoToken = 400;
        $this->respuestaToken = ['error' => 'invalid_grant'];

        $this->postJson('/api/auth/exchange', ['code' => 'usado', 'code_verifier' => str_repeat('v', 64)])->assertUnauthorized();
    }

    public function test_refresh_usa_la_cookie_y_la_rota(): void
    {
        $this->postJson('/api/auth/refresh')->assertUnauthorized();

        $this->respuestaToken = ['access_token' => $this->tokenIdentidad(), 'refresh_token' => 'rt-2', 'expires_in' => 900];
        $respuesta = $this->withCredentials()->withUnencryptedCookie('istqb_refresh', 'rt-1')->postJson('/api/auth/refresh')->assertOk();

        $this->assertSame('rt-2', collect($respuesta->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'istqb_refresh')->getValue());
        Http::assertSent(fn (PeticionHttp $r) => $r->url() === self::IDENTIDAD.'/api/oauth/token' && $r['refresh_token'] === 'rt-1');
    }

    public function test_logout_borra_cookie_y_devuelve_url_de_identidad(): void
    {
        $respuesta = $this->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('logout_url', self::IDENTIDAD.'/logout?post_logout_redirect_uri='.urlencode('https://istqb.test/'));

        $cookie = collect($respuesta->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'istqb_refresh');
        $this->assertTrue($cookie->isCleared());
    }

    public function test_configuracion_publica_para_angular(): void
    {
        $this->getJson('/api/auth/config')->assertOk()->assertExactJson([
            'authorize_endpoint' => self::IDENTIDAD.'/authorize',
            'client_id' => 'cliente-istqb',
            'redirect_uri' => 'https://istqb.test/callback',
        ]);
    }

    public function test_ya_no_existe_login_local(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@istqb.local', 'password' => 'Cambiar123!'])->assertNotFound();
    }
}
