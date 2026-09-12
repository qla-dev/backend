<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegalSourceCatalog;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegalSourceController extends Controller
{
    public function show(string $source, LegalSourceCatalog $catalog): BinaryFileResponse
    {
        $entry = $catalog->find($source);
        abort_unless($entry, 404);
        $path = $catalog->path($entry);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$entry['file'].'"']);
    }
}
