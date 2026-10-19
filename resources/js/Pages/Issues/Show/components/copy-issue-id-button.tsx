import { Button } from '@/components/ui/button';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Copies the full issue identifier to the clipboard and flips to a
 * short "Copied" feedback. Shared by the fiche pagebar (`Issues/Show`)
 * and the board's issue modal header.
 */
export function CopyIssueIdButton({ issueId }: { issueId: string }) {
    const { t } = useTranslation();
    const [copied, setCopied] = useState(false);

    const copyId = () => {
        navigator.clipboard?.writeText(issueId).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    };

    return (
        <Button variant="ghost" size="sm" data-test="copy-identifier" onClick={copyId}>
            {copied ? <Check className="size-3.5" aria-hidden /> : <Copy className="size-3.5" aria-hidden />}
            {copied ? t('issueShow.copied') : t('issueShow.copyIdentifier')}
        </Button>
    );
}
