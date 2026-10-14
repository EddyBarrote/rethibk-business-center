<?php

/*
|--------------------------------------------------------------------------
| Repositório de ficheiros (docs/DECISOES.md, 14.10.2026)
|--------------------------------------------------------------------------
|
| The company's files live in the knowledge base. Each file is read once, on
| the queue: its text page by page, and the pages without text (scans,
| photos) by a model that reads the page itself. Then it is cut into parts
| and indexed with vectors for search.
|
*/

return [

    // Largest file people can put in the repository (sent in parts, so PHP's own upload limit does not matter).
    'max_upload_mb' => (int) env('KNOWLEDGE_MAX_UPLOAD_MB', 100),

    // Most files in one upload (a dragged folder counts every file inside).
    'max_upload_files' => 200,

    // Vector size. Gemini's embedding model gives 3072 and accepts fewer
    // (Matryoshka): 768 keeps almost all of the quality with a quarter of
    // the bytes, which is what makes searching every part in PHP affordable.
    'embedding_dimensions' => (int) env('KNOWLEDGE_EMBEDDING_DIMENSIONS', 768),

    // Search: similar parts count only within this distance of the best
    // match (cosine); the rest is what every text shares with any question.
    'similarity_window' => 0.08,

    // Parts sent to the embeddings API per request.
    'embedding_batch' => 50,

    'reading' => [
        // A PDF page with fewer readable characters than this is treated as a
        // scan and read by the model.
        'min_chars_per_page' => 80,

        // Pages sent to the model per request; each request is its own job on the queue.
        'batch_pages' => 8,

        // Pages read by the model in one document, at most; the rest keeps only what pdftotext found.
        'max_model_pages' => 300,

        // Null: the provider's default text model.
        'provider' => env('KNOWLEDGE_READING_PROVIDER'),
        'model' => env('KNOWLEDGE_READING_MODEL'),
    ],

    // knowledge.view: pages of the original sent to the model at once.
    'view_max_pages' => 15,

];
