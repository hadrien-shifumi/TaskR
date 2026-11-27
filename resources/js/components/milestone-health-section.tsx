import { Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { MilestoneHealthBadge } from '@/components/milestone-badges';
import { Progress } from '@/components/progress';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDate } from '@/lib/format-date';
import type { MilestoneHealthData } from '@/types';

/**
 * Per-milestone trajectory of a project (manager view): expected pace
 * vs real pace, with a status badge. Empty state when the project has
 * no milestone at all.
 */
export function MilestoneHealthSection({ data }: { data: MilestoneHealthData | null }) {
    const { t } = useTranslation();
    const milestones = data?.milestones ?? [];

    return (
        <Card data-test="milestone-health-section" className="py-5">
            <CardContent className="grid gap-3.5 px-5">
                <h2 className="text-base font-semibold">{t('projectHealth.milestones.sectionTitle')}</h2>
                {milestones.length === 0 ? (
                    <p className="py-6 text-center text-xs text-muted-foreground">
                        {t('projectHealth.milestones.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('projectHealth.milestones.columnTitle')}</TableHead>
                                <TableHead>{t('projectHealth.milestones.columnDates')}</TableHead>
                                <TableHead>{t('projectHealth.milestones.columnExpected')}</TableHead>
                                <TableHead>{t('projectHealth.milestones.columnReal')}</TableHead>
                                <TableHead>{t('projectHealth.milestones.columnStatus')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {milestones.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={route('milestones.show', row.id)}
                                            className="font-medium hover:text-primary"
                                        >
                                            {row.title}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground tabular-nums">
                                        {formatDate(row.startDate)} → {formatDate(row.targetDate)}
                                    </TableCell>
                                    <TableCell>
                                        {row.expectedPercentage !== null ? (
                                            <span
                                                className="flex items-center gap-2"
                                                title={t('projectHealth.milestones.expectedHint')}
                                            >
                                                <Progress value={row.expectedPercentage} color="muted" className="w-16" />
                                                <span className="font-mono text-xs tabular-nums">{row.expectedPercentage}%</span>
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {row.realPercentage !== null ? (
                                            <span
                                                className="flex items-center gap-2"
                                                title={t('projectHealth.milestones.realHint')}
                                            >
                                                <Progress value={row.realPercentage} color="success" className="w-16" />
                                                <span className="font-mono text-xs tabular-nums">{row.realPercentage}%</span>
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <MilestoneHealthBadge status={row.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
