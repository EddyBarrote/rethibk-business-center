<?php

/*
 * Files in conversations with agents (docs/DECISOES.md, "Anexos nas
 * conversas"). The limits and the types are defaults that each company can
 * change in Definições › Anexos (tenants.settings.attachments).
 */
return [
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    // Per company, editable in Definições › Anexos.
    'defaults' => [
        'max_mb' => 20,
        'max_files' => 10,
        'audio_max_minutes' => 10,
        'types' => ['image', 'pdf', 'office', 'text', 'audio'],
    ],

    // No company can go above these.
    'ceiling' => [
        'max_mb' => 100,
        'max_files' => 20,
        'audio_max_minutes' => 60,
    ],

    // What may be sent, by type: the extensions accepted and the mime family
    // the content must have (checked on the bytes, not on the name).
    'types' => [
        'image' => ['label' => 'Imagens', 'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif'], 'mimes' => ['image/']],
        'pdf' => ['label' => 'PDF', 'extensions' => ['pdf'], 'mimes' => ['application/pdf']],
        'office' => ['label' => 'Word, Excel e PowerPoint', 'extensions' => ['docx', 'xlsx', 'pptx'], 'mimes' => ['application/vnd.openxmlformats-officedocument', 'application/zip', 'application/octet-stream']],
        'text' => ['label' => 'Texto (TXT, Markdown, CSV, JSON)', 'extensions' => ['txt', 'md', 'csv', 'json', 'xml', 'html', 'htm'], 'mimes' => ['text/', 'application/json', 'application/xml', 'application/csv']],
        'audio' => ['label' => 'Áudio (gravado ou ficheiro)', 'extensions' => ['wav', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'weba'], 'mimes' => ['audio/', 'video/webm', 'video/mp4', 'application/ogg']],
        'video' => ['label' => 'Vídeo', 'extensions' => ['mp4', 'mov', 'webm'], 'mimes' => ['video/']],
    ],

    // What each provider reads natively, by mime type. Anything else goes to
    // the model as text: extracted from documents, OCR for images (when
    // Tesseract is installed), a transcription for audio.
    'native' => [
        'gemini' => [
            'image/png', 'image/jpeg', 'image/webp', 'image/heic', 'image/heif',
            'application/pdf',
            'audio/wav', 'audio/mp3', 'audio/aiff', 'audio/aac', 'audio/ogg', 'audio/flac', 'audio/webm', 'audio/mp4',
            'video/mp4', 'video/mpeg', 'video/quicktime', 'video/webm',
        ],
        'anthropic' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'],
        'openai' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'],
        'azure' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
        'xai' => ['image/png', 'image/jpeg'],
        'openrouter' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'],
    ],

    // Files sent to the model in one turn, at most (Gemini takes 20 MB inline
    // per request); what does not fit goes as text.
    'inline_max_mb' => 18,

    // Earlier files of the conversation that go to the model again as files;
    // older ones are named, and the agent reads them with files.read.
    'history_files' => 4,

    // Transcribes audio for providers that cannot hear it. Null: the first of
    // these with a key in .env.
    'transcription_provider' => env('ATTACHMENTS_TRANSCRIPTION_PROVIDER'),
    'transcription_candidates' => ['gemini', 'openai', 'mistral', 'groq', 'elevenlabs'],

    // Text of one file given to the model in a turn, at most; the rest is read with files.read.
    'text_chars' => 20000,

    // Uploads go in parts of this size, at most, under PHP's own limits.
    'chunk_max_bytes' => 4 * 1024 * 1024,

    // Files uploaded and never sent are deleted after this.
    'orphan_hours' => 24,
];
