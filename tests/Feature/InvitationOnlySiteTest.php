<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ContactMessage;
use App\Models\Lodge;
use App\Models\User;
use App\Notifications\LodgeClaimNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * While Tracing Board is invitation-only: lodges ask through the contact form, nobody signs up without a lodge, and
 * the directory is for signed-in members.
 */
class InvitationOnlySiteTest extends TestCase
{
    use RefreshDatabase;

    private const SENT = 'Thank you. We have your message and will reply by email, usually within two working days.';

    /**
     * @return array<string, string>
     */
    private function message(array $overrides = []): array
    {
        return [
            'name' => 'John Smith',
            'email' => 'secretary@example.org',
            'lodge' => 'Lodge of Harmony No. 1234',
            'topic' => 'access',
            'message' => 'We would like to use it for our summonses.',
            'started_at' => Crypt::encryptString((string) now()->subMinute()->getTimestamp()),
            ...$overrides,
        ];
    }

    public function test_a_message_is_kept_and_sent_to_the_superadmins(): void
    {
        Notification::fake();
        $superadmin = User::factory()->create(['is_super_admin' => true]);

        $this->post(route('contact.store'), $this->message())
            ->assertRedirect(route('contact'))
            ->assertSessionHas('success', self::SENT);

        $this->assertDatabaseHas('contact_messages', ['email' => 'secretary@example.org', 'topic' => 'access', 'lodge' => 'Lodge of Harmony No. 1234', 'handled_at' => null]);
        Notification::assertSentTo($superadmin, LodgeClaimNotification::class, fn (LodgeClaimNotification $notification) => str_contains($notification->body, 'secretary@example.org'));
    }

    public function test_the_form_asks_about_access_unless_the_link_names_another_topic(): void
    {
        $this->get(route('contact'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Contact')->where('topic', 'access'));
        $this->get(route('contact', ['topic' => 'pricing']))->assertInertia(fn (Assert $page) => $page->where('topic', 'pricing'));
        $this->get(route('contact', ['topic' => 'nonsense']))->assertInertia(fn (Assert $page) => $page->where('topic', 'access'));
    }

    public function test_a_message_needs_a_name_an_email_a_known_topic_and_a_message(): void
    {
        $this->post(route('contact.store'), $this->message(['name' => '', 'email' => 'not-an-email', 'topic' => 'nonsense', 'message' => '']))
            ->assertSessionHasErrors(['name', 'email', 'topic', 'message']);

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_a_script_is_told_the_message_was_sent_but_nothing_is_kept(): void
    {
        Notification::fake();
        User::factory()->create(['is_super_admin' => true]);

        // The hidden field filled in, the form sent within a second of opening it, and a made-up start time.
        foreach ([['website' => 'http://spam.example'], ['started_at' => Crypt::encryptString((string) now()->getTimestamp())], ['started_at' => 'forged']] as $spam) {
            $this->post(route('contact.store'), $this->message($spam))->assertSessionHas('success', self::SENT);
        }

        $this->assertDatabaseCount('contact_messages', 0);
        Notification::assertNothingSent();
    }

    public function test_superadmins_read_messages_and_mark_them_handled_and_members_cannot(): void
    {
        $superadmin = User::factory()->create(['is_super_admin' => true]);
        $message = ContactMessage::factory()->create();

        // A member is sent away from the superadmin area, and cannot mark a message handled either.
        $this->actingAs(User::factory()->create())->get(route('superadmin.contact_messages.index'))->assertRedirect();
        $this->post(route('superadmin.contact_messages.handle', ['id' => $message->id]))->assertRedirect();
        $this->assertNull($message->fresh()->handled_at);

        $this->actingAs($superadmin)->get(route('superadmin.contact_messages.index'))
            ->assertInertia(fn (Assert $page) => $page->component('SuperAdmin/ContactMessages/Index')->where('messages.data.0.id', $message->id)->where('newCount', 1));

        $this->post(route('superadmin.contact_messages.handle', ['id' => $message->id]))->assertRedirect();
        $this->assertSame($superadmin->id, $message->fresh()->handled_by);

        $this->get(route('superadmin.contact_messages.index'))->assertInertia(fn (Assert $page) => $page->where('messages.data', [])->where('newCount', 0));
    }

    public function test_nobody_signs_up_without_a_lodge(): void
    {
        $this->get('/register')->assertRedirect(route('contact'));
        $this->post('/register', ['name' => 'Anyone', 'email' => 'anyone@example.org', 'password' => 'a-Strong-passphrase-42', 'password_confirmation' => 'a-Strong-passphrase-42'])
            ->assertRedirect(route('contact'));

        $this->assertDatabaseMissing('users', ['email' => 'anyone@example.org']);
    }

    public function test_a_lodges_join_link_still_opens_the_sign_up_form(): void
    {
        $club = Club::factory()->create();

        $this->get('/register?club='.$club->slug)->assertInertia(fn (Assert $page) => $page->component('Auth/Register')->where('club.slug', $club->slug));
    }

    public function test_the_directory_is_for_signed_in_members_but_calendars_stay_open(): void
    {
        $lodge = Lodge::factory()->create();

        $this->get(route('lodges.index'))->assertRedirect(route('login'));
        $this->get(route('lodges.show', $lodge->slug))->assertRedirect(route('login'));
        $this->get(route('lodges.ics', $lodge->slug))->assertOk();

        $this->actingAs(User::factory()->create())->get(route('lodges.index'))->assertOk();
    }
}
