<?php

namespace App\Services\Fiscal;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

/**
 * Runs the external fiscal worker (`php artisan fiscal:*`) exactly like invoice-maker's runLaravelArtisan().
 */
class FiscalBridge
{
    public function configured(): bool
    {
        $dir = (string) config('fiscal.worker_dir');

        return $dir !== '' && is_file(rtrim($dir, '\\/').DIRECTORY_SEPARATOR.'artisan');
    }

    /** @return array<string, mixed> */
    public function run(array $args, ?int $timeout = null): array
    {
        if (! $this->configured()) {
            throw ValidationException::withMessages(['fiscal' => 'Fiscal driver worker is not installed. Set FISCAL_WORKER_DIR to the Laravel project with fiscal:* commands.']);
        }
        $timeout ??= (int) config('fiscal.timeout', 90);
        $process = new Process([config('fiscal.php_bin', 'php'), 'artisan', ...$args], (string) config('fiscal.worker_dir'));
        $process->setTimeout(max($timeout + 5, 30));
        $process->run();
        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $parsed = json_decode($stdout, true);
        if (! $process->isSuccessful()) {
            $error = json_decode($stderr ?: $stdout, true);
            $message = is_array($error) ? (string) ($error['detail'] ?? $error['error'] ?? 'Fiscal command failed.') : ($stderr ?: $stdout ?: 'Fiscal command failed.');
            throw ValidationException::withMessages(['fiscal' => $message]);
        }

        return is_array($parsed) ? $parsed : ['ok' => true, 'rawOutput' => $stdout, 'stderr' => $stderr];
    }

    /** Sends a JSON request through a temporary file so receipt lines never pass through shell arguments. */
    public function runWithRequest(string $command, array $request, ?int $timeout = null): array
    {
        $path = tempnam(sys_get_temp_dir(), 'fiscal');
        file_put_contents($path, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        try {
            return $this->run([$command, '--request='.$path, '--timeout='.($timeout ?? config('fiscal.timeout', 90))], $timeout);
        } finally {
            @unlink($path);
        }
    }
}
