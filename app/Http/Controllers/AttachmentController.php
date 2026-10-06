<?php

namespace App\Http\Controllers;

use App\Attachments\AttachmentStore;
use App\Attachments\ModelInput;
use App\Models\Agent;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files in conversations (docs/DECISOES.md, "Anexos nas conversas"): uploaded
 * in parts while the person writes, sent with the message, and opened by
 * whoever may read that conversation.
 */
class AttachmentController extends Controller
{
    public function start(Request $request, AttachmentStore $store): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'mime' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', 'in:upload,recording'],
            'task_id' => ['nullable', 'integer', TenantRule::exists('tasks', 'id')],
            'agent_id' => ['nullable', 'integer', TenantRule::exists('agents', 'id')],
        ]);

        $task = isset($data['task_id']) ? Task::query()->findOrFail($data['task_id']) : null;

        if ($task !== null) {
            Gate::authorize('reply', $task);
        } elseif (isset($data['agent_id'])) {
            Gate::authorize('run', Agent::query()->findOrFail($data['agent_id']));
        } else {
            abort(422, 'Falta a conversa ou o agente.');
        }

        $attachment = $store->start($user, $data['filename'], (int) $data['size'], $data['mime'] ?? null, $task, $data['source'] ?? 'upload');

        return response()->json(['id' => $attachment->id], 201);
    }

    public function chunk(Request $request, MessageAttachment $attachment, AttachmentStore $store): JsonResponse
    {
        abort_unless($attachment->uploaded_by_user_id === $this->user($request)->id && $attachment->task_message_id === null, 404);
        $data = $request->validate([
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        $attachment = $store->append($attachment, $data['chunk'], (int) $data['offset']);

        return response()->json([
            'id' => $attachment->id,
            'received' => $attachment->received_bytes,
            'ready' => $attachment->isReady(),
            'kind' => $attachment->kind->value,
        ]);
    }

    public function destroy(Request $request, MessageAttachment $attachment, AttachmentStore $store): Response
    {
        abort_unless($attachment->uploaded_by_user_id === $this->user($request)->id && $attachment->task_message_id === null, 404);
        $store->delete($attachment);

        return response()->noContent();
    }

    /**
     * The content of a document for the preview in the conversation: the
     * Markdown of a document an agent generated, the extracted text of others
     * (Word, Excel, PowerPoint, text). Same access as the file itself.
     */
    public function preview(Request $request, MessageAttachment $attachment, ModelInput $input): JsonResponse
    {
        $this->authorizeOpen($request, $attachment);

        $text = in_array($attachment->kind->value, ['document', 'text', 'pdf'], true) ? $input->text($attachment) : null;

        return response()->json([
            'format' => $attachment->source === 'generated' && $text !== null ? 'markdown' : ($attachment->extension() === 'xlsx' ? 'sheet' : 'text'),
            'text' => $text === null ? null : mb_substr($text, 0, 200_000),
        ]);
    }

    /**
     * Opened in the browser when it is safe to (images, audio, video, PDF);
     * downloaded otherwise.
     */
    public function show(Request $request, MessageAttachment $attachment): StreamedResponse
    {
        $this->authorizeOpen($request, $attachment);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        $inline = ! $request->boolean('download') && (
            preg_match('#^(image/(png|jpeg|webp|gif)|audio/|video/|application/pdf$)#', $attachment->mime_type) === 1
        );
        $headers = [
            'Content-Type' => $inline ? $attachment->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ];

        return $inline
            ? $disk->response($attachment->path, $attachment->filename, $headers, 'inline')
            : $disk->download($attachment->path, $attachment->filename, $headers);
    }

    private function authorizeOpen(Request $request, MessageAttachment $attachment): void
    {
        if ($attachment->task_id !== null && $attachment->task_message_id !== null) {
            Gate::authorize('view', $attachment->task);
        } else {
            abort_unless($attachment->uploaded_by_user_id === $this->user($request)->id && $attachment->isReady(), 404);
        }
    }
}
