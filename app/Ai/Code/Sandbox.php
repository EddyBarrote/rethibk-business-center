<?php

namespace App\Ai\Code;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs an agent's Python on this server, shut in (docs/DECISOES.md, "Correr
 * código localmente"): a fresh folder with only the files it was given, no
 * network, no other file of the machine (not the project, not .env), no other
 * program, a clean environment, and limits on time, memory and output.
 *
 * Two ways to shut it in: macOS's sandbox-exec (development) and Docker
 * (servers). With neither, code does not run.
 */
final class Sandbox
{
    public const MACOS = 'macos';

    public const DOCKER = 'docker';

    /** The driver this server uses, or null when code cannot run safely here. */
    public function driver(): ?string
    {
        $driver = (string) config('agents.code_execution.driver', 'auto');
        $finder = new ExecutableFinder;

        return match ($driver) {
            self::MACOS => PHP_OS_FAMILY === 'Darwin' && is_file('/usr/bin/sandbox-exec') && $this->pythonReady() ? self::MACOS : null,
            self::DOCKER => $finder->find('docker') !== null ? self::DOCKER : null,
            'auto' => PHP_OS_FAMILY === 'Darwin' && is_file('/usr/bin/sandbox-exec') && $this->pythonReady() ? self::MACOS
                : ($finder->find('docker') !== null ? self::DOCKER : null),
            default => null,
        };
    }

    public function available(): bool
    {
        return $this->driver() !== null;
    }

    /**
     * @param  array<string, string>  $files  name in the folder => path on disk
     * @return array{stdout: string, stderr: string, exit: int|null, timed_out: bool, created: list<string>}
     */
    public function run(string $code, array $files): array
    {
        $driver = $this->driver() ?? throw new \RuntimeException('A execução de código não está instalada neste servidor.');
        $base = rtrim((string) realpath(sys_get_temp_dir()), '/').'/micomoc-code-'.Str::random(16);
        $work = "{$base}/work";
        File::ensureDirectoryExists($work, 0700);

        if ($driver === self::DOCKER) {
            // The container runs as nobody: it may write only in its folder.
            chmod($base, 0755);
            chmod($work, 0777);
        }

        try {
            foreach ($files as $name => $path) {
                File::copy($path, "{$work}/{$name}");
            }

            $before = array_keys($files);
            File::put("{$work}/main.py", $code);
            File::put("{$base}/runner.py", $this->runner());

            $process = new Process($driver === self::MACOS ? $this->macos($base) : $this->docker($base), $work, []);
            $process->setTimeout((float) config('agents.code_execution.timeout', 60) + 15);
            $timedOut = false;

            try {
                $process->run();
            } catch (ProcessTimedOutException|ProcessSignaledException) {
                // Out of time, or killed by its own CPU or memory limit.
                $timedOut = true;
            }

            $limit = (int) config('agents.code_execution.output_chars', 20000);
            $created = collect(File::allFiles($work))
                ->map(fn ($file) => $file->getRelativePathname())
                ->reject(fn (string $name) => $name === 'main.py' || in_array($name, $before, true))
                ->values()->all();

            return [
                'stdout' => Str::limit($process->getOutput(), $limit, "\n[saída cortada]"),
                'stderr' => Str::limit($process->getErrorOutput(), 4000, "\n[erros cortados]"),
                'exit' => $timedOut ? null : $process->getExitCode(),
                'timed_out' => $timedOut,
                'created' => $created,
            ];
        } finally {
            File::deleteDirectory($base);
        }
    }

    /**
     * Limits set inside the process before the agent's code runs; then the
     * code runs as __main__ in the work folder.
     */
    private function runner(): string
    {
        $seconds = (int) config('agents.code_execution.timeout', 60);
        $memory = (int) config('agents.code_execution.memory_mb', 1024) * 1024 * 1024;

        return <<<PY
        import resource, runpy, sys
        def limit(kind, value):
            try:
                resource.setrlimit(kind, (value, value))
            except (ValueError, OSError):
                pass
        limit(resource.RLIMIT_CPU, {$seconds})
        limit(resource.RLIMIT_AS, {$memory})
        limit(resource.RLIMIT_FSIZE, 100 * 1024 * 1024)
        limit(resource.RLIMIT_CORE, 0)
        sys.argv = ["main.py"]
        runpy.run_path("main.py", run_name="__main__")
        PY;
    }

    /**
     * @return list<string>
     */
    private function macos(string $base): array
    {
        $python = (string) config('agents.code_execution.python');
        $venv = (string) realpath(dirname($python, 2));
        $real = (string) realpath($python);
        // A framework build of Python starts itself again from its own folder.
        $framework = str_contains($real, '.framework/') ? Str::before($real, '.framework/').'.framework' : dirname($real, 2);

        $profile = <<<SB
        (version 1)
        (deny default)
        (allow process-fork)
        (allow process-exec (subpath "{$venv}") (subpath "{$framework}"))
        (allow file-read-metadata)
        (allow file-read* (literal "/") (subpath "/System") (subpath "/usr/lib") (subpath "/usr/share") (subpath "/private/var/db/dyld")
            (subpath "{$venv}") (subpath "{$framework}") (subpath "{$base}") (subpath "/opt/homebrew") (subpath "/usr/local/opt") (subpath "/usr/local/Cellar")
            (literal "/dev/null") (literal "/dev/urandom") (literal "/dev/random") (literal "/private/etc/localtime"))
        (allow file-write* (subpath "{$base}/work") (literal "/dev/null"))
        (allow sysctl-read)
        SB;

        return ['/usr/bin/env', '-i', 'PATH=/usr/bin:/bin', "HOME={$base}/work", 'LANG=en_US.UTF-8', "MPLCONFIGDIR={$base}/work",
            '/usr/bin/sandbox-exec', '-p', $profile, $python, '-I', "{$base}/runner.py"];
    }

    /**
     * @return list<string>
     */
    private function docker(string $base): array
    {
        $memory = (int) config('agents.code_execution.memory_mb', 1024);

        return ['docker', 'run', '--rm', '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '--memory', "{$memory}m", '--cpus', '1', '--pids-limit', '64', '--user', '65534:65534', '--tmpfs', '/tmp:size=64m',
            '-v', "{$base}/work:/work", '-v', "{$base}/runner.py:/runner.py:ro", '-w', '/work', '-e', 'HOME=/work',
            (string) config('agents.code_execution.docker_image', 'micomoc-sandbox'), 'python', '-I', '/runner.py'];
    }

    private function pythonReady(): bool
    {
        $python = (string) config('agents.code_execution.python');

        return $python !== '' && is_file($python) && is_file(dirname($python, 2).'/pyvenv.cfg');
    }
}
