/**
 * Prepares agent markdown for remark-math. Models write formulas as $…$,
 * $$…$$, \(…\) or \[…\], and also write prices with $ ("$120 e $80"). Code is
 * left alone; \(…\) and \[…\] become $…$ and $$…$$; a $$…$$ alone on a line
 * becomes a display block; a $…$ pair counts as a formula only when it reads
 * as one (a LaTeX command, ^, _, {} or =), otherwise its dollars stay text.
 */
export function prepareMath(markdown: string): string {
    if (!/[$\\]/.test(markdown)) {
        return markdown;
    }

    // Fenced code blocks and inline code are kept as they are.
    return markdown
        .split(/(^ {0,3}(?:```|~~~)[^\n]*\n[\s\S]*?(?:^ {0,3}(?:```|~~~)[^\n]*$|$(?![\s\S])))/m)
        .map((part, index) =>
            index % 2 === 1
                ? part
                : part
                      .split(/(`+[^`\n]*`+)/)
                      .map((text, i) => (i % 2 === 1 ? text : prose(text)))
                      .join(''),
        )
        .join('');
}

function prose(text: string): string {
    return (
        text
            .replace(/\\\[([\s\S]+?)\\\]/g, (_, body: string) => `\n$$\n${body.trim()}\n$$\n`)
            .replace(/\\\((.+?)\\\)/g, (_, body: string) => `$${body.trim()}$`)
            // A display formula written on one line.
            .replace(
                /^([ \t]*)\$\$(?!\s*$)(.+?)\$\$[ \t]*$/gm,
                (_, indent: string, body: string) => `${indent}$$\n${indent}${body.trim()}\n${indent}$$`,
            )
            // $…$ on one line: a formula, or two prices.
            .replace(/(?<![\\$])\$(?!\$)([^$\n]+?)(?<!\\)\$(?!\$)/g, (whole, body: string) =>
                looksLikeMath(body) && !/^\s|\s$/.test(body) ? whole : `\\$${body}\\$`,
            )
            // A decimal comma (464,83) is a comma in TeX too, not a list separator with a space after it.
            .replace(/(?<![\\$])\$\$\n[\s\S]+?\n[ \t]*\$\$|(?<![\\$])\$(?!\$)[^$\n]+?(?<!\\)\$/g, (math) => math.replace(/(\d),(?=\d)/g, '$1{,}'))
    );
}

function looksLikeMath(body: string): boolean {
    return /\\[a-zA-Z]+|[\^_{}=]/.test(body);
}
