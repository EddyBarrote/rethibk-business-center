import { useCallback, useEffect, useRef, useState } from 'react';

const SAMPLE_RATE = 16000;

/**
 * Records the microphone as a 16 kHz mono WAV, a format every model that
 * hears audio accepts (Gemini included) and every browser can produce.
 * Stops on its own at the company's longest recording.
 */
export function useWavRecorder(maxSeconds: number, onDone: (file: File) => void) {
    const [recording, setRecording] = useState(false);
    const [seconds, setSeconds] = useState(0);
    const [level, setLevel] = useState(0);
    const [error, setError] = useState<string | null>(null);
    const session = useRef<{
        context: AudioContext;
        stream: MediaStream;
        processor: ScriptProcessorNode;
        chunks: Float32Array[];
        startedAt: number;
        timer: number;
        cancelled: boolean;
    } | null>(null);
    const doneRef = useRef(onDone);
    doneRef.current = onDone;

    const finish = useCallback(() => {
        const current = session.current;

        if (!current) {
            return;
        }

        session.current = null;
        window.clearInterval(current.timer);
        current.processor.disconnect();
        current.stream.getTracks().forEach((track) => track.stop());
        void current.context.close();
        setRecording(false);
        setLevel(0);

        if (current.cancelled || current.chunks.length === 0) {
            return;
        }

        const blob = encodeWav(current.chunks, current.context.sampleRate);
        const stamp = new Date().toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' }).replace(':', 'h');
        doneRef.current(new File([blob], `gravacao-${stamp}.wav`, { type: 'audio/wav' }));
    }, []);

    const start = useCallback(async () => {
        setError(null);

        if (!navigator.mediaDevices?.getUserMedia) {
            setError('Este browser não deixa gravar áudio.');
            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } });
            const context = new AudioContext({ sampleRate: SAMPLE_RATE });
            const source = context.createMediaStreamSource(stream);
            const processor = context.createScriptProcessor(4096, 1, 1);
            const chunks: Float32Array[] = [];

            processor.onaudioprocess = (event) => {
                const input = event.inputBuffer.getChannelData(0);
                chunks.push(new Float32Array(input));
                let peak = 0;
                for (let i = 0; i < input.length; i += 64) {
                    peak = Math.max(peak, Math.abs(input[i]));
                }
                setLevel(peak);
            };
            source.connect(processor);
            processor.connect(context.destination);

            const startedAt = Date.now();
            const timer = window.setInterval(() => {
                const elapsed = (Date.now() - startedAt) / 1000;
                setSeconds(elapsed);
                if (elapsed >= maxSeconds) {
                    finish();
                }
            }, 250);

            session.current = { context, stream, processor, chunks, startedAt, timer, cancelled: false };
            setSeconds(0);
            setRecording(true);
        } catch {
            setError('Sem acesso ao microfone. Autorize-o no browser para gravar.');
        }
    }, [maxSeconds, finish]);

    const stop = useCallback(() => finish(), [finish]);

    const cancel = useCallback(() => {
        if (session.current) {
            session.current.cancelled = true;
        }
        finish();
    }, [finish]);

    useEffect(() => () => cancel(), [cancel]);

    return { recording, seconds, level, error, start, stop, cancel, clearError: () => setError(null) };
}

function encodeWav(chunks: Float32Array[], sampleRate: number): Blob {
    const length = chunks.reduce((sum, chunk) => sum + chunk.length, 0);
    const buffer = new ArrayBuffer(44 + length * 2);
    const view = new DataView(buffer);
    const text = (offset: number, value: string) => [...value].forEach((char, i) => view.setUint8(offset + i, char.charCodeAt(0)));

    text(0, 'RIFF');
    view.setUint32(4, 36 + length * 2, true);
    text(8, 'WAVE');
    text(12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, sampleRate, true);
    view.setUint32(28, sampleRate * 2, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    text(36, 'data');
    view.setUint32(40, length * 2, true);

    let offset = 44;
    for (const chunk of chunks) {
        for (let i = 0; i < chunk.length; i++, offset += 2) {
            const sample = Math.max(-1, Math.min(1, chunk[i]));
            view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true);
        }
    }

    return new Blob([buffer], { type: 'audio/wav' });
}
