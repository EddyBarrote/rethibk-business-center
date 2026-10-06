<?php

namespace App\Email\Imap;

use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Exceptions\ResponseException;
use Webklex\PHPIMAP\IMAP;

/**
 * IMAP through webklex's protocol layer, by UID. No ext-imap needed.
 */
final class WebklexImapSession implements ImapSession
{
    public function __construct(private readonly Client $client) {}

    public function folders(): array
    {
        return $this->guard(function () {
            $folders = [];

            foreach ($this->protocol()->folders('', '*')->validatedData() as $path => $info) {
                $attributes = array_values(array_map('strval', (array) ($info['flags'] ?? [])));

                if (in_array('\\Noselect', $attributes, true) || in_array('\\NonExistent', $attributes, true)) {
                    continue;
                }

                $delimiter = is_string($info['delimiter'] ?? null) && $info['delimiter'] !== 'NIL' ? $info['delimiter'] : null;
                $path = (string) $path;
                $name = $delimiter !== null && str_contains($path, $delimiter) ? substr($path, (int) strrpos($path, $delimiter) + strlen($delimiter)) : $path;

                $folders[] = ['path' => $path, 'name' => $this->decodeName($name), 'delimiter' => $delimiter, 'attributes' => $attributes];
            }

            return $folders;
        });
    }

    public function select(string $path): array
    {
        return $this->guard(function () use ($path) {
            $result = $this->protocol()->selectFolder($path)->validatedData();

            return ['uidvalidity' => isset($result['uidvalidity']) ? (int) $result['uidvalidity'] : null, 'exists' => (int) ($result['exists'] ?? 0)];
        });
    }

    public function uids(): array
    {
        return $this->guard(function () {
            $uids = array_map('intval', (array) $this->protocol()->search(['ALL'], IMAP::ST_UID)->validatedData());
            sort($uids);

            return $uids;
        });
    }

    public function flags(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        return $this->guard(function () use ($uids) {
            $flags = [];

            foreach (array_chunk($uids, 200) as $chunk) {
                foreach ($this->fetchOrEmpty(['FLAGS'], $chunk) as $uid => $list) {
                    $flags[(int) $uid] = array_values(array_map('strval', (array) $list));
                }
            }

            return $flags;
        });
    }

    public function raw(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        return $this->guard(function () use ($uids) {
            $messages = [];

            foreach (array_chunk($uids, 20) as $chunk) {
                // Two items: webklex matches a single item by the name asked, and servers answer BODY[] to BODY.PEEK[].
                foreach ($this->fetchOrEmpty(['UID', 'BODY.PEEK[]'], $chunk) as $uid => $item) {
                    $messages[(int) $uid] = is_array($item) ? (string) ($item['BODY[]'] ?? '') : (string) $item;
                }
            }

            return $messages;
        });
    }

    public function addFlags(array $uids, array $flags): void
    {
        $this->storeFlags($uids, $flags, '+');
    }

    public function removeFlags(array $uids, array $flags): void
    {
        $this->storeFlags($uids, $flags, '-');
    }

    public function move(array $uids, string $target): void
    {
        if ($uids === []) {
            return;
        }

        $this->guard(fn () => $this->protocol()->moveManyMessages($uids, $target, IMAP::ST_UID)->validatedData());
    }

    public function delete(array $uids): void
    {
        if ($uids === []) {
            return;
        }

        $this->storeFlags($uids, ['\\Deleted'], '+');
        $this->guard(fn () => $this->protocol()->expunge()->validatedData());
    }

    public function append(string $path, string $raw, array $flags): ?int
    {
        return $this->guard(function () use ($path, $raw, $flags) {
            $response = $this->protocol()->appendMessage($path, $raw, $flags === [] ? null : $flags);
            $text = json_encode($response->getResponse()).json_encode($response->data());

            if (! $response->boolean()) {
                throw new RuntimeException('o servidor recusou guardar a mensagem em '.$path.'.');
            }

            return preg_match('/APPENDUID\s+\d+\s+(\d+)/i', (string) $text, $match) === 1 ? (int) $match[1] : null;
        });
    }

    public function findByMessageId(string $messageId): ?int
    {
        return $this->guard(function () use ($messageId) {
            $uids = array_map('intval', (array) $this->protocol()->search(['HEADER', 'Message-ID', '"'.addcslashes($messageId, '"\\').'"'], IMAP::ST_UID)->validatedData());

            return $uids === [] ? null : max($uids);
        });
    }

    public function create(string $path): void
    {
        $this->guard(fn () => $this->protocol()->createFolder($path)->validatedData());
    }

    public function close(): void
    {
        try {
            $this->client->disconnect();
        } catch (Throwable) {
            // Already gone.
        }
    }

    /**
     * @param  list<int>  $uids
     * @param  list<string>  $flags
     */
    private function storeFlags(array $uids, array $flags, string $mode): void
    {
        $this->guard(function () use ($uids, $flags, $mode) {
            foreach ($uids as $uid) {
                $this->protocol()->store($flags, $uid, null, $mode, true, IMAP::ST_UID)->validatedData();
            }
        });
    }

    /**
     * Webklex throws on an empty answer, as when the messages were just
     * removed by another client: that is no messages, not a failure.
     *
     * @param  list<string>  $items
     * @param  list<int>  $uids
     * @return array<int|string, mixed>
     */
    private function fetchOrEmpty(array $items, array $uids): array
    {
        try {
            return (array) $this->protocol()->fetch($items, $uids, null, IMAP::ST_UID)->validatedData();
        } catch (ResponseException $e) {
            if (str_contains($e->getMessage(), 'Empty response')) {
                return [];
            }

            throw $e;
        }
    }

    private function protocol(): ImapProtocol
    {
        $connection = $this->client->getConnection();

        if (! $connection instanceof ImapProtocol) {
            throw new RuntimeException('ligação IMAP inesperada.');
        }

        return $connection;
    }

    /**
     * Folder names in modified UTF-7 (RFC 3501), as servers send accents.
     */
    private function decodeName(string $name): string
    {
        if (! str_contains($name, '&')) {
            return $name;
        }

        $decoded = function_exists('mb_convert_encoding') ? @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP') : false;

        return is_string($decoded) && $decoded !== '' ? $decoded : $name;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function guard(callable $call): mixed
    {
        try {
            return $call();
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), previous: $e);
        }
    }
}
