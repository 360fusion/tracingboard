<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\Event;
use App\Models\GrandLodge;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProvinceSectionsTest extends TestCase
{
    use RefreshDatabase;

    private Club $group;

    private ClubType $roseCroix;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $durham = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $this->group = Club::factory()->provinceGroup($durham)->create(['name' => 'Durham']);
        $this->roseCroix = ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']);
        $this->admin = User::factory()->create();
        $this->group->users()->attach($this->admin->id, ['role' => 'admin', 'status' => 'active']);
    }

    private function addSection(): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.sections.store', ['clubSlug' => $this->group->slug]), [
            'order_club_type_id' => $this->roseCroix->id,
            'name' => 'Rose Croix in Durham',
        ]);
    }

    private function section(): Club
    {
        return Club::factory()->provinceSection($this->group, $this->roseCroix)->create(['name' => 'Rose Croix in Durham']);
    }

    public function test_group_admin_adds_rose_croix_section(): void
    {
        $this->addSection()->assertSessionHasNoErrors()->assertRedirect();

        $section = $this->group->sections()->firstOrFail();
        $this->assertSame('Rose Croix in Durham', $section->name);
        $this->assertSame($this->roseCroix->id, $section->order_club_type_id);
        $this->assertSame($this->group->province_id, $section->province_id);
        $this->assertSame(ClubType::provinceSection()->id, $section->club_type_id);
        $this->assertSame($this->group->slug.'-rose-croix', $section->slug);
    }

    public function test_order_picker_lists_orders_not_yet_used(): void
    {
        $mark = ClubType::factory()->create(['name' => 'Mark', 'code' => 'mark_lodge']);
        ClubType::hall();
        $this->section();

        $this->actingAs($this->admin)->get(route('admin.sections.index', ['clubSlug' => $this->group->slug]))
            ->assertInertia(function (AssertableInertia $page) use ($mark) {
                $codes = collect($page->toArray()['props']['orders'])->pluck('id');
                $this->assertContains($mark->id, $codes);
                $this->assertNotContains($this->roseCroix->id, $codes);
                $this->assertNotContains(ClubType::hall()->id, $codes);
                $this->assertNotContains(ClubType::provinceGroup()->id, $codes);
            });
    }

    public function test_duplicate_section_is_refused(): void
    {
        $this->addSection();
        $this->addSection()->assertSessionHasErrors('order_club_type_id');

        $this->assertSame(1, $this->group->sections()->count());
    }

    public function test_group_admin_appoints_section_admin(): void
    {
        $section = $this->section();

        $this->actingAs($this->admin)->post(route('admin.sections.admins.store', ['clubSlug' => $this->group->slug, 'sectionId' => $section->id]), ['name' => 'Sam Section', 'email' => 'sam@example.com'])
            ->assertSessionMissing('error')->assertRedirect();

        $pivot = $section->users()->where('email', 'sam@example.com')->firstOrFail()->pivot;
        $this->assertSame('admin', $pivot->role);
        $this->assertSame('pending', $pivot->status);
        $this->assertFalse($this->group->users()->where('email', 'sam@example.com')->exists());
    }

    public function test_section_id_from_another_group_is_not_found(): void
    {
        $other = Club::factory()->provinceGroup(Province::create(['grand_lodge_id' => $this->group->province->grand_lodge_id, 'name' => 'Kent', 'code' => 'kent', 'country' => 'England']))->create();
        $foreign = Club::factory()->provinceSection($other, $this->roseCroix)->create();

        $this->actingAs($this->admin)->delete(route('admin.sections.destroy', ['clubSlug' => $this->group->slug, 'sectionId' => $foreign->id]))->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.sections.admins.store', ['clubSlug' => $this->group->slug, 'sectionId' => $foreign->id]), ['name' => 'A B', 'email' => 'a@example.com'])->assertNotFound();
    }

    public function test_section_with_upcoming_event_cannot_be_removed(): void
    {
        $section = $this->section();
        Event::create(['club_id' => $section->id, 'title' => 'Dinner', 'slug' => 'dinner', 'starts_at' => now()->addWeek(), 'status' => 'upcoming']);

        $this->actingAs($this->admin)->delete(route('admin.sections.destroy', ['clubSlug' => $this->group->slug, 'sectionId' => $section->id]))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('active', $section->fresh()->status);
        $this->assertSame($this->group->id, $section->fresh()->parent_club_id);
    }

    public function test_section_is_removed_and_order_becomes_available_again(): void
    {
        $section = $this->section();

        $this->actingAs($this->admin)->delete(route('admin.sections.destroy', ['clubSlug' => $this->group->slug, 'sectionId' => $section->id]))
            ->assertSessionHas('success');

        $this->assertSame('inactive', $section->fresh()->status);
        $this->assertNull($section->fresh()->parent_club_id);
        $this->addSection()->assertSessionHasNoErrors();
    }

    public function test_sections_screen_is_not_found_on_a_lodge(): void
    {
        $lodge = Club::factory()->create();
        $lodge->users()->attach($this->admin->id, ['role' => 'admin', 'status' => 'active']);

        $this->actingAs($this->admin)->get(route('admin.sections.index', ['clubSlug' => $lodge->slug]))->assertNotFound();
    }
}
