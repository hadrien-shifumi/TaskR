<?php

namespace Tests\Feature;

use App\Actions\Auth\UpdateUserLocale;
use App\Actions\Workspaces\CreateWorkspace;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_the_english_locale(): void
    {
        $user = User::factory()->create();

        $this->assertSame('en', $user->locale);
    }

    public function test_locale_is_mass_assignable_and_persists(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $this->assertSame('fr', $user->fresh()->locale);
    }

    public function test_authenticated_users_can_switch_their_locale(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('user.locale.update'), ['locale' => 'fr'])
            ->assertRedirect();

        $this->assertSame('fr', $user->fresh()->locale);
    }

    public function test_locale_switching_rejects_unsupported_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('user.locale.update'), ['locale' => 'de'])
            ->assertSessionHasErrors('locale');

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_the_shared_locale_prop_resolves_after_the_request_is_handled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        request()->setLaravelSession(app('session.store'));

        // The shared `locale` prop must be lazy: it is captured by
        // HandleInertiaRequests BEFORE the controller runs, but resolved
        // when the response is rendered, i.e. AFTER UpdateUserLocale has
        // applied the new locale. An eager value would send the PREVIOUS
        // locale back, making the client re-sync to the old language
        // (blink + revert to English).
        $shared = app(HandleInertiaRequests::class)->share(request());

        $this->assertInstanceOf(Closure::class, $shared['locale']);
        $this->assertSame('en', ($shared['locale'])());

        app(UpdateUserLocale::class)->handle($user, 'fr');

        $this->assertSame('fr', ($shared['locale'])());
    }

    public function test_guests_cannot_switch_locale(): void
    {
        $this->put(route('user.locale.update'), ['locale' => 'fr'])
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_the_middleware_applies_the_user_locale_to_the_request(): void
    {
        $user = User::factory()->create(['locale' => 'fr']);

        $this->actingAs($user)->get(route('login', absolute: false));

        $this->assertSame('fr', app()->getLocale());
    }

    public function test_guests_fall_back_to_the_application_locale(): void
    {
        config(['app.locale' => 'en']);

        $this->get(route('login', absolute: false));

        $this->assertSame('en', app()->getLocale());
    }

    public function test_users_with_an_unsupported_locale_fall_back_to_the_application_locale(): void
    {
        config(['app.locale' => 'en']);
        $user = User::factory()->create(['locale' => 'de']);

        $this->actingAs($user)->get(route('login', absolute: false));

        $this->assertSame('en', app()->getLocale());
    }

    public function test_flash_messages_are_rendered_in_the_user_locale(): void
    {
        $owner = User::factory()->create(['locale' => 'fr']);
        $workspace = CreateWorkspace::run($owner, ['name' => 'WS']);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)
            ->post(route('projects.store'), ['name' => 'Site vitrine'])
            ->assertSessionHas('status', 'Projet « Site vitrine » créé.');
    }
}
