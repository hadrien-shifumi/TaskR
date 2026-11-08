<?php

namespace App\Actions\TimeTracking;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Actions\TimeTracking\Concerns\InteractsWithTimesheetWeek;
use App\Models\User;
use App\Responders\TimeTracking\MyTimePageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

class ShowMyTimePage
{
    use AsController;
    use AuthorizesWorkspaceAccess;
    use InteractsWithTimesheetWeek;

    public function authorize(): bool
    {
        return $this->currentUserCanUseWorkspace();
    }

    /**
     * The "My time" page: the author's weekly grid, shareable through the
     * `week` URL parameter. Unlike the JSON feed, a shared URL never fails:
     * an unreadable or out-of-window week is clamped onto the current week
     * with a warning banner.
     */
    public function asController(Request $request, MyTimePageResponder $responder): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $raw = $request->query('week');
        $weekStart = $this->parseWeekAnchor($raw);
        $warning = null;

        if ($weekStart !== null && $this->weekOutOfBounds($weekStart)) {
            // Out of the retroactivity window: warn, show the current week.
            $weekStart = null;
            $warning = __('flash.time_entry.week_clamped');
        } elseif ($raw !== null && $weekStart === null) {
            // An unreadable shared value never fails the page: warn.
            $warning = __('flash.time_entry.week_clamped');
        }

        $payload = app(ShowWeekTimesheet::class)->handle(
            $user,
            $weekStart?->toDateString(),
        );

        if ($warning !== null) {
            $payload['warning'] = $warning;
        }

        return $responder->toResponse($request, $payload);
    }
}
