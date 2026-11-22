<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\Event;
use App\Models\GrandLodge;
use App\Models\Lodge;
use App\Models\LodgeCorrection;
use App\Models\Province;
use App\Models\User;
use App\Services\DefaultSitePages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvinceDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Province $durham;

    private Club $group;

    private Club $section;

    private ClubType $roseCroix;

    protected function setUp(): void
    {
        parent::setUp();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $this->durham = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $this->roseCroix = ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']);
        $this->group = Club::factory()->provinceGroup($this->durham)->create(['name' => 'Durham']);
        $this->section = Club::factory()->provinceSection($this->group, $this->roseCroix)->create(['name' => 'Durham Rose Croix']);
    }

    public function test_group_and_section_sites_have_no_join_us_page(): void
    {
        foreach ([$this->group, $this->section] as $club) {
            app(DefaultSitePages::class)->ensure($club);

            $this->assertFalse($club->pages()->where('slug', 'join-us')->exists());
            $this->assertArrayNotHasKey('cta_text', $club->pages()->where('slug', 'home')->first()->blocks[0]);
        }
    }

    private function person(Club $club, string $role = 'owner'): User
    {
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    public function test_group_dashboard_lists_sections(): void
    {
        $this->actingAs($this->person($this->group))->get(route('admin.analytics', ['slug' => $this->group->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sections', 1)->where('sections.0.name', 'Durham Rose Croix'));
    }

    public function test_section_dashboard_counts(): void
    {
        $lodge = Lodge::factory()->create(['province_id' => $this->durham->id, 'club_type_id' => $this->roseCroix->id]);
        Lodge::factory()->create(['province_id' => $this->durham->id, 'club_type_id' => $this->roseCroix->id]);
        LodgeCorrection::factory()->create(['lodge_id' => $lodge->id, 'changes' => ['number' => '4800']]);
        Event::create(['club_id' => $this->section->id, 'title' => 'Festival', 'slug' => 'festival', 'starts_at' => now()->addDays(3), 'status' => 'upcoming']);
        Event::create(['club_id' => $this->section->id, 'title' => 'Called off', 'slug' => 'called-off', 'starts_at' => now()->addDays(4), 'status' => 'cancelled']);
        Event::create(['club_id' => $this->section->id, 'title' => 'Old', 'slug' => 'old', 'starts_at' => now()->subDays(3), 'status' => 'upcoming']);

        $this->actingAs($this->person($this->section))->get(route('admin.analytics', ['slug' => $this->section->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sectionStats.chapters_count', 2)->where('sectionStats.upcoming_events', 1)->where('sectionStats.open_fixes', 1));
    }

    public function test_section_person_lands_on_section_admin_after_invitation(): void
    {
        $user = User::factory()->unverified()->create(['password' => Hash::make('temp-secret')]);
        $this->section->users()->attach($user->id, ['role' => 'admin', 'invitation_token' => hash('sha256', 'tok-1'), 'invited_at' => now(), 'status' => 'active']);

        $this->post(route('invitation.submit', ['slug' => $this->section->slug, 'token' => 'tok-1']), ['password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])
            ->assertRedirect(route('admin.analytics', ['slug' => $this->section->slug]));
    }

    public function test_group_person_lands_on_sections_after_invitation(): void
    {
        $user = User::factory()->unverified()->create(['password' => Hash::make('temp-secret')]);
        $this->group->users()->attach($user->id, ['role' => 'owner', 'invitation_token' => hash('sha256', 'tok-2'), 'invited_at' => now(), 'status' => 'active']);

        $this->post(route('invitation.submit', ['slug' => $this->group->slug, 'token' => 'tok-2']), ['password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])
            ->assertRedirect(route('admin.sections.index', ['clubSlug' => $this->group->slug]));
    }

    public function test_people_only_on_a_group_or_section_start_in_its_admin(): void
    {
        $this->actingAs($this->person($this->section, 'admin'))->get(route('members.dashboard'))
            ->assertRedirect(route('admin.analytics', ['slug' => $this->section->slug]));

        $this->actingAs($this->person($this->group))->get(route('members.dashboard'))
            ->assertRedirect(route('admin.sections.index', ['clubSlug' => $this->group->slug]));
    }

    public function test_group_staff_without_manage_sections_land_on_the_dashboard(): void
    {
        $dashboard = route('admin.analytics', ['slug' => $this->group->slug]);
        $this->actingAs($this->person($this->group, 'treasurer'))->get(route('members.dashboard'))->assertRedirect($dashboard);
        $this->get($dashboard)->assertOk();
    }

    public function test_a_group_or_section_editor_lands_on_a_dashboard_they_can_open(): void
    {
        foreach ([$this->group, $this->section] as $i => $club) {
            $user = User::factory()->unverified()->create(['password' => Hash::make('temp-secret')]);
            $club->users()->attach($user->id, ['role' => 'coach', 'invitation_token' => hash('sha256', "tok-e{$i}"), 'invited_at' => now(), 'status' => 'active']);
            $dashboard = route('admin.analytics', ['slug' => $club->slug]);

            $this->post(route('invitation.submit', ['slug' => $club->slug, 'token' => "tok-e{$i}"]), ['password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])
                ->assertRedirect($dashboard);
            $this->actingAs($user->fresh())->get($dashboard)->assertOk();
            auth()->logout();
        }
    }

    public function test_a_sections_settings_screen_shows_the_editor_on_the_dashboard(): void
    {
        $this->actingAs($this->person($this->section))->get(route('admin.settings.show', ['clubSlug' => $this->section->slug]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('settings.permission_matrix.view_dashboard.roles', ['owner', 'admin', 'treasurer', 'coach']));
    }

    public function test_a_lodge_editor_still_cannot_open_the_dashboard_and_a_saved_matrix_wins(): void
    {
        $lodge = Club::factory()->create();
        $this->actingAs($this->person($lodge, 'coach'))->get(route('admin.analytics', ['slug' => $lodge->slug]))->assertForbidden();

        $this->section->update(['settings' => ['permission_matrix' => ['view_dashboard' => ['roles' => ['owner', 'admin']]]]]);
        $this->actingAs($this->person($this->section, 'coach'))->get(route('admin.analytics', ['slug' => $this->section->slug]))->assertForbidden();
    }

    public function test_section_home_page_links_chapters_and_officers(): void
    {
        $this->section->ensureDefaultPages();

        $home = $this->section->pages()->where('slug', 'home')->firstOrFail();
        $this->assertStringContainsString('/site/'.$this->section->slug.'/chapters', json_encode($home->blocks, JSON_UNESCAPED_SLASHES));
        $this->assertStringContainsString('/site/'.$this->section->slug.'/officers', json_encode($home->blocks, JSON_UNESCAPED_SLASHES));
    }
}
