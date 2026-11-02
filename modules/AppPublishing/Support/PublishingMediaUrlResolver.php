<?php

namespace Modules\AppPublishing\Support;

use App\Support\Storage\StorageDriverManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\AppFiles\Models\AppFile;

class PublishingMediaUrlResolver
{
    public function __construct(
        protected StorageDriverManager $storageDriverManager,
    ) {}

    public function resolveFiles(array $items): Collection
    {
        $ids = collect($items)
            ->map(fn ($item) => (int) Arr::get($item, 'id'))
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return AppFile::query()
            ->whereIn('id', $ids->all())
            ->get()
            ->sortBy(fn (AppFile $file) => $ids->search($file->id))
            ->values();
    }

    public function publicUrlForFile(AppFile $file, int $expiresInMinutes = 360): string
    {
        $disk = (string) $file->disk;
        $path = trim((string) $file->path);

        if ($disk === 'public' && $path !== '') {
            $directUrl = $this->storageDriverManager->publicUrl($disk, $path);

            if ($directUrl) {
                return $this->normalizeAbsoluteUrl($directUrl);
            }
        }

        if ($disk === 'local' && $path !== '') {
            $directUrl = $this->storageDriverManager->publicUrl($disk, $path);

            if ($directUrl) {
                return $this->normalizeAbsoluteUrl($directUrl);
            }
        }

        $directUrl = $this->storageDriverManager->publicUrl($disk, $path);

        if ($directUrl) {
            return $this->normalizeAbsoluteUrl($directUrl);
        }

        return URL::temporarySignedRoute(
            'portal.files.publish-preview',
            now()->addMinutes($expiresInMinutes),
            ['file' => $file]
        );
    }

    public function firstAbsoluteUrl(array $mediaItem): ?string
    {
        foreach (['url', 'previewUrl', 'embedUrl'] as $key) {
            $value = trim((string) Arr::get($mediaItem, $key, ''));

            if ($value !== '' && preg_match('#^https?://#i', $value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mediaItems
     * @return array<int, array{label: string, url: string, kind: string}>
     */
    public function approvalEmailLinks(array $mediaItems, int $expiresInMinutes = 10080): array
    {
        $links = [];
        $index = 0;

        foreach ($mediaItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $this->resolveApprovalEmailUrl($item, $expiresInMinutes);

            if ($url === null || $url === '') {
                continue;
            }

            $index++;
            $kind = $this->mediaKind($item);

            $links[] = [
                'label' => $kind === 'video'
                    ? __('View video :number', ['number' => $index])
                    : __('View image :number', ['number' => $index]),
                'url' => $url,
                'kind' => $kind,
            ];
        }

        return $links;
    }

    /**
     * @param  array<string, mixed>  $mediaItem
     */
    protected function resolveApprovalEmailUrl(array $mediaItem, int $expiresInMinutes): ?string
    {
        $file = $this->resolveFileFromMediaItem($mediaItem);

        if ($file) {
            return $this->publicUrlForFile($file, $expiresInMinutes);
        }

        $absolute = $this->firstAbsoluteUrl($mediaItem);

        if ($absolute) {
            return $absolute;
        }

        foreach (['url', 'previewUrl', 'embedUrl'] as $key) {
            $value = trim((string) Arr::get($mediaItem, $key, ''));

            if ($value === '') {
                continue;
            }

            if (preg_match('#^https?://#i', $value)) {
                return $value;
            }

            return $this->normalizeAbsoluteUrl($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $mediaItem
     */
    protected function resolveFileFromMediaItem(array $mediaItem): ?AppFile
    {
        $fileId = (int) Arr::get($mediaItem, 'id', Arr::get($mediaItem, 'file_id', 0));

        if ($fileId > 0) {
            $file = AppFile::query()->find($fileId);

            if ($file) {
                return $file;
            }
        }

        $idSecure = trim((string) Arr::get($mediaItem, 'idSecure', Arr::get($mediaItem, 'id_secure', '')));

        if ($idSecure !== '') {
            return AppFile::query()->where('id_secure', $idSecure)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $mediaItem
     */
    protected function mediaKind(array $mediaItem): string
    {
        $mime = strtolower(trim((string) Arr::get($mediaItem, 'mimeType', Arr::get($mediaItem, 'mime_type', ''))));
        $category = strtolower(trim((string) Arr::get($mediaItem, 'category', '')));

        if (str_starts_with($mime, 'video/') || $category === 'video') {
            return 'video';
        }

        return 'image';
    }

    protected function normalizeAbsoluteUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return $url;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return URL::to($url);
    }
}
