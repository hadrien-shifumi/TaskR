/**
 * Reading metadata for a markdown article — computed client-side from the
 * raw body, no backend involved.
 *
 * Word-count convention: the markdown syntax is stripped (headings,
 * emphasis markers, links/images keep only their text, code fences keep
 * their content) and the remaining words are counted by splitting on
 * whitespace. Reading time: 200 words per minute, rounded, at least one
 * minute.
 */

const READING_WORDS_PER_MINUTE = 200;

/**
 * Strips the markdown subset the editor serializes and returns the
 * remaining prose: headings lose their hashes, links/images keep only
 * their text or alt, emphasis markers disappear, code fences keep their
 * content, list markers and table pipes disappear.
 */
export function stripMarkdown(source: string): string {
    return source
        .split('\n')
        .map((line) => {
            // Fenced code: keep the content, drop the fence markers.
            if (/^\s*(```|~~~)/.test(line)) {
                return '';
            }
            return line
                // Headings, blockquotes: drop the markers.
                .replace(/^\s{0,3}#{1,6}\s+/, '')
                .replace(/^\s{0,3}>\s?/, '')
                // Images keep their alt text, links keep their label.
                .replace(/!\[([^\]]*)\]\([^)\s]*\)/g, '$1')
                .replace(/\[([^\]]*)\]\([^)\s]*\)/g, '$1')
                // Emphasis and inline code markers.
                .replace(/(\*\*|__)(.*?)\1/g, '$2')
                .replace(/(\*|_)(.*?)\1/g, '$2')
                .replace(/~~(.*?)~~/g, '$2')
                .replace(/`([^`]+)`/g, '$1')
                // List markers and table pipes.
                .replace(/^\s*([-*+]|\d+[.)])\s+/, '')
                .replace(/\|/g, ' ')
                // Horizontal rules.
                .replace(/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/, '');
        })
        .join('\n');
}

/** Number of prose words in a markdown body. */
export function countMarkdownWords(body: string): number {
    const prose = stripMarkdown(body);
    const matches = prose.match(/\S+/g);

    return matches ? matches.length : 0;
}

/** Estimated reading time in minutes — 200 words per minute, at least 1. */
export function readingMinutes(words: number): number {
    return Math.max(1, Math.round(words / READING_WORDS_PER_MINUTE));
}
