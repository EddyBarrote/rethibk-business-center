import { cn } from '@/lib/utils';

export interface BarSeries {
    key: string;
    label: string;
    className: string;
}

/**
 * A small stacked bar chart for a short daily series (Paperclip's dashboard
 * pattern). Plain divs, no chart library: the data is a couple of weeks.
 */
export function MiniBars<T extends { date: string }>({
    data,
    series,
    format = (value) => String(value),
    height = 96,
}: {
    data: T[];
    series: BarSeries[];
    format?: (value: number) => string;
    height?: number;
}) {
    const totals = data.map((day) => series.reduce((sum, item) => sum + Number(day[item.key as keyof T] ?? 0), 0));
    const max = Math.max(...totals, 0);
    const label = (date: string) => new Date(`${date}T00:00:00`).toLocaleDateString('pt-PT', { day: 'numeric', month: 'numeric' });

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-end gap-1" style={{ height }}>
                {data.map((day, index) => (
                    <div
                        key={day.date}
                        className="group relative flex h-full flex-1 flex-col justify-end"
                        title={`${label(day.date)}: ${series.map((item) => `${item.label} ${format(Number(day[item.key as keyof T] ?? 0))}`).join(' · ')}`}
                    >
                        {totals[index] === 0 ? (
                            <div className="h-px w-full bg-border" />
                        ) : (
                            <div className="flex w-full flex-col-reverse overflow-hidden rounded-[3px]" style={{ height: `${Math.max((totals[index] / max) * 100, 4)}%` }}>
                                {series.map((item) => {
                                    const value = Number(day[item.key as keyof T] ?? 0);

                                    return value > 0 ? <div key={item.key} className={cn('w-full', item.className)} style={{ flexGrow: value }} /> : null;
                                })}
                            </div>
                        )}
                    </div>
                ))}
            </div>
            <div className="flex justify-between font-mono text-[10px] text-muted-foreground">
                <span>{data[0] && label(data[0].date)}</span>
                <span>{data[Math.floor(data.length / 2)] && label(data[Math.floor(data.length / 2)].date)}</span>
                <span>{data.at(-1) && label(data.at(-1)!.date)}</span>
            </div>
            {series.length > 1 && (
                <div className="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                    {series.map((item) => (
                        <span key={item.key} className="flex items-center gap-1.5">
                            <span className={cn('size-2 rounded-full', item.className)} />
                            {item.label}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
