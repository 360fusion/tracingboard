<?php

namespace Tests\Feature\Halls;

use App\Enums\Visibility;
use App\Models\Club;
use App\Models\ClubPaymentMethod;
use App\Models\Event;
use App\Models\EventPaymentMethod;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Events\EventRegistrationService;
use App\Services\Payment\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class HallEventAudienceTest extends TestCase
{
    use RefreshDatabase;

    private Club $hallClub;

    private MasonicHall $hall;

    private Club $lodge;

    private User $member;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hallClub = Club::factory()->hall()->create();
        $this->hall = MasonicHall::factory()->create(['club_id' => $this->hallClub->id]);
        $this->lodge = Club::factory()->create(['masonic_hall_id' => $this->hall->id]);
        $this->member = User::factory()->create();
        $this->lodge->users()->attach($this->member->id, ['role' => 'member', 'status' => 'active']);
        $this->event = Event::create([
            'club_id' => $this->hallClub->id,
            'title' => 'Hall open evening',
            'slug' => 'open-evening',
            'status' => 'upcoming',
            'starts_at' => now()->addDays(3),
            'visibility' => Visibility::Club,
            'rsvp_audience' => Visibility::Club,
            'requires_payment' => false,
        ]);
    }

    public function test_club_audience_hall_event_is_visible_to_members_of_lodges_at_the_hall(): void
    {
        $this->assertTrue($this->event->isVisibleTo($this->member));
        $this->assertTrue(Event::visibleTo($this->member)->whereKey($this->event->id)->exists());
    }

    public function test_not_visible_to_members_of_a_lodge_at_another_hall(): void
    {
        $other = Club::factory()->create(['masonic_hall_id' => MasonicHall::factory()->create()->id]);
        $outsider = User::factory()->create();
        $other->users()->attach($outsider->id, ['role' => 'member', 'status' => 'active']);

        $this->assertFalse($this->event->isVisibleTo($outsider));
        $this->assertFalse(Event::visibleTo($outsider)->whereKey($this->event->id)->exists());
    }

    public function test_lodge_moving_hall_moves_the_audience(): void
    {
        $this->lodge->update(['masonic_hall_id' => MasonicHall::factory()->create()->id]);
        $member = User::find($this->member->id);

        $this->assertFalse($this->event->isVisibleTo($member));
        $this->assertFalse(Event::visibleTo($member)->whereKey($this->event->id)->exists());
    }

    public function test_hall_event_shows_in_member_calendar_and_home(): void
    {
        $this->actingAs($this->member)->get('/members/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('upNext', fn ($items) => collect($items)->pluck('title')->contains('Hall open evening')));

        $this->actingAs($this->member)->get('/members/calendar?month='.now()->addDays(3)->format('Y-m'))
            ->assertInertia(fn (Assert $page) => $page->where('items', fn ($items) => collect($items)->pluck('title')->contains('Hall open evening')));
    }

    public function test_member_can_book_a_club_audience_hall_event(): void
    {
        $registration = app(EventRegistrationService::class)->register($this->event, $this->member, ['attendees' => [['name' => 'Me', 'attending_dining' => false]]]);

        $this->assertSame($this->event->id, $registration->event_id);
    }

    public function test_event_form_labels_club_audience_for_a_hall(): void
    {
        $board = User::factory()->create();
        $this->hallClub->users()->attach($board->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($board)->get("/{$this->hallClub->slug}/admin/events/create")
            ->assertInertia(fn (Assert $page) => $page->where('visibilityOptions.0.label', 'Members of lodges that meet here'));

        $lodgeAdmin = User::factory()->create();
        $this->lodge->users()->attach($lodgeAdmin->id, ['role' => 'admin', 'status' => 'active']);
        $this->actingAs($board)->get("/{$this->hallClub->slug}/admin/posts/create")
            ->assertInertia(fn (Assert $page) => $page->where('visibilityOptions.0.label', 'Members of lodges that meet here'));

        $this->actingAs($lodgeAdmin)->get("/{$this->lodge->slug}/admin/events/create")
            ->assertInertia(fn (Assert $page) => $page->where('visibilityOptions.0.label', 'Club members only'));
    }

    public function test_member_events_page_and_ics_feed_include_the_hall_event(): void
    {
        $this->actingAs($this->member)->get('/members/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($items) => collect($items)->pluck('title')->contains('Hall open evening')));

        $this->actingAs($this->member)->get('/members/calendar')->assertOk();
        $url = route('members.calendar.feed', ['token' => $this->member->fresh()->calendar_token], false);
        auth()->logout();
        $this->get($url)
            ->assertOk()->assertSee('Hall open evening', false);
    }

    private function priceHallEventWithCard(): EventPaymentMethod
    {
        config(['events.online_payments' => true]);
        $this->event->update(['requires_payment' => true, 'price' => 40]);
        $card = ClubPaymentMethod::create(['club_id' => $this->hallClub->id, 'type' => 'card_online', 'label' => 'Pay online', 'sort_order' => 0, 'config' => ['stripe_secret_key' => 'sk_test_x', 'stripe_webhook_secret' => 'whsec_x']]);

        return EventPaymentMethod::create(['event_id' => $this->event->id, 'payment_method_id' => $card->id, 'is_enabled' => true]);
    }

    private function hallEventsUrl(): string
    {
        return "/members/{$this->hallClub->slug}/events";
    }

    public function test_lodge_member_sees_and_books_the_hall_event_in_the_member_area(): void
    {
        $this->actingAs($this->member)->get($this->hallEventsUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('events', fn ($events) => collect($events)->pluck('title')->contains('Hall open evening')));

        $this->actingAs($this->member)->post("{$this->hallEventsUrl()}/{$this->event->id}/rsvp", [
            'attendance_status' => 'attending', 'attendees' => [['name' => 'Me', 'attending_dining' => false]],
        ])->assertRedirect();

        $this->assertDatabaseHas('event_registrations', ['event_id' => $this->event->id, 'user_id' => $this->member->id]);

        $this->actingAs($this->member)->get("{$this->hallEventsUrl()}/{$this->event->id}/ticket")->assertOk();
    }

    public function test_hall_events_page_marks_the_club_as_a_hall_so_the_lodge_tabs_are_left_out(): void
    {
        $this->actingAs($this->member)->get($this->hallEventsUrl())
            ->assertInertia(fn (Assert $page) => $page->where('club.is_hall', true));

        $this->actingAs($this->member)->get("/members/{$this->lodge->slug}/events")
            ->assertInertia(fn (Assert $page) => $page->where('club.is_hall', false));
    }

    public function test_member_gets_the_price_quote_for_a_hall_event(): void
    {
        $this->priceHallEventWithCard();

        $this->actingAs($this->member)
            ->postJson("{$this->hallEventsUrl()}/{$this->event->id}/quote", ['attendees' => [['is_guest' => false], ['is_guest' => true]]])
            ->assertOk()
            ->assertJsonPath('options.0.total', 80);
    }

    public function test_online_payment_for_a_hall_booking_returns_to_the_member_events_page(): void
    {
        $option = $this->priceHallEventWithCard();
        $captured = null;
        $this->mock(StripeGateway::class, function (MockInterface $mock) use (&$captured) {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturnUsing(function ($method, $params) use (&$captured) {
                $captured = $params;

                return ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.test/pay/cs_test_1'];
            });
        });

        $this->actingAs($this->member)->post("{$this->hallEventsUrl()}/{$this->event->id}/rsvp", [
            'attendance_status' => 'attending', 'payment_method' => $option->id, 'attendees' => [['name' => 'Me']],
        ], ['X-Inertia' => 'true'])->assertStatus(409);

        $this->assertStringEndsWith($this->hallEventsUrl().'?payment=success', $captured['success_url']);
        $this->assertStringEndsWith($this->hallEventsUrl().'?payment=cancelled', $captured['cancel_url']);
    }

    public function test_only_a_lodge_member_at_the_hall_reaches_the_hall_events_page(): void
    {
        $other = Club::factory()->create(['masonic_hall_id' => MasonicHall::factory()->create()->id]);
        $outsider = User::factory()->create();
        $other->users()->attach($outsider->id, ['role' => 'member', 'status' => 'active']);
        $this->actingAs($outsider)->get($this->hallEventsUrl())->assertNotFound();

        $board = User::factory()->create();
        $this->hallClub->users()->attach($board->id, ['role' => 'admin', 'status' => 'active']);
        $this->actingAs($board)->get($this->hallEventsUrl())->assertNotFound();
        $this->actingAs($board)->get("/members/{$this->hallClub->slug}")->assertNotFound();
    }

    public function test_the_rest_of_the_hall_member_area_stays_closed_to_lodge_members(): void
    {
        $this->actingAs($this->member)->get("/members/{$this->hallClub->slug}/news")->assertNotFound();
    }
}
