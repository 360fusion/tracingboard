<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HallSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function ownerOf(Club $club): User
    {
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => 'owner', 'status' => 'active']);

        return $user;
    }

    public function test_a_hall_settings_screen_is_flagged_and_lists_only_capabilities_a_hall_uses(): void
    {
        $hall = Club::factory()->hall()->create();

        $this->actingAs($this->ownerOf($hall))->get(route('admin.settings.show', ['clubSlug' => $hall->slug]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('isHall', true)
            ->missing('settings.permission_matrix.manage_meetings')
            ->missing('settings.permission_matrix.manage_members')
            ->missing('settings.permission_matrix.manage_subscriptions')
            ->missing('settings.permission_matrix.pass_on_communications')
            ->has('settings.permission_matrix.manage_events')
            ->has('settings.permission_matrix.manage_hall'));
    }

    public function test_a_lodge_settings_screen_is_unchanged(): void
    {
        $lodge = Club::factory()->create();

        $this->actingAs($this->ownerOf($lodge))->get(route('admin.settings.show', ['clubSlug' => $lodge->slug]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('isHall', false)
            ->has('settings.permission_matrix.manage_meetings')
            ->has('settings.permission_matrix.manage_members'));
    }

    public function test_saving_a_hall_settings_form_keeps_the_hidden_fields_and_does_not_require_them(): void
    {
        $hall = Club::factory()->hall()->create(['settings' => [
            'meeting_formula' => '2nd Tue',
            'officers_roster' => [['role' => 'WM', 'name' => 'A']],
            'permission_matrix' => ['manage_meetings' => ['roles' => ['admin']]],
        ]]);
        $owner = $this->ownerOf($hall);

        $this->actingAs($owner)->put(route('admin.settings.update', ['clubSlug' => $hall->slug]), [
            'name' => 'The Hall',
            'tagline' => 'Where lodges meet',
            'contact_email' => 'hall@example.test',
            'permission_matrix' => ['manage_events' => ['roles' => ['owner', 'admin', 'treasurer']]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $settings = $hall->fresh()->settings;
        $this->assertSame('Where lodges meet', $settings['tagline']);
        $this->assertSame('2nd Tue', $settings['meeting_formula']);
        $this->assertSame([['role' => 'WM', 'name' => 'A']], $settings['officers_roster']);
        $this->assertSame(['roles' => ['admin']], $settings['permission_matrix']['manage_meetings']);
        $this->assertSame(['roles' => ['owner', 'admin', 'treasurer']], $settings['permission_matrix']['manage_events']);
        $this->assertSame('The Hall', $hall->fresh()->name);
    }

    public function test_a_lodge_screen_leaves_out_hall_capabilities_and_a_save_keeps_their_stored_values(): void
    {
        $lodge = Club::factory()->create(['settings' => ['permission_matrix' => ['manage_hall' => ['roles' => ['admin']]]]]);
        $owner = $this->ownerOf($lodge);

        $this->actingAs($owner)->get(route('admin.settings.show', ['clubSlug' => $lodge->slug]))
            ->assertInertia(fn (Assert $page) => $page->missing('settings.permission_matrix.manage_hall')->missing('settings.permission_matrix.manage_board'));

        $this->put(route('admin.settings.update', ['clubSlug' => $lodge->slug]), [
            'permission_matrix' => ['manage_events' => ['roles' => ['owner', 'admin']]],
        ])->assertSessionHasNoErrors();

        $matrix = $lodge->fresh()->settings['permission_matrix'];
        $this->assertSame(['roles' => ['admin']], $matrix['manage_hall']);
        $this->assertSame(['roles' => ['owner', 'admin']], $matrix['manage_events']);
    }
}
