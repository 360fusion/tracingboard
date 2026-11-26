<?php

namespace Tests\Feature\Halls;

use App\Mail\MemberInvitationMail;
use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Halls\HallBoard;
use App\Services\Halls\HallClubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SuperAdminHallBoardTest extends TestCase
{
    use RefreshDatabase;

    private function postBoard(User $by, MasonicHall $hall, array $data = ['name' => 'Olive Owner', 'email' => 'olive@example.com'])
    {
        return $this->actingAs($by)->post(route('superadmin.masonic_halls.board', $hall->id), $data);
    }

    public function test_superadmin_creates_board_and_invites_owner(): void
    {
        Mail::fake();
        $hall = MasonicHall::factory()->create();

        $this->postBoard(User::factory()->create(['is_super_admin' => true]), $hall)->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect();

        $club = Club::findOrFail($hall->fresh()->club_id);
        Mail::assertQueued(MemberInvitationMail::class, fn ($m) => $m->hasTo('olive@example.com'));
        $user = User::where('email', 'olive@example.com')->firstOrFail();
        $pivot = $club->users()->where('users.id', $user->id)->firstOrFail()->pivot;
        $this->assertSame('owner', $pivot->role);
        $this->assertNull($pivot->committee_role);
    }

    public function test_hall_invitation_email_is_worded_for_a_hall_board(): void
    {
        Mail::fake();
        $migration = require database_path('migrations/2026_11_26_111552_improve_account_invitation_email.php');
        DB::table('default_email_templates')->where('template_key', 'account_invitation')->update(['body_html' => $migration::NEW_BODY]);
        $hall = MasonicHall::factory()->create();

        $this->postBoard(User::factory()->create(['is_super_admin' => true, 'name' => 'Sam Super']), $hall);

        Mail::assertQueued(MemberInvitationMail::class, function (MemberInvitationMail $mail) {
            $html = $mail->render();

            return str_contains($html, 'Sam Super has invited you')
                && str_contains($html, 'as an owner')
                && str_contains($html, 'Invite the rest of the board')
                && str_contains($html, 'olive@example.com')
                && ! str_contains($html, 'summonses');
        });
    }

    public function test_second_call_invites_into_the_same_club_and_creates_no_new_one(): void
    {
        Mail::fake();
        $hall = MasonicHall::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->postBoard($admin, $hall);
        $clubs = Club::count();
        $this->postBoard($admin, $hall, ['name' => 'Second Person', 'email' => 'second@example.com'])->assertSessionMissing('error');

        $this->assertSame($clubs, Club::count());
        $this->assertTrue(Club::find($hall->fresh()->club_id)->users()->where('email', 'second@example.com')->exists());
    }

    public function test_already_on_the_board_is_a_flash_error(): void
    {
        Mail::fake();
        $hall = MasonicHall::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->postBoard($admin, $hall);
        $this->postBoard($admin, $hall)->assertSessionHas('error');
    }

    public function test_request_is_in_the_superadmin_audit_log(): void
    {
        Mail::fake();
        $this->postBoard(User::factory()->create(['is_super_admin' => true]), MasonicHall::factory()->create());

        $this->assertTrue(DB::table('superadmin_actions')->where('path', 'like', '%masonic-halls/%/board')->exists());
    }

    public function test_non_superadmin_is_refused_and_input_is_validated(): void
    {
        $hall = MasonicHall::factory()->create();
        $this->postBoard(User::factory()->create(), $hall)->assertRedirect();
        $this->assertNull($hall->fresh()->club_id);
        $this->postBoard(User::factory()->create(['is_super_admin' => true]), $hall, ['name' => '', 'email' => 'nope'])->assertSessionHasErrors(['name', 'email']);
        $this->assertNull($hall->fresh()->club_id);
    }

    public function test_failed_invite_leaves_no_ownerless_club_behind(): void
    {
        $this->mock(HallBoard::class)->shouldReceive('invite')->andThrow(new \DomainException('Nope.'));
        $hall = MasonicHall::factory()->create();
        $clubs = Club::count();

        $this->postBoard(User::factory()->create(['is_super_admin' => true]), $hall)->assertSessionHas('error');

        $this->assertNull($hall->fresh()->club_id);
        $this->assertSame($clubs, Club::count());
    }

    public function test_index_rows_carry_club_slug_without_the_club_relation(): void
    {
        $hall = MasonicHall::factory()->create();
        app(HallClubs::class)->createFor($hall);

        $this->actingAs(User::factory()->create(['is_super_admin' => true]))->get(route('superadmin.masonic_halls.index'))
            ->assertInertia(fn ($page) => $page->has('halls.0.club_slug')->missing('halls.0.club'));
    }

    public function test_the_halls_list_links_each_board_to_its_hall_admin(): void
    {
        $hall = MasonicHall::factory()->create();
        $club = app(HallClubs::class)->createFor($hall);
        MasonicHall::factory()->create();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]))->get(route('superadmin.masonic_halls.index'))
            ->assertInertia(fn ($page) => $page->where('halls', fn ($halls) => collect($halls)->pluck('board_url')->filter()->values()->all() === [route('admin.hall.edit', ['clubSlug' => $club->slug], false)]));
    }

    public function test_a_hall_with_a_board_cannot_be_deleted(): void
    {
        $hall = MasonicHall::factory()->create();
        app(HallClubs::class)->createFor($hall);
        $bare = MasonicHall::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($admin)->delete(route('superadmin.masonic_halls.destroy', $hall->id))->assertSessionHas('error');
        $this->assertModelExists($hall);
        $this->delete(route('superadmin.masonic_halls.destroy', $bare->id))->assertSessionHas('success');
        $this->assertModelMissing($bare);
    }
}
