/**
 * Anchor helpers shared by the activity timeline and the comments thread.
 *
 * The timeline scrolls the comment row directly in the DOM and flashes it:
 * when the comments accordion is folded, the issue fiche first unfolds it
 * (state lifted in `IssueDetail`) and defers the scroll to the effect that
 * runs once the panel has rendered — `scrollToComment` stays the shared DOM
 * helper for both the immediate and the post-unfold path.
 */

/** Marks a comment row as recently targeted: the CSS transition fades it back. */
const FLASH_CLASS = 'comment-flash';

/** How long the comment stays highlighted after the scroll. */
const FLASH_DURATION_MS = 1500;

/** DOM id of a comment row, shared by the comments list and the timeline. */
export function commentAnchorId(commentId: string): string {
    return `comment-${commentId}`;
}

/**
 * Scrolls to the comment and flashes it. Returns false when the comment is
 * not on the page (deleted, or outside the rendered thread) so the caller
 * can fall back to its own rendering.
 */
export function scrollToComment(commentId: string): boolean {
    const element = document.getElementById(commentAnchorId(commentId));

    if (element === null) {
        return false;
    }

    element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    element.classList.add(FLASH_CLASS);

    window.setTimeout(() => {
        element.classList.remove(FLASH_CLASS);
    }, FLASH_DURATION_MS);

    return true;
}
