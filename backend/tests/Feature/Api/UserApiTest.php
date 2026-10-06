<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use ApiTestHelpers;
    use RefreshDatabase;

    public function test_admin_crea_un_operador_que_puede_iniciar_sesion(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);

        $this->postJson('/api/v1/usuarios', [
            'name' => 'María Quispe',
            'email' => 'maria.quispe@sigesta.com',
            'password' => 'Operador2026',
            'password_confirmation' => 'Operador2026',
            'role' => User::ROLE_OPERADOR,
        ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'maria.quispe@sigesta.com')
            ->assertJsonPath('data.role', User::ROLE_OPERADOR)
            ->assertJsonPath('data.status', User::STATUS_ACTIVE)
            ->assertJsonMissingPath('data.password');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'maria.quispe@sigesta.com',
            'password' => 'Operador2026',
        ])->assertOk();
    }

    public function test_valida_correo_unico_y_contrasena_segura(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $existente = $this->crearUsuario();

        $this->postJson('/api/v1/usuarios', [
            'name' => 'Duplicado',
            'email' => $existente->email,
            'password' => 'corta',
            'password_confirmation' => 'distinta',
            'role' => 'superusuario',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'role']);
    }

    public function test_lista_y_filtra_usuarios(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $this->crearUsuario(User::ROLE_OPERADOR);
        $this->crearUsuario(User::ROLE_OPERADOR, User::STATUS_INACTIVE);

        $this->getJson('/api/v1/usuarios?role=operador')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/usuarios?role=operador&status=inactive')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/usuarios?role=admin')->assertJsonCount(1, 'data');
    }

    public function test_operador_no_accede_a_la_administracion_de_usuarios(): void
    {
        $this->autenticarComo(User::ROLE_OPERADOR);

        $this->getJson('/api/v1/usuarios')->assertForbidden();
        $this->postJson('/api/v1/usuarios', [])->assertForbidden();
    }

    public function test_desactivar_a_un_operador_revoca_sus_sesiones_y_bloquea_su_acceso(): void
    {
        $admin = $this->crearUsuario(User::ROLE_ADMIN);
        $operador = $this->crearUsuario(User::ROLE_OPERADOR);

        $tokenAdmin = $admin->createToken('test')->plainTextToken;
        $tokenOperador = $operador->createToken('test')->plainTextToken;

        $this->withToken($tokenOperador)->getJson('/api/v1/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenAdmin)
            ->patchJson("/api/v1/usuarios/{$operador->id}", ['status' => User::STATUS_INACTIVE])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_INACTIVE)
            ->assertJsonPath('message', 'Usuario actualizado. Sus sesiones activas fueron cerradas.');

        $this->assertSame(0, $operador->tokens()->count());

        // Su token anterior ya no sirve
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenOperador)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Y tampoco puede volver a iniciar sesión
        $this->postJson('/api/v1/auth/login', ['email' => $operador->email, 'password' => 'password'])
            ->assertForbidden();
    }

    public function test_cambiar_el_rol_revoca_los_tokens_del_usuario(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $operador = $this->crearUsuario(User::ROLE_OPERADOR);
        $operador->createToken('sesion-1');
        $operador->createToken('sesion-2');

        $this->patchJson("/api/v1/usuarios/{$operador->id}", ['role' => User::ROLE_ADMIN])
            ->assertOk()
            ->assertJsonPath('data.role', User::ROLE_ADMIN);

        $this->assertSame(0, $operador->tokens()->count());
    }

    public function test_actualizar_solo_el_nombre_no_cierra_sesiones(): void
    {
        $this->autenticarComo(User::ROLE_ADMIN);
        $operador = $this->crearUsuario(User::ROLE_OPERADOR);
        $operador->createToken('sesion-1');

        $this->patchJson("/api/v1/usuarios/{$operador->id}", ['name' => 'Juan Mamani'])
            ->assertOk()
            ->assertJsonPath('message', 'Usuario actualizado correctamente.');

        $this->assertSame(1, $operador->tokens()->count());
    }

    public function test_el_administrador_no_puede_bloquearse_a_si_mismo(): void
    {
        $admin = $this->autenticarComo(User::ROLE_ADMIN);

        $this->patchJson("/api/v1/usuarios/{$admin->id}", ['status' => User::STATUS_INACTIVE])
            ->assertUnprocessable();

        $this->patchJson("/api/v1/usuarios/{$admin->id}", ['role' => User::ROLE_OPERADOR])
            ->assertUnprocessable();

        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
    }

    public function test_los_tokens_expiran_despues_de_ocho_horas(): void
    {
        $this->assertSame(480, config('sigesta.token_expiracion_minutos'));

        $operador = $this->crearUsuario();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $operador->email, 'password' => 'password'])
            ->assertOk();

        $this->assertNotNull($login->json('data.expires_at'));
        $token = $login->json('data.token');

        $this->travel(479)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(2)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
