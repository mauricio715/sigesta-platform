<?php

namespace App\Console\Commands;

use App\Http\Middleware\RestringirTokensSoloLectura;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerarTokenIaCommand extends Command
{
    protected $signature = 'sigesta:token-ia
                            {--dias= : Días de validez del token (por defecto config sigesta.ia_service.token_dias)}
                            {--revocar : Solo revoca los tokens vigentes del servicio, sin emitir uno nuevo}';

    protected $description = 'Emite (o revoca) el token Sanctum de SOLO LECTURA del microservicio de IA';

    public function handle(): int
    {
        $config = config('sigesta.ia_service');

        // Usuario técnico: rol operador (nunca admin) y contraseña aleatoria
        // desconocida, de modo que no puede iniciar sesión por /auth/login.
        $servicio = User::firstOrCreate(
            ['email' => $config['email']],
            [
                'name' => $config['nombre'],
                'password' => Str::random(64),
                'role' => User::ROLE_OPERADOR,
                'status' => User::STATUS_ACTIVE,
            ],
        );

        if ($servicio->role !== User::ROLE_OPERADOR) {
            $servicio->update(['role' => User::ROLE_OPERADOR]);
            $this->warn('El usuario de servicio tenía otro rol; se restableció a "operador".');
        }

        $revocados = $servicio->tokens()->delete();

        if ($this->option('revocar')) {
            $this->info("Tokens del servicio de IA revocados: {$revocados}.");

            return self::SUCCESS;
        }

        if (! $servicio->isActive()) {
            $this->error('El usuario de servicio está desactivado. Reactívelo desde la administración de usuarios.');

            return self::FAILURE;
        }

        $dias = (int) ($this->option('dias') ?? $config['token_dias']);

        if ($dias < 1 || $dias > 365) {
            $this->error('La validez debe estar entre 1 y 365 días.');

            return self::FAILURE;
        }

        $expira = now()->addDays($dias);
        $token = $servicio->createToken(
            'ia-service',
            [RestringirTokensSoloLectura::HABILIDAD],
            $expira,
        )->plainTextToken;

        $this->info('Token de servicio de IA emitido (solo lectura).');
        $this->line("Usuario:   {$servicio->email}");
        $this->line("Expira:    {$expira->toDateTimeString()}");
        $this->line("Revocados: {$revocados} token(s) anterior(es)");
        $this->newLine();
        $this->line('Copie esta línea en ai-service/.env (no se volverá a mostrar):');
        $this->newLine();
        $this->line("IA_SERVICE_TOKEN={$token}");

        return self::SUCCESS;
    }
}
