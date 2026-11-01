<?php

namespace App\Actions\Auth;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\User;
use App\Presenters\Auth\NotificationPreferencesPresenter;
use App\Responders\Auth\NotificationSettingsPageResponder;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Symfony\Component\HttpFoundation\Response;

class ShowNotificationSettingsPage
{
    use AsController;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserIsFullMember();
    }

    /**
     * Reads the account's notification preferences (defaults when the user
     * never saved them) plus the timezone options of the form.
     *
     * @return array<string, mixed>
     */
    public function handle(User $user): array
    {
        $preference = $user->notificationPreference()->first();

        return [
            'preferences' => NotificationPreferencesPresenter::present($preference),
            'timezones' => timezone_identifiers_list(),
        ];
    }

    public function asController(Request $request, NotificationSettingsPageResponder $responder): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        return $responder->toResponse($request, $this->handle($user));
    }
}
