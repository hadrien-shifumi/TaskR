<?php

namespace App\Actions\Auth;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Enums\DigestFrequency;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Responders\Auth\NotificationPreferencesRedirectResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateNotificationPreferences
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        return $this->currentUserIsFullMember();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::enum(DigestFrequency::class)],
            'upcoming_threshold_days' => ['required', 'integer', 'min:1', 'max:90'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ];
    }

    /**
     * Saves the account's digest settings: the row is created on the first
     * save, updated afterwards. Opting out keeps the other settings intact.
     *
     * @param  array{enabled: bool|int|string, frequency: string, upcoming_threshold_days: int|string, timezone: string}  $data
     */
    public function handle(User $user, array $data): NotificationPreference
    {
        $preference = NotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'enabled' => filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN),
                'frequency' => $data['frequency'],
                'upcoming_threshold_days' => (int) $data['upcoming_threshold_days'],
                'timezone' => $data['timezone'],
            ],
        );

        return $preference->refresh();
    }

    public function asController(ActionRequest $request, NotificationPreferencesRedirectResponder $responder): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $this->handle($user, $request->validated());

        return $responder->updated();
    }
}
