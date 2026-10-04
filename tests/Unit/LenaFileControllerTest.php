<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\LenaFileController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class LenaFileControllerTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Explicit SQLite :memory: process settings required.');
        }
        $this->app = require __DIR__.'/../../bootstrap/app.php';
        $this->app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        });
        $this->app->make(Kernel::class)->bootstrap();
        // The controller touches only the (faked) local disk, never the database.
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    private function upload(string $name, string $content, string $mime = 'application/json'): array
    {
        $request = Request::create('/api/lena-files', 'POST', [], [], ['file' => UploadedFile::fake()->createWithContent($name, $content)->mimeType($mime)]);

        return (new LenaFileController)->store($request)->getData(true)['data'];
    }

    public function test_uploaded_json_gets_its_own_route_and_replaces_on_reupload(): void
    {
        $meta = $this->upload('FreightBook Ops Pantheon.json', '{"title":"v1"}');
        self::assertSame('freightbook-ops-pantheon', $meta['slug']);
        self::assertSame('/api/lena-files/freightbook-ops-pantheon', $meta['route']);
        $this->upload('FreightBook Ops Pantheon.json', '{"title":"v2"}');
        $shown = (new LenaFileController)->show('freightbook-ops-pantheon')->getData(true)['data'];
        self::assertSame(['title' => 'v2'], $shown['content']);
        self::assertCount(1, (new LenaFileController)->index()->getData(true)['data']);
    }

    public function test_txt_is_served_as_text_and_replaces_json_of_same_name(): void
    {
        $this->upload('brief.json', '{"a":1}');
        $this->upload('brief.txt', 'Plain brief', 'text/plain');
        $shown = (new LenaFileController)->show('brief')->getData(true)['data'];
        self::assertSame('txt', $shown['type']);
        self::assertSame('Plain brief', $shown['content']);
        self::assertSame(['lena-files/brief.txt'], Storage::disk('local')->files('lena-files'));
    }

    public function test_invalid_json_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->upload('broken.json', '{"a":');
    }
}
