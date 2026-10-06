<?php

namespace App\Enums;

/**
 * What a file in a conversation is, for the model and for the screen.
 */
enum AttachmentKind: string
{
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Pdf = 'pdf';
    case Document = 'document';
    case Text = 'text';

    public static function fromMime(string $mime, ?string $filename = null): self
    {
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

        return match (true) {
            str_starts_with($mime, 'image/') => self::Image,
            str_starts_with($mime, 'audio/') || in_array($extension, ['wav', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'weba'], true) => self::Audio,
            str_starts_with($mime, 'video/') => self::Video,
            $mime === 'application/pdf' => self::Pdf,
            str_starts_with($mime, 'text/') || in_array($extension, ['txt', 'md', 'csv', 'json', 'xml'], true) => self::Text,
            default => self::Document,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Image => 'imagem',
            self::Audio => 'áudio',
            self::Video => 'vídeo',
            self::Pdf => 'PDF',
            self::Document => 'documento',
            self::Text => 'texto',
        };
    }
}
