<?php

namespace Modules\Products\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CatalogImageStorageService
{
    /**
     * @return array{path: string, url: string}
     */
    public function store(UploadedFile $file, int $organizationId): array
    {
        $extension = strtolower($file->guessExtension() ?: 'jpg');
        $filename = Str::uuid()->toString().'.'.$extension;
        $directory = "catalog/{$organizationId}";

        $path = $file->storeAs($directory, $filename, 'public');

        return [
            'path' => $path,
            'url' => '/storage/'.$path,
        ];
    }

    public function deleteByUrl(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $prefix = '/storage/';
        if (! str_starts_with($url, $prefix)) {
            return;
        }

        $path = substr($url, strlen($prefix));
        if ($path === '' || ! str_starts_with($path, 'catalog/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
