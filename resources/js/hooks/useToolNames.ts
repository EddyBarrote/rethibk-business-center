import { usePage } from '@inertiajs/react';

/** Capability names by key, on pages whose controller sends them (to title runs written for agents). */
export function useToolNames(): Record<string, string> | undefined {
    return usePage<{ tool_names?: Record<string, string> }>().props.tool_names;
}
