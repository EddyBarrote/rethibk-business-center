<?php

namespace App\Documents;

use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The brand's images for documents (signature, stamp, partner logos…) and
 * its logo, in tenants.settings.brand: uploaded in Definições › Marca or
 * on an agent's request card while it builds a document.
 */
final class BrandAssets
{
    public const RULES = ['file', 'image', 'mimes:png,jpg,jpeg', 'max:5120'];

    /**
     * Keeps the image under its key, replacing one with the same key; the
     * key "logotipo" replaces the brand's logo. Takes an upload or a file
     * already on the private disk (an image sent in the conversation).
     */
    public function store(UploadedFile|string $file, string $key, string $label): string
    {
        $key = DocumentType::key($key);
        $tenant = Tenant::current() ?? throw new \RuntimeException('Sem empresa.');
        $extension = $file instanceof UploadedFile ? $file->extension() : strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $folder = $key === 'logotipo' ? 'brand' : 'brand/assets';
        $path = $folder.'/'.$tenant->id.'-'.$key.'-'.Str::random(6).'.'.($extension !== '' ? $extension : 'png');

        if ($file instanceof UploadedFile) {
            $file->storeAs(dirname($path), basename($path), 'local');
        } else {
            Storage::disk('local')->put($path, (string) Storage::disk('local')->get($file));
        }

        $settings = $tenant->settings ?? [];

        if ($key === 'logotipo') {
            $old = data_get($settings, 'brand.logo_path');
            data_set($settings, 'brand.logo_path', $path);
            $tenant->update(['settings' => $settings]);

            if (is_string($old)) {
                Storage::disk('local')->delete($old);
            }

            return $key;
        }

        $assets = $this->without($settings, $key);
        $assets[] = [
            'key' => $key,
            'label' => Str::limit(trim($label) !== '' ? trim($label) : Str::headline($key), 120, ''),
            'path' => $path,
        ];

        data_set($settings, 'brand.assets', $assets);
        $tenant->update(['settings' => $settings]);

        return $key;
    }

    public function remove(string $key): void
    {
        $tenant = Tenant::current() ?? throw new \RuntimeException('Sem empresa.');
        $settings = $tenant->settings ?? [];
        $assets = $this->without($settings, $key);

        data_set($settings, 'brand.assets', $assets);
        $tenant->update(['settings' => $settings]);
    }

    /**
     * The brand's assets without the one under this key, whose file is deleted.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function without(array $settings, string $key): array
    {
        $kept = [];

        foreach ((array) data_get($settings, 'brand.assets', []) as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            if (($asset['key'] ?? null) === $key) {
                Storage::disk('local')->delete((string) $asset['path']);
            } else {
                $kept[] = $asset;
            }
        }

        return $kept;
    }
}
