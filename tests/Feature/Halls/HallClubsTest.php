<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Halls\HallClubs;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HallClubsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_makes_one_hall_club_owned_by_the_user(): void
    {
        $hall = MasonicHall::factory()->create(['name' => 'Freemasons Hall Leeds']);
        $owner = User::factory()->create();

        $club = app(HallClubs::class)->createFor($hall, $owner);

        $this->assertTrue($club->isHall());
        $this->assertSame($club->id, $hall->fresh()->club_id);
        $this->assertNull($club->masonic_hall_id);
        $this->assertSame('owner', $club->users()->first()->pivot->role);
        $this->assertSame($club->id, $hall->fresh()->club->id);
        $this->assertSame($hall->id, $club->hall->id);
    }

    public function test_create_for_refuses_a_hall_that_already_has_a_club(): void
    {
        $hall = MasonicHall::factory()->create();
        $owner = User::factory()->create();
        app(HallClubs::class)->createFor($hall, $owner);

        try {
            app(HallClubs::class)->createFor($hall->fresh(), $owner);
            $this->fail('Expected a DomainException.');
        } catch (DomainException $e) {
            $this->assertSame('This hall already has a board.', $e->getMessage());
        }

        $this->assertSame(1, Club::whereHas('clubType', fn ($q) => $q->where('code', ClubType::HALL))->count());
    }

    public function test_is_hall_works_without_the_type_loaded_and_not_halls_scope_excludes_halls(): void
    {
        $hall = Club::factory()->hall()->create();
        $lodge = Club::factory()->create();

        $this->assertTrue(Club::find($hall->id)->isHall());
        $this->assertFalse(Club::find($lodge->id)->isHall());
        $this->assertSame([$lodge->id], Club::notHalls()->pluck('id')->all());
    }

    public function test_hall_club_cannot_set_its_own_masonic_hall(): void
    {
        $club = Club::factory()->hall()->create();
        $other = MasonicHall::factory()->create();
        $admin = User::factory()->create();
        $club->users()->attach($admin->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($admin)
            ->put(route('admin.settings.update', ['clubSlug' => $club->slug]), ['masonic_hall_id' => $other->id])
            ->assertSessionHasNoErrors();

        $this->assertNull($club->refresh()->masonic_hall_id);
    }
}
