<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\GrandLodge;
use App\Models\Province;
use App\Models\ProvincialOfficer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvincialOfficersTest extends TestCase
{
    use RefreshDatabase;

    private Club $section;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $province = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $group = Club::factory()->provinceGroup($province)->create(['name' => 'Durham']);
        $this->section = Club::factory()->provinceSection($group, ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']))->create();
        $this->editor = User::factory()->create();
        $this->section->users()->attach($this->editor->id, ['role' => 'coach', 'status' => 'active']);
    }

    public function test_editor_adds_and_orders_officers(): void
    {
        $slug = $this->section->slug;
        $this->actingAs($this->editor)->post(route('admin.provincial-officers.store', $slug), ['office' => ' Sovereign ', 'name' => 'John Smith', 'rank' => 'MWBro'])->assertRedirect();
        $this->post(route('admin.provincial-officers.store', $slug), ['office' => 'Secretary', 'name' => 'Ann Jones'])->assertRedirect();

        [$first, $second] = ProvincialOfficer::where('club_id', $this->section->id)->orderBy('position')->get()->all();
        $this->assertSame('Sovereign', $first->office);
        $this->assertSame(2, $second->position);

        $this->put(route('admin.provincial-officers.reorder', $slug), ['ids' => [$second->id, $first->id]])->assertRedirect();

        $this->post('/logout');
        $this->get(route('public.site.officers', $slug))->assertOk()
            ->assertInertia(fn ($page) => $page->has('officers', 2)
                ->where('officers.0.office', 'Secretary')->where('officers.0.name', 'Ann Jones')
                ->where('officers.1.rank', 'MWBro'));
    }

    public function test_reorder_refuses_another_sections_officer(): void
    {
        $mine = ProvincialOfficer::factory()->create(['club_id' => $this->section->id]);
        $foreign = ProvincialOfficer::factory()->create();

        $this->actingAs($this->editor)->putJson(route('admin.provincial-officers.reorder', $this->section->slug), ['ids' => [$mine->id, $foreign->id]])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_reorder_needs_every_officer(): void
    {
        $a = ProvincialOfficer::factory()->create(['club_id' => $this->section->id, 'position' => 1]);
        $b = ProvincialOfficer::factory()->create(['club_id' => $this->section->id, 'position' => 2]);

        $this->actingAs($this->editor)->putJson(route('admin.provincial-officers.reorder', $this->section->slug), ['ids' => [$b->id]])
            ->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->assertSame([1, 2], [$a->fresh()->position, $b->fresh()->position]);
    }

    public function test_officer_of_another_section_cannot_be_edited(): void
    {
        $foreign = ProvincialOfficer::factory()->create();

        $this->actingAs($this->editor)->put(route('admin.provincial-officers.update', [$this->section->slug, $foreign->id]), ['office' => 'X', 'name' => 'Y'])->assertNotFound();
        $this->delete(route('admin.provincial-officers.destroy', [$this->section->slug, $foreign->id]))->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_lodge_has_no_provincial_officers_section(): void
    {
        $lodge = Club::factory()->create();
        $owner = User::factory()->create();
        $lodge->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);

        $this->actingAs($owner)->get(route('admin.provincial-officers.index', $lodge->slug))->assertNotFound();
    }

    public function test_officers_page_falls_through_when_there_are_none(): void
    {
        $this->get(route('public.site.officers', $this->section->slug))->assertNotFound();
    }
}
