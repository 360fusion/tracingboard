<?php

namespace Tests\Feature\Halls;

use App\Enums\Visibility;
use App\Models\Club;
use App\Models\Event;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Events\EventRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HallOrganiserSearchTest extends TestCase
{
    use RefreshDatabase;

    private Club $hallClub;

    private Club $lodge;

    private User $organiser;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hallClub = Club::factory()->hall()->create();
        $hall = MasonicHall::factory()->create(['club_id' => $this->hallClub->id]);
        $this->lodge = Club::factory()->create(['masonic_hall_id' => $hall->id]);
        $this->organiser = User::factory()->create();
        $this->hallClub->users()->attach($this->organiser->id, ['role' => 'admin', 'status' => 'active']);
        $this->event = Event::create([
            'club_id' => $this->hallClub->id, 'title' => 'Open evening', 'slug' => 'open-evening', 'status' => 'upcoming',
            'starts_at' => now()->addDays(3), 'visibility' => Visibility::Club, 'rsvp_audience' => Visibility::Club, 'requires_payment' => false,
        ]);
    }

    private function search(string $q): array
    {
        return $this->actingAs($this->organiser)
            ->getJson("/{$this->hallClub->slug}/admin/events/{$this->event->id}/member-search?q={$q}")
            ->assertOk()->json('members');
    }

    private function book(User $user): void
    {
        app(EventRegistrationService::class)->register($this->event, $user, ['attendees' => [['name' => $user->name, 'attending_dining' => false]]]);
    }

    public function test_hall_search_finds_someone_who_booked_a_hall_event(): void
    {
        $user = User::factory()->create(['name' => 'Zed Booker']);
        $this->lodge->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);
        $this->book($user);

        $found = $this->search('Zed');

        $this->assertCount(1, $found);
        $this->assertSame($user->id, $found[0]['id']);
        $this->assertNull($found[0]['rank']);
        $this->assertNull($found[0]['lodge']);
        $this->assertNull($found[0]['member_number']);
    }

    public function test_hall_search_does_not_find_a_lodge_member_who_never_booked(): void
    {
        $user = User::factory()->create(['name' => 'Zed Lurker']);
        $this->lodge->users()->attach($user->id, ['role' => 'member', 'status' => 'active']);

        $this->assertSame([], $this->search('Zed'));
        $this->assertSame([], $this->search('Organiser'));
    }

    public function test_hall_organiser_cannot_book_an_unknown_user_id(): void
    {
        $stranger = User::factory()->create();
        $this->lodge->users()->attach($stranger->id, ['role' => 'member', 'status' => 'active']);
        $data = ['contact_name' => 'X', 'attendees' => [['name' => 'X', 'attending_dining' => false]]];

        try {
            app(EventRegistrationService::class)->saveByOrganiser($this->event, null, $stranger, $data, $this->organiser);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('member_id', $e->errors());
        }

        $visitor = app(EventRegistrationService::class)->saveByOrganiser($this->event, null, null, ['contact_name' => 'Vic', 'contact_email' => 'vic@example.test', 'attendees' => [['name' => 'Vic', 'attending_dining' => false]]], $this->organiser);
        $this->assertSame('Vic', $visitor->contact_name);
    }
}
