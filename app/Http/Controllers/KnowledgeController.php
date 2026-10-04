<?php

namespace App\Http\Controllers;

use App\Ai\Knowledge\KnowledgeBase;
use App\Enums\KnowledgeType;
use App\Models\KnowledgeItem;
use App\Support\TextExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisational memory (section 13): browse, search, and add briefings,
 * notes and documents.
 */
class KnowledgeController extends Controller
{
    public function index(Request $request, KnowledgeBase $knowledge): Response
    {
        $query = trim((string) $request->query('q', ''));
        $type = $request->query('type');

        $results = null;

        if ($query !== '') {
            $results = $knowledge->search($query, 20)->map(fn (array $hit) => [
                ...$this->item($hit['item']),
                'excerpt' => $hit['excerpt'],
                'score' => $hit['score'],
            ])->values();
        }

        return Inertia::render('Knowledge/Index', [
            'items' => $results ?? KnowledgeItem::query()
                ->when($type, fn ($q, $t) => $q->where('type', $t))
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (KnowledgeItem $item) => [...$this->item($item), 'excerpt' => mb_substr($item->content, 0, 300), 'score' => null]),
            'filters' => ['q' => $query, 'type' => $type],
            'types' => KnowledgeType::options(),
            'semantic' => KnowledgeBase::embeddingsConfigured(),
        ]);
    }

    public function show(KnowledgeItem $item): Response
    {
        return Inertia::render('Knowledge/Show', ['item' => [...$this->item($item), 'content' => $item->content]]);
    }

    public function store(Request $request, KnowledgeBase $knowledge, TextExtractor $extractor): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(KnowledgeType::class)],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required_without:file', 'nullable', 'string', 'max:200000'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,docx,xlsx,txt,md,csv,html'],
        ]);

        $content = $data['content'] ?? '';

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $text = $extractor->extract((string) $file->getRealPath(), $file->getMimeType(), $file->getClientOriginalName());

            if ($text === null) {
                return back()->withErrors(['file' => 'Não foi possível ler texto deste ficheiro.']);
            }

            $content = trim($content."\n\n".$text);
        }

        $item = $knowledge->remember(KnowledgeType::from($data['type']), $data['title'], $content, $this->user($request));

        return to_route('knowledge.show', $item)->with('success', 'Guardado na memória.');
    }

    /**
     * @return array<string, mixed>
     */
    private function item(KnowledgeItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'title' => $item->title,
            'is_external' => $item->is_external,
            'embedding_status' => $item->embedding_status,
            'author' => $item->created_by_type,
            'created_at' => $item->created_at->toIso8601String(),
        ];
    }
}
