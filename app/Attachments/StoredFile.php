<?php

namespace App\Attachments;

/**
 * A file the platform keeps and can give to a model: a conversation
 * attachment or an email attachment.
 */
interface StoredFile
{
    /** How the agent refers to it, e.g. "anexo #12". */
    public function reference(): string;

    public function fileName(): string;

    public function mimeType(): string;

    public function sizeBytes(): int;

    public function diskName(): string;

    public function storagePath(): ?string;

    /** Text already extracted (or transcribed), if any. */
    public function cachedText(): ?string;

    /** Whether extraction was already tried, so it is not tried again. */
    public function textTried(): bool;

    public function rememberText(?string $text): void;

    /** Content that came from outside the company. */
    public function isExternal(): bool;
}
