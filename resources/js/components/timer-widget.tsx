import { LogTimeDialog } from '@/Pages/Issues/Show/components/log-time-dialog';
import { Button } from '@/components/ui/button';
import i18n from '@/i18n';
import { router, useHttp, usePage } from '@inertiajs/react';
import { Square } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageSharedProps, StoppedTimerProposal, TimerState } from '@/types';

/** Shape of the timer start/stop JSON endpoints. */
type TimerActionResponse = {
    timer: TimerState | null;
    stopped: StoppedTimerProposal | null;
};

export type TimerControlHandlers = {
    /** Called with the proposal of a timer that was stopped or replaced. */
    onProposal?: (proposal: StoppedTimerProposal) => void;
    /** Called with the server refusal message on a failed start, null to clear. */
    onError?: (message: string | null) => void;
};

export type TimerControl = {
    activeTimer: TimerState | null;
    processing: boolean;
    start: (issueId: string) => void;
    stop: () => void;
};

/**
 * Start/stop control for the user's running timer. The active timer comes
 * from the shared `timer` prop — the server's truth, rehydrated on every
 * navigation — and each action re-syncs that prop after the JSON response.
 * A stop (or a start that replaces a running timer) hands the pre-filled
 * proposal to the caller so it can open the confirmation dialog.
 */
export function useTimerControl(handlers: TimerControlHandlers = {}): TimerControl {
    const { timer } = usePage<PageSharedProps>().props;
    const http = useHttp<TimerActionResponse>();
    const httpRef = useRef(http);
    httpRef.current = http;
    const handlersRef = useRef(handlers);
    handlersRef.current = handlers;

    /** Sync the shared prop, then surface a proposal when one was produced. */
    const syncAndPropose = useCallback((data: TimerActionResponse | null) => {
        router.reload({ only: ['timer'] });
        if (data?.stopped) {
            handlersRef.current.onProposal?.(data.stopped);
        }
    }, []);

    const start = useCallback(
        (issueId: string) => {
            handlersRef.current.onError?.(null);
            httpRef.current.post(route('issues.timer.start', issueId), {
                onSuccess: syncAndPropose,
                onError: (errors) => {
                    handlersRef.current.onError?.(errors.issue ?? null);
                },
                onHttpException: () => {
                    handlersRef.current.onError?.(i18n.t('timer.startRefused'));
                    return false;
                },
            });
        },
        [syncAndPropose],
    );

    const stop = useCallback(
        () => {
            httpRef.current.post(route('timer.stop'), {
                onSuccess: syncAndPropose,
                onHttpException: () => {
                    // The timer is already gone (e.g. double click): resync.
                    router.reload({ only: ['timer'] });
                    return false;
                },
            });
        },
        [syncAndPropose],
    );

    return { activeTimer: timer ?? null, processing: http.processing, start, stop };
}

/** h:mm:ss with unbounded hours (26:15:09 past one day). */
export function formatElapsed(startedAt: string, now: number): string {
    const totalSeconds = Math.max(0, Math.floor((now - new Date(startedAt).getTime()) / 1000));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    return `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

/**
 * Global indicator of the user's running timer, mounted once in the app
 * shell: the timed issue, the locally ticking elapsed time (no server
 * round trip) and a stop button opening the pre-filled confirmation
 * dialog. Renders nothing without an active timer.
 */
export function TimerWidget() {
    const { t } = useTranslation();
    const [now, setNow] = useState(() => Date.now());
    const [proposal, setProposal] = useState<StoppedTimerProposal | null>(null);
    const control = useTimerControl({ onProposal: setProposal });

    useEffect(() => {
        const interval = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(interval);
    }, []);

    const timer = control.activeTimer;

    return (
        <>
            {timer ? (
                <div
                    data-test="timer-widget"
                    className="fixed right-4 bottom-4 z-40 flex items-center gap-3 rounded-full border bg-card py-2 pr-2 pl-4 shadow-lg"
                >
                    <span aria-hidden className="size-2 shrink-0 animate-pulse rounded-full bg-primary" />
                    <span className="min-w-0 max-w-40 truncate text-[13px] font-medium sm:max-w-56">
                        {timer.issueTitle}
                    </span>
                    <span
                        data-test="timer-widget-elapsed"
                        className="font-mono text-[13px] tabular-nums text-muted-foreground"
                    >
                        {formatElapsed(timer.startedAt, now)}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="timer-widget-stop"
                        disabled={control.processing}
                        onClick={control.stop}
                    >
                        <Square className="size-3" aria-hidden />
                        {t('timer.stop')}
                    </Button>
                </div>
            ) : null}
            {proposal ? (
                <LogTimeDialog
                    issueId={proposal.issueId}
                    open
                    initialHours={String(proposal.roundedHours)}
                    issueTitle={proposal.issueTitle}
                    exceeds24h={proposal.exceeds24h}
                    onClose={() => setProposal(null)}
                />
            ) : null}
        </>
    );
}
