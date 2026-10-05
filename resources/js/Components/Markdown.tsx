import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

/**
 * Markdown written by agents (briefings, documents). Raw HTML is never
 * rendered; links open in the console when they are console paths.
 */
export function Markdown({ children }: { children: string }) {
    return (
        <div className="prose-sm max-w-none text-sm leading-relaxed [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_h1]:mt-4 [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:mt-4 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mt-3 [&_h3]:font-semibold [&_li]:my-0.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-2 [&_table]:w-full [&_td]:align-top [&_hr]:my-4 [&_table]:text-left [&_td]:border-b [&_td]:px-2 [&_td]:py-1 [&_th]:border-b [&_th]:px-2 [&_th]:py-1 [&_th]:font-medium [&_ul]:list-disc [&_ul]:pl-5">
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                skipHtml
                components={{
                    // Wide tables scroll inside the text column instead of spilling over the page.
                    table: ({ node: _node, ...props }) => (
                        <div className="my-3 overflow-x-auto">
                            <table {...props} />
                        </div>
                    ),
                }}
            >
                {children}
            </ReactMarkdown>
        </div>
    );
}
