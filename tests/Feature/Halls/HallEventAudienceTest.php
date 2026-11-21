<?php

namespace Tests\Feature\Halls;

use App\Enums\Visibility;
use App\Models\Club;
use App\Models\Event;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Events\EventRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_lodge_member_books_a_hall_event_as_themselves_through_the_public_page(): void
    {
        $url = "/site/{$this->hallClub->slug}/events/open-evening/member-register";
        $payload = ['attendance_status' => 'attending', 'attendees' => [['name' => 'Me', 'attending_dining' => false]]];

        $this->actingAs($this->member)->get("/site/{$this->hallClub->slug}/events/open-evening")
            ->assertInertia(fn (Assert $page) => $page->where('canBookAsMember', true));

        $this->actingAs($this->member)->post($url, $payload)->assertRedirect();
        $this->assertDatabaseHas('event_registrations', ['event_id' => $this->event->id, 'user_id' => $this->member->id]);

        $other = Club::factory()->create(['masonic_hall_id' => MasonicHall::factory()->create()->id]);
        $outsider = User::factory()->create();
        $other->users()->attach($outsider->id, ['role' => 'member', 'status' => 'active']);
        $this->actingAs($outsider)->post($url, $payload)->assertNotFound();
        $this->assertDatabaseMissing('event_registrations', ['user_id' => $outsider->id]);
    }
}
