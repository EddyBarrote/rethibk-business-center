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

    // Messages fetched per mailbox per poll.
    'batch_size' => 50,

];
