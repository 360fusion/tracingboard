<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\GrandLodge;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupAdminSectionsTest extends TestCase
{
    use RefreshDatabase;

    private Club $group;

    private Club $section;

    private Club $sibling;

    protected function setUp(): void
    {
        parent::setUp();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $durham = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $this->group = Club::factory()->provinceGroup($durham)->create(['name' => 'Durham']);
        $this->section = Club::factory()->provinceSection($this->group, ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']))->create();
        $this->sibling = Club::factory()->provinceSection($this->group, ClubType::factory()->create(['name' => 'Mark', 'code' => 'mark_lodge']))->create();
    }

    private function person(Club $club, string $role): User
    {
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => $role, 'status' => 'active']);

        return $user;
    }

    public function test_section_editor_cannot_manage_people(): void
    {
        $this->actingAs($this->person($this->section, 'coach'))
            ->post(route('admin.board.store', ['clubSlug' => $this->section->slug]), ['name' => 'A Person', 'email' => 'a@example.com', 'office' => 'Editor', 'role' => 'coach'])
            ->assertForbidden();

        $this->actingAs($this->person($this->section, 'admin'))
            ->post(route('admin.board.store', ['clubSlug' => $this->section->slug]), ['name' => 'A Person', 'email' => 'a@example.com', 'office' => 'Editor', 'role' => 'coach'])
            ->assertRedirect()->assertSessionHas('success');
    }

    public function test_section_role_does_not_reach_sibling_section_or_group(): void
    {
        $admin = $this->person($this->section, 'admin');

        $this->actingAs($admin)->get(route('admin.analytics', ['slug' => $this->sibling->slug]))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.analytics', ['slug' => $this->group->slug]))->assertForbidden();
    }

    public function test_section_editor_does_not_reach_a_chapter_admin(): void
    {
        $chapter = Club::factory()->create(['club_type_id' => $this->section->order_club_type_id, 'province_id' => $this->section->province_id]);

        $this->actingAs($this->person($this->section, 'coach'))->get(route('admin.analytics', ['slug' => $chapter->slug]))->assertForbidden();
    }

    public function test_province_admin_pages_send_only_the_clubs_name_and_slug(): void
    {
        $pages = [
            [$this->group, 'admin.sections.index'],
            [$this->section, 'admin.provincial-officers.index'],
            [$this->section, 'admin.chapters.index'],
            [$this->section, 'admin.circulars.index'],
        ];

        foreach ($pages as [$club, $name]) {
            $this->actingAs($this->person($club, 'owner'))->get(route($name, ['clubSlug' => $club->slug]))
                ->assertInertia(fn ($page) => $page->where('club', ['id' => $club->id, 'name' => $club->name, 'slug' => $club->slug])->etc());
        }
    }

    public function test_settings_cannot_move_a_section_or_group_to_another_province(): void
    {
        $other = Province::create(['grand_lodge_id' => GrandLodge::first()->id, 'name' => 'Northumberland', 'code' => 'northumberland', 'country' => 'England']);

        foreach ([$this->section, $this->group] as $club) {
            $province = $club->province_id;

            $this->actingAs($this->person($club, 'owner'))
                ->put(route('admin.settings.update', ['clubSlug' => $club->slug]), ['name' => $club->name, 'province_id' => $other->id])
                ->assertSessionHasNoErrors()->assertRedirect();

            $this->assertSame($province, $club->fresh()->province_id);
        }
    }

    public function test_group_has_no_events_or_accounts(): void
    {
        $owner = $this->person($this->group, 'owner');

        $this->actingAs($owner)->get("/{$this->group->slug}/admin/events")->assertNotFound();
        $this->actingAs($owner)->get("/{$this->group->slug}/admin/accounting")->assertNotFound();
        $this->actingAs($owner)->get("/{$this->group->slug}/admin/bank-accounts")->assertNotFound();
        $this->actingAs($owner)->get("/{$this->group->slug}/admin/notices")->assertNotFound();
        $this->actingAs($owner)->get("/{$this->section->slug}/admin/meetings")->assertNotFound();
    }

    public function test_board_screen_calls_coach_editor_on_a_section(): void
    {
        $this->actingAs($this->person($this->section, 'admin'))
            ->get(route('admin.board.index', ['clubSlug' => $this->section->slug]))
            ->assertInertia(fn ($page) => $page->where('roles.coach', 'Editor'));

        $hall = Club::factory()->hall()->create();
        $this->actingAs($this->person($hall, 'admin'))
            ->get(route('admin.board.index', ['clubSlug' => $hall->slug]))
            ->assertInertia(fn ($page) => $page->where('roles.coach', 'Secretary'));
    }
}
