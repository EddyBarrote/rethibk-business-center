import { AlertCircle, AudioLines, Check, Loader2, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import { useWavRecorder } from '@/hooks/useWavRecorder';
import { clock } from '@/lib/attachments';

/** The longest dictation: a message, not a meeting. */
const MAX_SECONDS = 120;

/**
 * «Ditar» (docs/DECISOES.md, "Falar em vez de escrever", an idea from
 * OpenDuet): speak, and the text lands in the box to read before sending.
 * Unlike «Gravar áudio», nothing is attached: the recording is not kept.
 */
export function DictateControl({ onText, disabled }: { onText: (text: string) => void; disabled?: boolean }) {
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState<string | null>(null);

    const recorder = useWavRecorder(MAX_SECONDS, async (file) => {
        setBusy(true);
        setFailed(null);
        const body = new FormData();
        body.append('audio', file);
        const token = document.cookie
            .split('; ')
            .find((part) => part.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);

        try {
            const response = await fetch('/dictation', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
                },
                body,
            });
            const data = (await response.json().catch(() => ({}))) as { text?: string; message?: string };

            if (!response.ok) {
                throw new Error(data.message ?? 'Não foi possível transcrever.');
            }
            if (data.text) {
                onText(data.text);
            } else {
                setFailed('Não se ouviu nada.');
            }
        } catch (error) {
            setFailed(error instanceof Error ? error.message : 'Não foi possível transcrever.');
        } finally {
            setBusy(false);
        }
    });

    if (recorder.recording) {
        return (
            <div className="flex items-center gap-2 rounded-lg bg-primary/10 py-1 pr-1 pl-2.5 text-sm" role="status" aria-live="polite">
                <span className="relative flex size-2.5">
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-primary opacity-60" />
                    <span className="relative inline-flex size-2.5 rounded-full bg-primary" />
                </span>
                <span className="text-xs">A ouvir</span>
                <span className="font-mono text-xs tabular-nums">{clock(recorder.seconds)}</span>
                <Button type="button" variant="ghost" size="icon" className="size-7" onClick={recorder.cancel} aria-label="Cancelar" title="Cancelar">
                    <X />
                </Button>
                <Button
                    type="button"
                    size="icon"
                    className="size-7"
                    onClick={recorder.stop}
                    aria-label="Terminar e escrever"
                    title="Terminar e escrever"
                >
                    <Check />
                </Button>
            </div>
        );
    }

    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        disabled={disabled || busy}
                        onClick={() => {
                            setFailed(null);
                            void recorder.start();
                        }}
                        aria-label="Ditar"
                    >
                        {busy ? <Loader2 className="animate-spin" /> : <AudioLines />}
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{busy ? 'A escrever o que disse…' : 'Ditar: fale e o texto aparece na caixa'}</TooltipContent>
            </Tooltip>
            {(failed ?? recorder.error) && (
                <span className="flex items-center gap-1 text-xs text-destructive">
                    <AlertCircle className="size-3.5" />
                    {failed ?? recorder.error}
                </span>
            )}
        </>
    );
}
