<?php

/*
|--------------------------------------------------------------------------
| Inbound email (section 9, IMAP as decided in docs/DECISOES.md)
|--------------------------------------------------------------------------
*/

return [

    // Raw messages and attachments older than this are deleted; metadata
    // stays. Each tenant can override it in its profile.
    'retention_days' => (int) env('MAIL_RETENTION_DAYS', 365),

    // Disk where raw .eml files and attachments are kept.
    'disk' => env('MAIL_INGEST_DISK', 'local'),

    // Above these sizes only metadata is kept (section 9.4).
    'max_message_bytes' => (int) env('MAIL_MAX_MESSAGE_BYTES', 40 * 1024 * 1024),
    'max_attachment_bytes' => (int) env('MAIL_MAX_ATTACHMENT_BYTES', 20 * 1024 * 1024),

    // Messages fetched per mailbox per poll.
    'batch_size' => 50,

];
