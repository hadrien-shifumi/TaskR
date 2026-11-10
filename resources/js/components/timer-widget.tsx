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
    timer?: TimerState | null;
    stopped: StoppedTimerProposal[];
};

/** Body of the timer start/stop JSON endpoints. */
type TimerRequestBody = {
    stop_previous?: boolean;
    timer_id?: string;
};

export type TimerControlHandlers = {
    /** Called with the proposals of the timers that were stopped (or replaced). */
    onProposal?: (proposals: StoppedTimerProposal[]) => void;
    /** Called with the server refusal message on a failed start, null to clear. */
    onError?: (message: string | null) => void;
};

export type TimerStartOptions = {
    /** Stop every other running timer first — their elapsed time is proposed. */
    stopPrevious?: boolean;
};

export type TimerControl = {
    activeTimers: TimerState[];
    processing: boolean;
    start: (issueId: string, options?: TimerStartOptions) => void;
    stop: (timerId?: string) => void;
};

/**
 * Start/stop control for the user's running timers. The active timers come
 * from the shared `timers` prop — the server's truth, rehydrated on every
 * navigation — and each action re-syncs that prop after the JSON response.
 * Nothing is ever stopped silently: a start only stops the other running
 * timers when `stopPrevious` is explicitly asked for, and the produced
 * proposals (one per stopped timer) are handed to the caller so it can
 * queue the confirmation dialogs.
 */
export function useTimerControl(handlers: TimerControlHandlers = {}): TimerControl {
    const { timers } = usePage<PageSharedProps>().props;
    const http = useHttp<TimerRequestBody, TimerActionResponse>({});
    const httpRef = useRef(http);
    httpRef.current = http;
    const handlersRef = useRef(handlers);
    handlersRef.current = handlers;

    /** Sync the shared prop, then surface the proposals of stopped timers. */
    const syncAndPropose = useCallback((data: TimerActionResponse | null) => {
        router.reload({ only: ['timers'] });
        if (data?.stopped?.length) {
            handlersRef.current.onProposal?.(data.stopped);
        }
    }, []);

    const start = useCallback(
        (issueId: string, options: TimerStartOptions = {}) => {
            handlersRef.current.onError?.(null);
            httpRef.current.setData({ stop_previous: options.stopPrevious ?? false });
            httpRef.current.post(route('issues.timer.start', issueId), {
                onSuccess: (data) => syncAndPropose(data),
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
        (timerId?: string) => {
            httpRef.current.setData(timerId ? { timer_id: timerId } : {});
            httpRef.current.post(route('timer.stop'), {
                onSuccess: (data) => syncAndPropose(data),
                onHttpException: () => {
                    // The timer is already gone (e.g. double click): resync.
                    router.reload({ only: ['timers'] });
                    return false;
                },
            });
        },
        [syncAndPropose],
    );

    return { activeTimers: timers ?? [], processing: http.processing, start, stop };
}

/** Queue of stopped-timer proposals and its sequential confirmation state. */
export function useTimerProposals() {
    const [proposals, setProposals] = useState<StoppedTimerProposal[]>([]);

    /** Queue the proposals of one or several stopped timers. */
    const push = useCallback((incoming: StoppedTimerProposal[]) => {
        setProposals((current) => [...current, ...incoming]);
    }, []);

    /** Drop the proposal being confirmed — the next one, if any, opens. */
    const consumeOne = useCallback(() => {
        setProposals(([, ...rest]) => rest);
    }, []);

    return { proposals, push, consumeOne };
}

/**
 * Sequential confirmation of stopped timers: one pre-filled dialog at a
 * time, oldest first. Closing (validating or cancelling) reveals the next
 * proposal, so several timers stopped at once never lose their time.
 */
export function TimerProposalDialogs({
    proposals,
    onConsumed,
}: {
    proposals: StoppedTimerProposal[];
    onConsumed: () => void;
}) {
    const proposal = proposals[0];

    return proposal ? (
        <LogTimeDialog
            issueId={proposal.issueId}
            open
            initialHours={String(proposal.roundedHours)}
            issueTitle={proposal.issueTitle}
            exceeds24h={proposal.exceeds24h}
            onClose={onConsumed}
        />
    ) : null;
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
 * Global indicator of the user's running timers, mounted once in the app
 * shell: one row per timer with the timed issue, the locally ticking
 * elapsed time (no server round trip) and its own stop button opening the
 * pre-filled confirmation dialog. Renders nothing without an active timer.
 */
export function TimerWidget() {
    const { t } = useTranslation();
    const [now, setNow] = useState(() => Date.now());
    const proposals = useTimerProposals();
    const control = useTimerControl({ onProposal: proposals.push });

    useEffect(() => {
        const interval = setInterval(() => setNow(Date.now()), 1000);

        return () => clearInterval(interval);
    }, []);

    const timers = control.activeTimers;

    return (
        <>
            {timers.length > 0 ? (
                <div
                    data-test="timer-widget"
                    aria-label={t('timer.running')}
                    className="fixed right-4 bottom-4 z-40 flex flex-col items-end gap-2"
                >
                    {timers.map((timer) => (
                        <div
                            key={timer.id}
                            data-test={`timer-widget-row-${timer.issueId}`}
                            className="flex items-center gap-3 rounded-full border bg-card py-2 pr-2 pl-4 shadow-lg"
                        >
                            <span aria-hidden className="size-2 shrink-0 animate-pulse rounded-full bg-primary" />
                            <span className="min-w-0 max-w-40 truncate text-[13px] font-medium sm:max-w-56">
                                {timer.issueTitle}
                            </span>
                            <span
                                data-test={`timer-widget-elapsed-${timer.issueId}`}
                                className="font-mono text-[13px] tabular-nums text-muted-foreground"
                            >
                                {formatElapsed(timer.startedAt, now)}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                data-test={`timer-widget-stop-${timer.issueId}`}
                                disabled={control.processing}
                                onClick={() => control.stop(timer.id)}
                            >
                                <Square className="size-3" aria-hidden />
                                {t('timer.stop')}
                            </Button>
                        </div>
                    ))}
                </div>
            ) : null}
            <TimerProposalDialogs proposals={proposals.proposals} onConsumed={proposals.consumeOne} />
        </>
    );
}
