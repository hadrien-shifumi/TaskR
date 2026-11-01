<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('mail.digest.subject', ['workspace' => $workspace->name]) }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:100%;background-color:#ffffff;border-radius:8px;padding:32px;">
                <tr>
                    <td>
                        <h1 style="margin:0 0 8px;font-size:20px;line-height:28px;">{{ __('mail.digest.greeting', ['name' => $user->name]) }}</h1>
                        <p style="margin:0 0 4px;font-size:14px;line-height:22px;">{{ __('mail.digest.intro', ['workspace' => $workspace->name]) }}</p>
                        <p style="margin:0 0 24px;font-size:12px;line-height:18px;color:#71717a;">
                            {{ __('mail.digest.window', ['date' => \Carbon\CarbonImmutable::parse($digest['windowStart'])->translatedFormat('j F Y')]) }}
                        </p>

                        @if ($digest['overdueIssues'] !== [])
                            <h2 style="margin:0 0 8px;font-size:16px;line-height:24px;">{{ __('mail.digest.sections.overdue') }}</h2>
                            <ul style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:22px;">
                                @foreach ($digest['overdueIssues'] as $issue)
                                    <li style="margin-bottom:6px;">
                                        <strong>{{ $issue['title'] }}</strong> — {{ trans_choice('mail.digest.days_late', $issue['daysLate'], ['count' => $issue['daysLate']]) }}<br>
                                        <span style="font-size:12px;color:#71717a;">{{ __('mail.digest.project', ['name' => $issue['projectName']]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($digest['upcomingIssues'] !== [])
                            <h2 style="margin:0 0 8px;font-size:16px;line-height:24px;">{{ __('mail.digest.sections.upcoming') }}</h2>
                            <ul style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:22px;">
                                @foreach ($digest['upcomingIssues'] as $issue)
                                    <li style="margin-bottom:6px;">
                                        <strong>{{ $issue['title'] }}</strong> —
                                        @if ($issue['daysUntilDue'] === 0)
                                            {{ __('mail.digest.due_today') }}
                                        @else
                                            {{ trans_choice('mail.digest.days_until', $issue['daysUntilDue'], ['count' => $issue['daysUntilDue']]) }}
                                        @endif
                                        <br>
                                        <span style="font-size:12px;color:#71717a;">{{ __('mail.digest.project', ['name' => $issue['projectName']]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($digest['newAssignments'] !== [])
                            <h2 style="margin:0 0 8px;font-size:16px;line-height:24px;">{{ __('mail.digest.sections.assignments') }}</h2>
                            <ul style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:22px;">
                                @foreach ($digest['newAssignments'] as $issue)
                                    <li style="margin-bottom:6px;">
                                        <strong>{{ $issue['title'] }}</strong><br>
                                        <span style="font-size:12px;color:#71717a;">{{ __('mail.digest.project', ['name' => $issue['projectName']]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @if ($digest['newComments'] !== [])
                            <h2 style="margin:0 0 8px;font-size:16px;line-height:24px;">{{ __('mail.digest.sections.comments') }}</h2>
                            <ul style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:22px;">
                                @foreach ($digest['newComments'] as $comment)
                                    <li style="margin-bottom:6px;">
                                        <strong>{{ $comment['issue']['title'] }}</strong> —
                                        {{ __('mail.digest.by', ['name' => $comment['authorName'] ?? '?']) }}<br>
                                        <span style="font-size:12px;color:#71717a;">{{ \Illuminate\Support\Str::limit($comment['body'], 160) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <h2 style="margin:0 0 8px;font-size:16px;line-height:24px;">{{ __('mail.digest.sections.workload') }}</h2>
                        <ul style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:22px;">
                            <li>{{ __('mail.digest.workload.open_issues', ['count' => $digest['workload']['openIssues']]) }}</li>
                            <li>{{ __('mail.digest.workload.overdue_issues', ['count' => $digest['workload']['overdueIssues']]) }}</li>
                            <li>{{ __('mail.digest.workload.estimated_hours', ['hours' => $digest['workload']['estimatedHours']]) }}</li>
                            <li>{{ __('mail.digest.workload.logged_hours', ['hours' => $digest['workload']['loggedHours']]) }}</li>
                            <li>{{ __('mail.digest.workload.remaining_hours', ['hours' => round($digest['workload']['estimatedHours'] - $digest['workload']['loggedHours'], 2)]) }}</li>
                        </ul>

                        @if ($digest['managerHighlights'] !== [])
                            <h2 style="margin:0 0 8px;font-size:16px;line-height:24px;">{{ __('mail.digest.sections.highlights') }}</h2>
                            <ul style="margin:0 0 24px;padding-left:20px;font-size:14px;line-height:22px;">
                                @foreach ($digest['managerHighlights'] as $highlight)
                                    <li style="margin-bottom:6px;">
                                        <strong>{{ $highlight['projectName'] }}</strong> —
                                        {{ trans_choice('mail.digest.highlights.overdue_issues', $highlight['overdueIssues'], ['count' => $highlight['overdueIssues']]) }}
                                        @if ($highlight['completionPercentage'] !== null && $highlight['expectedPercentage'] !== null)
                                            <br>
                                            <span style="font-size:12px;color:#71717a;">{{ __('mail.digest.highlights.gap', ['completion' => $highlight['completionPercentage'], 'expected' => $highlight['expectedPercentage']]) }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <hr style="border:none;border-top:1px solid #e4e4e7;margin:24px 0;">
                        <p style="margin:0;font-size:12px;line-height:18px;color:#71717a;">{{ __('mail.digest.footer') }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
