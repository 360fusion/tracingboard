<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireStagingPassword;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StagingPasswordTest extends TestCase
{
    private const PASSWORD = 'test-staging-password';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.staging_password' => ['user' => 'staging', 'password' => self::PASSWORD]]);
    }

    private function basicAuth(string $user, string $password): array
    {
        return ['Authorization' => 'Basic '.base64_encode("{$user}:{$password}")];
    }

    public function test_site_is_open_when_no_password_is_set(): void
    {
        config(['app.staging_password.password' => null]);

        $this->get('/login')->assertOk();
    }

    public function test_pages_show_the_password_form_without_a_browser_pop_up(): void
    {
        $this->get('/members?tab=events')
            ->assertUnauthorized()
            ->assertHeaderMissing('WWW-Authenticate')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Private test site')
            ->assertSee('name="back" value="/members?tab=events"', false);
    }

    public function test_right_password_sets_a_cookie_that_opens_the_site(): void
    {
        $response = $this->post('/staging-password', ['password' => self::PASSWORD, 'back' => '/login']);

        $response->assertRedirect('/login')->assertCookie(RequireStagingPassword::COOKIE, null, false);
        $cookie = $response->getCookie(RequireStagingPassword::COOKIE, false);

        $this->withUnencryptedCookie(RequireStagingPassword::COOKIE, $cookie->getValue())->get('/login')->assertOk();
    }

    public function test_wrong_password_or_forged_cookie_keeps_the_site_closed(): void
    {
        $this->post('/staging-password', ['password' => 'wrong', 'back' => '/login'])
            ->assertUnauthorized()
            ->assertCookieMissing(RequireStagingPassword::COOKIE)
            ->assertSee('That password is not right.');

        $this->withUnencryptedCookie(RequireStagingPassword::COOKIE, 'made-up')->get('/login')->assertUnauthorized();
    }

    public function test_a_changed_password_signs_everyone_out(): void
    {
        $cookie = $this->post('/staging-password', ['password' => self::PASSWORD])->getCookie(RequireStagingPassword::COOKIE, false);
        config(['app.staging_password.password' => 'a-new-password']);

        $this->withUnencryptedCookie(RequireStagingPassword::COOKIE, $cookie->getValue())->get('/login')->assertUnauthorized();
    }

    public function test_back_address_cannot_send_people_to_another_site(): void
    {
        $this->post('/staging-password', ['password' => self::PASSWORD, 'back' => '//evil.example/x'])->assertRedirect('/');
        $this->post('/staging-password', ['password' => self::PASSWORD, 'back' => 'https://evil.example'])->assertRedirect('/');
    }

    public function test_too_many_wrong_passwords_are_slowed_down(): void
    {
        foreach (range(1, 10) as $ignored) {
            $this->post('/staging-password', ['password' => 'wrong']);
        }

        $this->post('/staging-password', ['password' => self::PASSWORD])->assertStatus(429)->assertCookieMissing(RequireStagingPassword::COOKIE);
    }

    public function test_scripts_can_still_send_the_password_as_basic_auth(): void
    {
        $this->withHeaders($this->basicAuth('staging', self::PASSWORD))->get('/login')->assertOk();

        $this->flushHeaders();
        $this->withHeaders($this->basicAuth('staging', 'wrong'))->get('/login')->assertUnauthorized();
    }

    public function test_services_without_a_person_still_get_through(): void
    {
        $this->get('/up')->assertOk();
        $this->post('/webhooks/stripe-connect')->assertDontSee('Private test site');
        $this->getJson('/api/v1/user')->assertDontSee('Private test site');
    }

    public function test_signed_addresses_get_through_for_the_website_builder(): void
    {
        $this->get(URL::temporarySignedRoute('security.txt', now()->addMinute()))->assertDontSee('Private test site');
        $this->get('/.well-known/security.txt?signature=forged')->assertUnauthorized();
    }

    public function test_production_never_asks_for_the_password(): void
    {
        $this->app['env'] = 'production';

        $this->get('/login')->assertOk();
    }
}
