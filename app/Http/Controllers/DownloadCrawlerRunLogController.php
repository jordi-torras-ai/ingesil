<?php

namespace App\Http\Controllers;

use App\Models\CrawlerRun;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadCrawlerRunLogController extends Controller
{
    public function __invoke(CrawlerRun $crawlerRun): BinaryFileResponse
    {
        abort_unless(auth()->user()?->isAdmin() ?? false, 403);

        $path = $crawlerRun->absoluteLogPath();
        abort_unless($path !== null && is_file($path), 404);

        $realPath = realpath($path);
        $storageRoot = realpath(storage_path('crawlers'));

        abort_unless(
            $realPath !== false
            && $storageRoot !== false
            && str_starts_with($realPath, $storageRoot),
            404,
        );

        return response()->download($realPath, basename($realPath), [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
