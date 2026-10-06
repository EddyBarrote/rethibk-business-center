import { Eye, EyeOff } from 'lucide-react';
import { type ComponentProps, useState } from 'react';

import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

/** A password field with a show/hide button. */
export function PasswordInput({ className, ...props }: Omit<ComponentProps<typeof Input>, 'type'>) {
    const [visible, setVisible] = useState(false);
    const label = visible ? 'Esconder palavra-passe' : 'Mostrar palavra-passe';

    return (
        <div className="relative">
            <Input type={visible ? 'text' : 'password'} className={cn('pr-10', className)} {...props} />
            <button
                type="button"
                onClick={() => setVisible((value) => !value)}
                className="absolute inset-y-0 right-0 flex w-10 items-center justify-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label={label}
                title={label}
            >
                {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
            </button>
        </div>
    );
}
