<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    // ---------- Login ----------

    public function test_login_con_credenciales_validas_devuelve_token(): void
    {
        $admin = $this->crearUsuario(User::ROLE_ADMIN);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $admin->email)
            ->assertJsonPath('data.user.role', User::ROLE_ADMIN)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['token', 'token_type', 'user' => ['id', 'name', 'email', 'role', 'status']],
            ]);

        $this->assertArrayNotHasKey('password', $response->json('data.user'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_con_contrasena_incorrecta_es_rechazado(): void
    {
        $operador = $this->crearUsuario();

        $this->postJson('/api/v1/auth/login', [
            'email' => $operador->email,
            'password' => 'contraseña-incorrecta',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Credenciales incorrectas.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_con_correo_inexistente_es_rechazado(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nadie@sigesta.test',
            'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_login_de_usuario_inactivo_es_rechazado(): void
    {
        $inactivo = $this->crearUsuario(User::ROLE_OPERADOR, User::STATUS_INACTIVE);

        $this->postJson('/api/v1/auth/login', [
            'email' => $inactivo->email,
            'password' => 'password',
        ])->assertForbidden()->assertJsonPath('status', 'error');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_valida_los_campos_obligatorios(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'no-es-un-correo'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // ---------- /me y logout ----------

    public function test_me_devuelve_el_usuario_del_token(): void
    {
        $operador = $this->crearUsuario();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $operador->email, 'password' => 'password'])
            ->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $operador->id)
            ->assertJsonPath('data.role', User::ROLE_OPERADOR);
    }

    public function test_logout_revoca_el_token(): void
    {
        $operador = $this->crearUsuario();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $operador->email, 'password' => 'password'])
            ->json('data.token');

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // El guard memoriza el usuario dentro de la misma prueba: se reinicia
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ---------- Protección de rutas y RBAC ----------

    public function test_rutas_protegidas_sin_token_devuelven_401(): void
    {
        $this->getJson('/api/v1/insumos')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_usuario_desactivado_con_token_vigente_es_bloqueado(): void
    {
        $operador = $this->crearUsuario(User::ROLE_OPERADOR, User::STATUS_INACTIVE);
        Sanctum::actingAs($operador);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'El usuario está inactivo. Contacte al administrador.');
    }

    public function test_operador_no_puede_modificar_el_catalogo(): void
    {
        $this->autenticarComo(User::ROLE_OPERADOR);

        $this->postJson('/api/v1/insumos', [
            'codigo' => 'INS-NEW',
            'nombre' => 'Sal yodada',
            'unidad_medida' => 'kg',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tiene permisos para realizar esta acción.');

        $this->assertDatabaseCount('insumos', 0);
    }

    public function test_operador_si_puede_consultar_el_catalogo(): void
    {
        $this->autenticarComo(User::ROLE_OPERADOR);

        $this->getJson('/api/v1/insumos')->assertOk()->assertJsonPath('status', 'success');
    }

    public function test_admin_puede_modificar_el_catalogo(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);

        $this->postJson('/api/v1/insumos', [
            'codigo' => 'INS-SAL',
            'nombre' => 'Sal yodada',
            'unidad_medida' => 'kg',
        ])->assertCreated();
    }
}
