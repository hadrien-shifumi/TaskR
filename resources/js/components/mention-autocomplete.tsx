import * as React from "react";
import { useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import { AtSign, Folder } from "lucide-react";
import { cn } from "@/lib/utils";
import { Textarea } from "@/components/ui/textarea";
import type { PageSharedProps } from "@/types";

type MentionAutocompleteProps = Omit<
    React.ComponentProps<"textarea">,
    "value" | "onChange"
> & {
    value: string;
    onChange: (value: string) => void;
};

/** Fragment being typed right after an "@", at the caret. */
function activeQuery(
    text: string,
    caret: number,
): { start: number; query: string } | null {
    const before = text.slice(0, caret);
    const match = before.match(/(^|[^a-zA-Z0-9_@])@([a-zA-Z0-9_-]*)$/);

    if (match === null) {
        return null;
    }

    return { start: caret - match[2].length - 1, query: match[2].toLowerCase() };
}

/**
 * Comment textarea with an @mention autocomplete: typing "@" opens the
 * list of mentionable members and projects (shared `mentionables` prop),
 * filtered on the fly. Clicking an option — or pressing Enter / Tab —
 * replaces the current fragment with plain "@handle " text, compatible
 * with the markdown storage (no ProseMirror node involved).
 */
export function MentionAutocomplete({
    value,
    onChange,
    ...textareaProps
}: MentionAutocompleteProps) {
    const { mentionables } = usePage<PageSharedProps>().props;
    const textareaRef = useRef<HTMLTextAreaElement | null>(null);
    // Suppresses the caret re-detection right after an insertion, until
    // the caret is restored to its post-insertion position.
    const suppressDetect = useRef(false);
    const [query, setQuery] = useState<{ start: number; query: string } | null>(
        null,
    );
    const [activeIndex, setActiveIndex] = useState(0);

    const options =
        query === null
            ? []
            : (mentionables ?? [])
                  .filter(
                      (mentionable) =>
                          mentionable.handle.startsWith(query.query) ||
                          mentionable.name
                              .toLowerCase()
                              .includes(query.query),
                  )
                  .slice(0, 8);

    const detect = () => {
        if (suppressDetect.current) {
            return;
        }

        const textarea = textareaRef.current;

        if (textarea === null) {
            return;
        }

        const next = activeQuery(
            textarea.value,
            textarea.selectionStart ?? textarea.value.length,
        );

        setQuery(next);

        if (next === null) {
            setActiveIndex(0);
        }
    };

    const handleChange = (
        event: React.ChangeEvent<HTMLTextAreaElement>,
    ) => {
        onChange(event.target.value);

        const next = activeQuery(
            event.target.value,
            event.target.selectionStart ?? event.target.value.length,
        );

        setQuery(next);

        if (next === null) {
            setActiveIndex(0);
        }
    };

    const insert = (handle: string) => {
        const textarea = textareaRef.current;

        if (textarea === null || query === null) {
            return;
        }

        const caret = textarea.selectionStart ?? value.length;
        const nextValue = `${value.slice(0, query.start)}@${handle} ${value.slice(caret)}`;
        const nextCaret = query.start + handle.length + 2;

        suppressDetect.current = true;
        onChange(nextValue);
        setQuery(null);
        setActiveIndex(0);

        requestAnimationFrame(() => {
            textarea.focus();
            textarea.setSelectionRange(nextCaret, nextCaret);
            suppressDetect.current = false;
        });
    };

    const handleKeyDown = (
        event: React.KeyboardEvent<HTMLTextAreaElement>,
    ) => {
        if (query === null || options.length === 0) {
            return;
        }

        if (event.key === "ArrowDown") {
            event.preventDefault();
            setActiveIndex((index) => (index + 1) % options.length);
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            setActiveIndex(
                (index) => (index - 1 + options.length) % options.length,
            );
        } else if (event.key === "Enter" || event.key === "Tab") {
            event.preventDefault();
            insert(options[activeIndex].handle);
        } else if (event.key === "Escape") {
            event.preventDefault();
            setQuery(null);
        }
    };

    return (
        <div className="relative">
            <Textarea
                {...textareaProps}
                ref={textareaRef}
                value={value}
                onChange={handleChange}
                onKeyDown={handleKeyDown}
                onClick={detect}
                onKeyUp={detect}
            />
            {query !== null && options.length > 0 ? (
                <div
                    data-test="mention-autocomplete"
                    role="listbox"
                    className="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-lg border bg-popover p-1 text-popover-foreground shadow-lg"
                >
                    {options.map((mentionable, index) => {
                        const Icon =
                            mentionable.type === "project" ? Folder : AtSign;

                        return (
                            <button
                                key={`${mentionable.type}-${mentionable.handle}`}
                                type="button"
                                role="option"
                                aria-selected={index === activeIndex}
                                data-test="mention-option"
                                data-handle={mentionable.handle}
                                onMouseDown={(event) => {
                                    event.preventDefault();
                                    insert(mentionable.handle);
                                }}
                                className={cn(
                                    "flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent",
                                    index === activeIndex && "bg-accent",
                                )}
                            >
                                <Icon
                                    className="size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden
                                />
                                <span className="font-mono text-xs">
                                    @{mentionable.handle}
                                </span>
                                <span className="truncate text-xs text-muted-foreground">
                                    {mentionable.name}
                                </span>
                            </button>
                        );
                    })}
                </div>
            ) : null}
        </div>
    );
}
