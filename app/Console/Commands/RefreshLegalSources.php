<?php

namespace App\Console\Commands;

use App\Services\LegalSourceCatalog;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

// Lena cites the documents listed in agents/lena/legal-sources.json. Each entry keeps the URL it was
// downloaded from, so an updated law can be fetched again instead of being searched for by hand.
class RefreshLegalSources extends Command
{
    protected $signature = 'legal-sources:refresh
        {id?* : Only refresh these source ids}
        {--dry-run : Download and compare without writing documents or the manifest}
        {--soffice=soffice : LibreOffice binary used to convert .docx sources to PDF}';

    protected $description = 'Re-download Lena legal sources from the URLs in the manifest and report which documents changed';

    public function handle(LegalSourceCatalog $catalog): int
    {
        $manifest = $catalog->manifest();
        $only = (array) $this->argument('id');
        $rows = [];
        $failures = 0;

        foreach ($manifest['sources'] as $index => $source) {
            if ($only !== [] && ! in_array($source['id'], $only, true)) {
                continue;
            }

            if (empty($source['url'])) {
                $rows[] = [$source['id'], 'manual', 'no download URL recorded: '.($source['note'] ?? 'replace the file by hand')];
                continue;
            }
            if (($source['stored'] ?? true) === false) {
                $rows[] = [$source['id'], 'link only', $source['url']];
                continue;
            }

            try {
                $url = $this->currentUrl($source);
                $original = $this->download($url);
                $hash = hash('sha256', $original);

                // Converted PDFs differ byte for byte on every run, so change is judged on the file
                // the publisher serves, not on the stored PDF.
                if ($hash === ($source['sha256'] ?? null) && $url === $source['url'] && is_file($catalog->path($source))) {
                    $rows[] = [$source['id'], 'unchanged', ''];
                    continue;
                }

                $status = $url !== $source['url'] ? 'new url' : (isset($source['sha256']) ? 'updated' : 'downloaded');
                if (! $this->option('dry-run')) {
                    $pdf = ($source['format'] ?? 'pdf') === 'docx' ? $this->convertDocx($original) : $original;
                    $path = $catalog->path($source);
                    if (! is_dir(dirname($path))) {
                        mkdir(dirname($path), 0775, true);
                    }
                    file_put_contents($path, $pdf);
                    $manifest['sources'][$index] = array_merge($source, ['url' => $url, 'sha256' => $hash, 'retrieved' => now()->toDateString()]);
                }
                $rows[] = [$source['id'], $status, $url];
            } catch (Throwable $exception) {
                $failures++;
                $rows[] = [$source['id'], 'failed', $exception->getMessage()];
            }
        }

        if (! $this->option('dry-run')) {
            file_put_contents(
                base_path(LegalSourceCatalog::MANIFEST),
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );
        }

        $this->table(['id', 'status', 'detail'], $rows);
        $this->line('A changed document can move article numbers: re-check the matching agents/lena/legal/*/AGENT.md.');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Publishers that re-upload a new consolidated text under a new name are followed through their listing page. */
    private function currentUrl(array $source): string
    {
        if (empty($source['link_pattern']) || empty($source['page'])) {
            return $source['url'];
        }

        $html = $this->http()->timeout(60)->get($source['page'])->throw()->body();
        if (preg_match('~'.$source['link_pattern'].'~', $html, $match) !== 1) {
            throw new RuntimeException("link_pattern did not match on {$source['page']}");
        }

        $link = html_entity_decode($match[0]);

        return str_starts_with($link, 'http') ? $link : rtrim((string) parse_url($source['page'], PHP_URL_SCHEME).'://'.parse_url($source['page'], PHP_URL_HOST), '/').'/'.ltrim($link, '/');
    }

    /** Some ministries reject clients that do not look like a browser (mfin.gov.rs answers 403). */
    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/pdf,application/octet-stream;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'bs,hr;q=0.9,sr;q=0.8,en;q=0.7',
        ]);
    }

    private function download(string $url): string
    {
        $body = $this->http()->timeout(180)->get($url)->throw()->body();
        // Official sites answer a moved document with an HTML page and status 200.
        if (! str_starts_with($body, '%PDF') && ! str_starts_with($body, "PK\x03\x04")) {
            throw new RuntimeException('response is not a PDF or DOCX document');
        }

        return $body;
    }

    private function convertDocx(string $docx): string
    {
        $directory = storage_path('framework/cache/legal-sources-'.bin2hex(random_bytes(6)));
        mkdir($directory, 0775, true);
        try {
            file_put_contents($directory.'/source.docx', $docx);
            Process::timeout(300)->run([(string) $this->option('soffice'), '--headless', '--convert-to', 'pdf', '--outdir', $directory, $directory.'/source.docx'])->throw();
            if (! is_file($directory.'/source.pdf')) {
                throw new RuntimeException('LibreOffice did not produce a PDF');
            }

            return (string) file_get_contents($directory.'/source.pdf');
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
}
