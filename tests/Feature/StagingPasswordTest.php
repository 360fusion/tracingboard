<?php

namespace Tests\Feature;

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

    public function test_pages_ask_for_the_password_and_tell_crawlers_to_stay_out(): void
    {
        $this->get('/login')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Tracing Board staging", charset="UTF-8"')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_right_password_opens_the_site_and_a_wrong_one_does_not(): void
    {
        $this->withHeaders($this->basicAuth('staging', self::PASSWORD))->get('/login')->assertOk();

        $this->flushHeaders();
        $this->withHeaders($this->basicAuth('staging', 'wrong'))->get('/login')->assertUnauthorized();
        $this->flushHeaders();
        $this->withHeaders($this->basicAuth('someone', self::PASSWORD))->get('/login')->assertUnauthorized();
    }

    public function test_services_without_a_person_still_get_through(): void
    {
        $this->get('/up')->assertOk();
        $this->post('/webhooks/stripe-connect')->assertHeaderMissing('WWW-Authenticate');
        $this->getJson('/api/v1/user')->assertHeaderMissing('WWW-Authenticate');
    }

    public function test_signed_addresses_get_through_for_the_website_builder(): void
    {
        $this->get(URL::temporarySignedRoute('security.txt', now()->addMinute()))->assertHeaderMissing('WWW-Authenticate');
        $this->get('/.well-known/security.txt?signature=forged')->assertUnauthorized();
    }

    public function test_production_never_asks_for_the_password(): void
    {
        $this->app['env'] = 'production';

        $this->get('/login')->assertOk();
    }
}
