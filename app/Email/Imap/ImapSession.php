<?php

namespace App\Email\Imap;

use RuntimeException;

/**
 * What the email client needs from an IMAP server (docs/DECISOES.md, "Email:
 * um cliente de email na plataforma"). Every UID refers to the selected
 * folder; every failure is a RuntimeException.
 *
 * @phpstan-type FolderInfo array{path: string, name: string, delimiter: string|null, attributes: list<string>}
 */
interface ImapSession
{
    /**
     * @return list<FolderInfo>
     *
     * @throws RuntimeException
     */
    public function folders(): array;

    /**
     * @return array{uidvalidity: int|null, exists: int}
     *
     * @throws RuntimeException
     */
    public function select(string $path): array;

    /**
     * All UIDs of the selected folder, ascending.
     *
     * @return list<int>
     */
    public function uids(): array;

    /**
     * @param  list<int>  $uids
     * @return array<int, list<string>> flags by UID
     */
    public function flags(array $uids): array;

    /**
     * The raw messages, without marking them read.
     *
     * @param  list<int>  $uids
     * @return array<int, string> raw message by UID
     */
    public function raw(array $uids): array;

    /**
     * @param  list<int>  $uids
     * @param  list<string>  $flags
     */
    public function addFlags(array $uids, array $flags): void;

    /**
     * @param  list<int>  $uids
     * @param  list<string>  $flags
     */
    public function removeFlags(array $uids, array $flags): void;

    /**
     * Moves messages of the selected folder to another one.
     *
     * @param  list<int>  $uids
     */
    public function move(array $uids, string $target): void;

    /**
     * Deletes messages of the selected folder for good.
     *
     * @param  list<int>  $uids
     */
    public function delete(array $uids): void;

    /**
     * Adds a message to a folder and returns its UID there when the server says it.
     *
     * @param  list<string>  $flags
     */
    public function append(string $path, string $raw, array $flags): ?int;

    /**
     * The UID of a message in the selected folder by its Message-ID header.
     */
    public function findByMessageId(string $messageId): ?int;

    public function create(string $path): void;

    public function close(): void;
}
