<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Responders\Auth\LocaleRedirectResponder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateUserLocale
{
    use AsController;
    use AsObject;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(array_keys(config('i18n.locales', [])))],
        ];
    }

    /**
     * Persists the user's interface language preference.
     *
     * @param  array{locale: string}  $data
     */
    public function handle(User $user, string $locale): User
    {
        $user->update(['locale' => $locale]);

        App::setLocale($locale);

        return $user->refresh();
    }

    public function asController(ActionRequest $request, LocaleRedirectResponder $responder): RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $this->handle($user, $request->validated('locale'));

        return $responder->updated();
    }
}
