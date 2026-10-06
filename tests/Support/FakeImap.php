<?php

namespace Tests\Support;

use App\Email\Imap\ImapGateway;
use App\Email\Imap\ImapSession;
use App\Models\Mailbox;
use RuntimeException;

/**
 * An IMAP server in memory for the tests: folders with SPECIAL-USE
 * attributes, UIDs, flags, MOVE and APPEND, like a real one behaves.
 */
final class FakeImap implements ImapGateway, ImapSession
{
    /** @var array<string, array{attributes: list<string>, uidvalidity: int, next: int, messages: array<int, array{raw: string, flags: list<string>}>}> */
    public array $folders = [];

    /** @var list<string> */
    public array $log = [];

    public bool $down = false;

    private ?string $selected = null;

    public function __construct()
    {
        foreach (['INBOX' => [], 'Sent' => ['\\Sent'], 'Drafts' => ['\\Drafts'], 'Trash' => ['\\Trash'], 'Junk' => ['\\Junk']] as $path => $attributes) {
            $this->addFolder($path, $attributes);
        }
    }

    public function addFolder(string $path, array $attributes = []): void
    {
        $this->folders[$path] = ['attributes' => $attributes, 'uidvalidity' => 1000 + count($this->folders), 'next' => 1, 'messages' => []];
    }

    /**
     * @param  list<string>  $flags
     */
    public function deliver(string $path, string $raw, array $flags = []): int
    {
        $uid = $this->folders[$path]['next']++;
        $this->folders[$path]['messages'][$uid] = ['raw' => $raw, 'flags' => $flags];

        return $uid;
    }

    public function open(Mailbox $mailbox): ImapSession
    {
        if ($this->down) {
            throw new RuntimeException('[AUTHENTICATIONFAILED] Authentication failed.');
        }

        $this->log[] = 'open';

        return $this;
    }

    public function folders(): array
    {
        return array_values(array_map(fn (string $path, array $f) => ['path' => $path, 'name' => $path, 'delimiter' => '.', 'attributes' => $f['attributes']], array_keys($this->folders), $this->folders));
    }

    public function select(string $path): array
    {
        if (! isset($this->folders[$path])) {
            throw new RuntimeException("folder doesn't exist");
        }

        $this->selected = $path;

        return ['uidvalidity' => $this->folders[$path]['uidvalidity'], 'exists' => count($this->folders[$path]['messages'])];
    }

    public function uids(): array
    {
        $uids = array_keys($this->current()['messages']);
        sort($uids);

        return $uids;
    }

    public function flags(array $uids): array
    {
        return array_map(fn (array $m) => $m['flags'], array_intersect_key($this->current()['messages'], array_flip($uids)));
    }

    public function raw(array $uids): array
    {
        return array_map(fn (array $m) => $m['raw'], array_intersect_key($this->current()['messages'], array_flip($uids)));
    }

    public function addFlags(array $uids, array $flags): void
    {
        $this->log[] = 'add '.implode(' ', $flags);

        foreach ($uids as $uid) {
            $current = &$this->folders[$this->selected]['messages'][$uid]['flags'];
            $current = array_values(array_unique([...$current, ...$flags]));
        }
    }

    public function removeFlags(array $uids, array $flags): void
    {
        $this->log[] = 'remove '.implode(' ', $flags);

        foreach ($uids as $uid) {
            $current = &$this->folders[$this->selected]['messages'][$uid]['flags'];
            $current = array_values(array_diff($current, $flags));
        }
    }

    public function move(array $uids, string $target): void
    {
        $this->log[] = "move {$this->selected} {$target}";

        foreach ($uids as $uid) {
            $message = $this->folders[$this->selected]['messages'][$uid];
            unset($this->folders[$this->selected]['messages'][$uid]);
            $this->deliver($target, $message['raw'], $message['flags']);
        }
    }

    public function delete(array $uids): void
    {
        $this->log[] = "delete {$this->selected}";

        foreach ($uids as $uid) {
            unset($this->folders[$this->selected]['messages'][$uid]);
        }
    }

    public function append(string $path, string $raw, array $flags): ?int
    {
        $this->log[] = "append {$path}";

        return $this->deliver($path, $raw, $flags);
    }

    public function findByMessageId(string $messageId): ?int
    {
        foreach (array_reverse($this->current()['messages'], true) as $uid => $message) {
            if (stripos($message['raw'], 'Message-ID: '.$messageId) !== false) {
                return $uid;
            }
        }

        return null;
    }

    public function create(string $path): void
    {
        $this->log[] = "create {$path}";
        $this->addFolder($path);
    }

    public function close(): void {}

    /**
     * The messages of a folder, by UID.
     *
     * @return array<int, array{raw: string, flags: list<string>}>
     */
    public function in(string $path): array
    {
        return $this->folders[$path]['messages'];
    }

    /**
     * @return array{attributes: list<string>, uidvalidity: int, next: int, messages: array<int, array{raw: string, flags: list<string>}>}
     */
    private function current(): array
    {
        return $this->folders[$this->selected ?? throw new RuntimeException('no folder selected')];
    }
}
