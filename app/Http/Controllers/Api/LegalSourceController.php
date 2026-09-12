<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LegalSourceCatalog;
use Illuminate\Http\Response;

class LegalSourceController extends Controller
{
    public function show(string $source, LegalSourceCatalog $catalog): Response
    {
        $entry = $catalog->find($source);
        abort_unless($entry, 404);
        $path = storage_path('app'.DIRECTORY_SEPARATOR.LegalSourceCatalog::DIRECTORY.DIRECTORY_SEPARATOR.$entry['file']);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$entry['file'].'"']);
    }
}
