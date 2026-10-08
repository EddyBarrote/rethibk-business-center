import { ImageOff } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { Button } from '@/Components/ui/button';

/*
 * The body of an email. HTML is shown as the sender wrote it, in a sandboxed
 * frame where no script runs. External images load on their own, except in
 * Spam or for whoever chose in Perfil to be asked first (images can tell the
 * sender the email was opened).
 */

const quoted = /^(>|On .+wrote:$|Em .+escreveu:$|-{2,} ?(Original Message|Mensagem original) ?-{2,})/m;

function frameDocument(html: string, images: boolean) {
    const csp = `default-src 'none'; style-src 'unsafe-inline' data:; font-src data:; img-src data: cid:${images ? ' https: http:' : ''}; media-src 'none'`;

    return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="${csp}"><base target="_blank"><style>
        html,body{margin:0;padding:0;background:#fff;color:#1f2328;font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;overflow-wrap:anywhere}
        body{padding:4px 2px}img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}a{color:#0052cc}
        blockquote{margin:0 0 0 .5em;padding-left:.75em;border-left:2px solid #d0d7de;color:#57606a}
    </style></head><body>${html}</body></html>`;
}

function HtmlBody({ html, autoImages }: { html: string; autoImages: boolean }) {
    const frame = useRef<HTMLIFrameElement>(null);
    const [height, setHeight] = useState(120);
    const [images, setImages] = useState(autoImages);
    const remote = useMemo(() => /<img[^>]+src=["']?https?:/i.test(html) || /url\(\s*["']?https?:/i.test(html), [html]);
    const doc = useMemo(() => frameDocument(html, images), [html, images]);

    const fit = () => {
        const body = frame.current?.contentDocument?.documentElement;

        if (body) {
            setHeight(Math.min(Math.max(body.scrollHeight + 8, 60), 20000));
        }
    };

    useEffect(() => {
        const id = window.setTimeout(fit, 300);

        return () => window.clearTimeout(id);
    }, [doc]);

    return (
        <div className="flex flex-col gap-2">
            {remote && !images && (
                <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                    <ImageOff className="size-3.5" />
                    <span className="flex-1">As imagens externas estão escondidas, para o remetente não saber que abriu o email.</span>
                    <Button type="button" variant="outline" size="sm" className="h-7" onClick={() => setImages(true)}>
                        Mostrar imagens
                    </Button>
                </div>
            )}
            <iframe
                ref={frame}
                title="Conteúdo do email"
                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                srcDoc={doc}
                onLoad={fit}
                className="w-full rounded-md bg-white"
                style={{ height }}
            />
        </div>
    );
}

function TextBody({ text }: { text: string }) {
    const [expanded, setExpanded] = useState(false);
    const match = quoted.exec(text);
    const cut = match && match.index > 0 ? match.index : -1;
    const shown = expanded || cut < 0 ? text : text.slice(0, cut).trimEnd();

    return (
        <div className="flex flex-col items-start gap-2">
            <p className="w-full text-sm leading-relaxed break-words whitespace-pre-wrap">{shown || '(sem texto)'}</p>
            {cut >= 0 && (
                <button
                    type="button"
                    onClick={() => setExpanded((value) => !value)}
                    className="rounded-md bg-muted px-2 text-xs text-muted-foreground hover:text-foreground"
                    title={expanded ? 'Esconder o texto citado' : 'Mostrar o texto citado'}
                >
                    {expanded ? 'Esconder citação' : '•••'}
                </button>
            )}
        </div>
    );
}

export function EmailBody({ html, text, images = true }: { html: string | null; text: string; images?: boolean }) {
    return html && html.trim() !== '' ? <HtmlBody html={html} autoImages={images} /> : <TextBody text={text} />;
}
