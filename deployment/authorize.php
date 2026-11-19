<?php
declare(strict_types=1);

/** Authorize before any deployment side effect. Only public verification material is stored here. */
function freightbookAuthorizeDeployment(string $target): void
{
    if (PHP_SAPI === 'cli') return;
    $deny = static function (int $status): never {
        http_response_code($status);
        header('Cache-Control: no-store');
        header('Content-Type: text/plain; charset=utf-8');
        exit('Deployment authorization required.');
    };
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $deny(405);
    $time = (string) ($_SERVER['HTTP_X_FREIGHTBOOK_DEPLOY_TIME'] ?? '');
    $nonce = (string) ($_SERVER['HTTP_X_FREIGHTBOOK_DEPLOY_NONCE'] ?? '');
    $signature = base64_decode((string) ($_SERVER['HTTP_X_FREIGHTBOOK_DEPLOY_SIGNATURE'] ?? ''), true);
    if (!preg_match('/^\d{10}$/D', $time) || abs(time() - (int) $time) > 90 || !preg_match('/^[a-f0-9]{32}$/D', $nonce) || $signature === false) $deny(401);
    if (!function_exists('openssl_verify')) $deny(503);
    $key = @file_get_contents(__DIR__.'/deploy-public.pem');
    $message = "freightbook-deploy-v1\n{$target}\nPOST\n{$time}\n{$nonce}";
    if (!$key || @openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) $deny(401);
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'freightbook-deploy-nonces';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) $deny(503);
    // Exclusive creation rejects replay even when two requests arrive simultaneously.
    $path = $directory.DIRECTORY_SEPARATOR.hash('sha256', $target.':'.$nonce);
    $handle = @fopen($path, 'x');
    if (!$handle) $deny(409);
    fclose($handle);
    foreach (new DirectoryIterator($directory) as $file) {
        if ($file->isFile() && preg_match('/^[a-f0-9]{64}$/D', $file->getFilename()) && $file->getMTime() < time() - 300) @unlink($file->getPathname());
    }
}
