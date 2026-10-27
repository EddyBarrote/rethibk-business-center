<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A person's photo (docs/DECISOES.md, "Foto de perfil"). The page crops it to
 * a square before sending; here it is cut square again and drawn at 512 px as
 * JPEG, so a large or odd file never reaches the screens as it came and the
 * camera's metadata (place, device) is dropped. Files live on the private disk
 * and are served by UserAvatarController to people of the same tenant.
 */
final class UserAvatars
{
    public const DISK = 'local';

    public const SIZE = 512;

    public function upload(User $user, UploadedFile $file): void
    {
        $bytes = $this->square((string) $file->get());
        $path = "avatars/{$user->tenant_id}/people/{$user->id}-".Str::random(10).'.jpg';

        Storage::disk(self::DISK)->put($path, $bytes);
        $this->forget($user);
        $user->forceFill(['avatar_path' => $path])->save();
    }

    public function remove(User $user): void
    {
        $this->forget($user);
        $user->forceFill(['avatar_path' => null])->save();
    }

    private function forget(User $user): void
    {
        $path = $user->getAttributes()['avatar_path'] ?? null;
        if ($path !== null) {
            Storage::disk(self::DISK)->delete((string) $path);
        }
    }

    private function square(string $bytes): string
    {
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new RuntimeException('A imagem não se consegue abrir.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), self::SIZE, self::SIZE, $side, $side);

        ob_start();
        imagejpeg($target, null, 86);

        return (string) ob_get_clean();
    }
}
