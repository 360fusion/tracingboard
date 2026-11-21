<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\Lodge;
use App\Models\LodgeSchedule;
use App\Models\MasonicHall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HallLodgesTest extends TestCase
{
    use RefreshDatabase;

    private Club $hallClub;

    private MasonicHall $hall;

    private User $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hallClub = Club::factory()->hall()->create();
        $this->hall = MasonicHall::factory()->create(['club_id' => $this->hallClub->id]);
        $this->board = User::factory()->create();
        $this->hallClub->users()->attach($this->board->id, ['role' => 'admin', 'status' => 'active']);
    }

    private function directoryLodge(string $name, ?MasonicHall $hall = null): Lodge
    {
        $lodge = Lodge::factory()->create(['name' => $name, 'masonic_hall_id' => ($hall ?? $this->hall)->id]);
        LodgeSchedule::factory()->create(['lodge_id' => $lodge->id, 'masonic_hall_id' => $lodge->masonic_hall_id]);

        return $lodge;
    }

    private function openList(): TestResponse
    {
        return $this->actingAs($this->board)->get("/{$this->hallClub->slug}/admin/lodges");
    }

    public function test_lists_directory_lodges_and_platform_clubs_at_the_hall_with_meeting_pattern(): void
    {
        $this->directoryLodge('Alpha Lodge');
        $this->directoryLodge('Elsewhere Lodge', MasonicHall::factory()->create());
        $club = Club::factory()->create(['name' => 'Beta Lodge', 'masonic_hall_id' => $this->hall->id]);
        $claimed = Lodge::factory()->create(['name' => 'Beta Lodge', 'club_id' => $club->id, 'masonic_hall_id' => $this->hall->id]);
        LodgeSchedule::factory()->create(['lodge_id' => $claimed->id, 'masonic_hall_id' => $this->hall->id]);

        $this->openList()->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Hall/Lodges')
            ->has('lodges', 2)
            ->where('lodges.0.name', 'Alpha Lodge')
            ->where('lodges.0.schedules.0.day_of_week', 'Tuesday')
            ->where('lodges.1.name', 'Beta Lodge')
            ->has('lodges.1.schedules', 1));
        $this->assertEquals([$club->id], $this->hall->memberClubIds()->all());
    }

    public function test_the_hall_club_is_not_in_its_own_list(): void
    {
        $this->hallClub->update(['masonic_hall_id' => $this->hall->id]);

        $this->openList()->assertInertia(fn (Assert $page) => $page->has('lodges', 0));
        $this->assertSame([], $this->hall->memberClubIds()->all());
    }

    public function test_list_has_no_links_into_lodge_admin(): void
    {
        Club::factory()->create(['masonic_hall_id' => $this->hall->id]);

        $this->assertStringNotContainsString('/admin', json_encode($this->openList()->viewData('page')['props']['lodges']));
    }

    public function test_query_count_does_not_grow_with_lodges(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->openList()->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        foreach (['A', 'B'] as $n) {
            $this->directoryLodge("{$n} Lodge");
        }
        $before = $count();
        foreach (['C', 'D', 'E', 'F'] as $n) {
            $this->directoryLodge("{$n} Lodge");
            Club::factory()->create(['masonic_hall_id' => $this->hall->id]);
        }

        $this->assertSame($before, $count());
    }

    public function test_every_board_role_can_open_the_lodges_list(): void
    {
        foreach (['owner', 'admin', 'coach', 'treasurer'] as $role) {
            $user = User::factory()->create();
            $this->hallClub->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

            $this->actingAs($user)->get("/{$this->hallClub->slug}/admin/lodges")->assertOk();
        }
    }

    public function test_the_lodges_list_is_still_404_for_a_lodge(): void
    {
        $lodge = Club::factory()->create();
        $treasurer = User::factory()->create();
        $lodge->users()->attach($treasurer->id, ['role' => 'treasurer', 'status' => 'active']);

        $this->actingAs($treasurer)->get("/{$lodge->slug}/admin/lodges")->assertNotFound();
    }
}
