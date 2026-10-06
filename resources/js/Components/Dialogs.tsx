import { Loader2, TriangleAlert } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogMedia,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Components/ui/alert-dialog';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

/*
 * The two modal shapes of the console (docs/UI.md):
 * - ConfirmDialog asks before something that cannot be undone, never window.confirm();
 * - FormDialog holds a create or edit form: title, why, fields, then Cancelar on the
 *   left and the action on the right, with the body scrolling when the form is long.
 */

const widths = { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-3xl' };

export function ConfirmDialog({
    trigger,
    open,
    onOpenChange,
    title,
    description,
    children,
    confirmLabel,
    destructive = false,
    processing = false,
    onConfirm,
}: {
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    children?: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    processing?: boolean;
    /** Uncontrolled dialogs close themselves; controlled ones close when the caller says so. */
    onConfirm: () => void;
}) {
    const [inner, setInner] = useState(false);
    const isOpen = open ?? inner;
    const setOpen = onOpenChange ?? setInner;

    return (
        <AlertDialog open={isOpen} onOpenChange={setOpen}>
            {trigger && <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>}
            <AlertDialogContent>
                <AlertDialogHeader>
                    {destructive && (
                        <AlertDialogMedia className="bg-destructive/10 text-destructive">
                            <TriangleAlert />
                        </AlertDialogMedia>
                    )}
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    {description && <AlertDialogDescription>{description}</AlertDialogDescription>}
                </AlertDialogHeader>
                {children}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={processing}>Cancelar</AlertDialogCancel>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={() => {
                            onConfirm();
                            if (onOpenChange === undefined) {
                                setInner(false);
                            }
                        }}
                    >
                        {processing && <Loader2 className="animate-spin" />}
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export function FormDialog({
    trigger,
    open,
    onOpenChange,
    title,
    description,
    children,
    submitLabel,
    processing = false,
    disabled = false,
    onSubmit,
    size = 'md',
    footer,
    className,
}: {
    trigger?: ReactNode;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    submitLabel: string;
    processing?: boolean;
    disabled?: boolean;
    onSubmit: (event: FormEvent) => void;
    size?: keyof typeof widths;
    /** Extra controls on the left of the footer (e.g. a destructive action), instead of Cancelar. */
    footer?: ReactNode;
    className?: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent className={cn('flex max-h-[calc(100dvh-2rem)] flex-col gap-0 p-0', widths[size], className)}>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        onSubmit(event);
                    }}
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <DialogHeader className="border-b px-6 pt-6 pb-4">
                        <DialogTitle className="pr-6">{title}</DialogTitle>
                        {description && <DialogDescription>{description}</DialogDescription>}
                    </DialogHeader>
                    <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">{children}</div>
                    <DialogFooter className="items-center border-t px-6 py-4 sm:justify-between">
                        {footer ?? (
                            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)} disabled={processing}>
                                Cancelar
                            </Button>
                        )}
                        <Button type="submit" disabled={processing || disabled}>
                            {processing && <Loader2 className="animate-spin" />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
