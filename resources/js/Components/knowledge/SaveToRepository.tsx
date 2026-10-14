import { Library, Loader2 } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { xsrf } from '@/lib/attachments';

interface Options {
    can: boolean;
    domains: { id: number; name: string }[];
    folders: { id: number; domain_id: number; path: string }[];
}

/**
 * «Guardar no repositório» on a file of a conversation (/files/{id}) or of an
 * email (/attachments/{id}): a copy goes to a domain and folder of the
 * knowledge base, where it is read and searched like any other file.
 */
export function SaveToRepository({ url, name }: { url: string; name: string }) {
    const source = url.startsWith('/files/') ? 'attachment' : url.startsWith('/attachments/') ? 'email_attachment' : null;
    const id = Number(url.split('/')[2]);
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState<Options | null>(null);
    const [domain, setDomain] = useState('');
    const [folder, setFolder] = useState('');
    const [title, setTitle] = useState(name.replace(/\.[^.]+$/, ''));
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<{ url: string; title: string; existed: boolean } | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open || options) {
            return;
        }

        fetch('/knowledge/import/options', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error())))
            .then((data: Options) => {
                setOptions(data);
                setDomain(String(data.domains[0]?.id ?? ''));
            })
            .catch(() => setError('Não foi possível abrir o repositório.'));
    }, [open, options]);

    if (source === null || !Number.isFinite(id)) {
        return null;
    }

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);

        const response = await fetch('/knowledge/import', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { ...xsrf(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ source, id, title, domain_id: Number(domain), folder_id: folder ? Number(folder) : null }),
        }).catch(() => null);

        setBusy(false);

        if (!response?.ok) {
            const data = (await response?.json().catch(() => ({}))) as { message?: string } | undefined;
            setError(data?.message || 'Não foi possível guardar.');

            return;
        }

        setResult(await response.json());
    };

    const folders = options?.folders.filter((f) => String(f.domain_id) === domain) ?? [];

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline">
                    <Library />
                    Guardar no repositório
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-80">
                {result ? (
                    <div className="flex flex-col gap-3 text-sm">
                        <p>{result.existed ? 'Este ficheiro já estava no repositório.' : 'Guardado. O texto é lido em segundo plano.'}</p>
                        <Button asChild size="sm">
                            <a href={result.url}>Abrir «{result.title}»</a>
                        </Button>
                    </div>
                ) : options === null ? (
                    <div className="flex items-center gap-2 py-4 text-sm text-muted-foreground">
                        {error ?? (
                            <>
                                <Loader2 className="size-4 animate-spin" />A abrir…
                            </>
                        )}
                    </div>
                ) : !options.can ? (
                    <p className="text-sm text-muted-foreground">Não tem permissão para escrever no Conhecimento.</p>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-3">
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="repo-title" className="text-xs font-medium">
                                Título
                            </label>
                            <Input id="repo-title" value={title} onChange={(e) => setTitle(e.target.value)} />
                        </div>
                        <div className="flex flex-col gap-1.5">
                            <label htmlFor="repo-domain" className="text-xs font-medium">
                                Domínio
                            </label>
                            <NativeSelect
                                id="repo-domain"
                                value={domain}
                                onChange={(e) => {
                                    setDomain(e.target.value);
                                    setFolder('');
                                }}
                            >
                                {options.domains.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        {folders.length > 0 && (
                            <div className="flex flex-col gap-1.5">
                                <label htmlFor="repo-folder" className="text-xs font-medium">
                                    Pasta
                                </label>
                                <NativeSelect id="repo-folder" value={folder} onChange={(e) => setFolder(e.target.value)}>
                                    <option value="">Na raiz do domínio</option>
                                    {folders.map((f) => (
                                        <option key={f.id} value={f.id}>
                                            {f.path}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                        )}
                        {error && <p className="text-sm text-destructive">{error}</p>}
                        <Button type="submit" size="sm" disabled={busy || domain === ''}>
                            {busy ? 'A guardar…' : 'Guardar'}
                        </Button>
                    </form>
                )}
            </PopoverContent>
        </Popover>
    );
}
