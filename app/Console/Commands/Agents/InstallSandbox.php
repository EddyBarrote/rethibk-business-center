<?php

namespace App\Console\Commands\Agents;

use App\Ai\Code\Sandbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Prepares the Python that code.run uses (docs/DECISOES.md, "Correr código
 * localmente"): a venv with the data libraries, on macOS; on servers, the
 * Docker image from docker/sandbox.
 */
#[Signature('sandbox:install')]
#[Description('Prepara o Python isolado onde os agentes correm código (venv com pandas, numpy e openpyxl)')]
class InstallSandbox extends Command
{
    public function handle(Sandbox $sandbox): int
    {
        $python = (string) config('agents.code_execution.python');
        $venv = dirname($python, 2);

        $ok = true;

        if (! is_file($python)) {
            $this->components->info('A criar o venv em '.$venv);
            $ok = $this->exec([(string) config('agents.code_execution.base_python'), '-m', 'venv', $venv]);
        }

        $packages = (array) config('agents.code_execution.packages', []);
        $this->components->info('A instalar '.implode(', ', $packages));
        $ok = $ok && $this->exec([$python, '-m', 'pip', 'install', '--quiet', '--upgrade', '--only-binary=:all:', ...$packages]);

        $driver = $sandbox->driver();
        $driver === null
            ? $this->components->warn('Sem forma de isolar o código neste servidor (sandbox-exec no macOS ou Docker): code.run fica indisponível. Em servidores: docker build -t '.config('agents.code_execution.docker_image').' docker/sandbox')
            : $this->components->info("Pronto: o código corre com {$driver}.");

        return $ok && $driver !== null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $command
     */
    private function exec(array $command): bool
    {
        $process = new Process($command);
        $process->setTimeout(900)->run();

        if (! $process->isSuccessful()) {
            $this->line($process->getErrorOutput());
        }

        return $process->isSuccessful();
    }
}
