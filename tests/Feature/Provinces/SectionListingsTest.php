<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\GrandLodge;
use App\Models\Lodge;
use App\Models\MasonicHall;
use App\Models\Province;
use App\Models\User;
use App\Services\Lodges\LodgeCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionListingsTest extends TestCase
{
    use RefreshDatabase;

    private Province $durham;

    private Club $section;

    private ClubType $roseCroix;

    private ClubType $mark;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $this->durham = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $this->roseCroix = ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']);
        $this->mark = ClubType::factory()->create(['name' => 'Mark', 'code' => 'mark_lodge']);
        $group = Club::factory()->provinceGroup($this->durham)->create(['name' => 'Durham']);
        $this->section = Club::factory()->provinceSection($group, $this->roseCroix)->create(['name' => 'Durham Rose Croix']);
        $this->editor = User::factory()->create();
        $this->section->users()->attach($this->editor->id, ['role' => 'coach', 'status' => 'active']);
    }

    private function lodge(ClubType $type, array $extra = []): Lodge
    {
        return Lodge::factory()->create(['province_id' => $this->durham->id, 'club_type_id' => $type->id, ...$extra]);
    }

    private function update(Lodge $lodge, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->editor)->put(route('admin.chapters.listings.update', [$this->section->slug, $lodge->id]), [
            'club_type_id' => $lodge->club_type_id,
            'province_id' => $this->durham->id,
            'name' => 'Renamed Chapter',
        ]);
    }

    public function test_editor_edits_a_durham_rose_croix_listing(): void
    {
        $lodge = $this->lodge($this->roseCroix);

        $this->update($lodge)->assertRedirect();

        $this->assertSame('Renamed Chapter', $lodge->fresh()->name);
        $this->actingAs($this->editor)->get(route('admin.chapters.listings.index', $this->section->slug))->assertOk();
    }

    public function test_editor_cannot_edit_a_mark_lodge_in_durham(): void
    {
        $lodge = $this->lodge($this->mark, ['name' => 'Wear Mark']);

        $this->update($lodge)->assertNotFound();

        $this->assertSame('Wear Mark', $lodge->fresh()->name);
    }

    public function test_managed_chapter_listing_is_refused(): void
    {
        $lodge = $this->lodge($this->roseCroix, ['club_id' => Club::factory()->create()->id]);

        $this->update($lodge)->assertForbidden();
    }

    public function test_editor_applies_a_suggested_fix_for_their_chapter(): void
    {
        $chapter = $this->lodge($this->roseCroix, ['website_url' => 'https://old.example.org']);
        $markLodge = $this->lodge($this->mark, ['website_url' => 'https://old.example.org']);
        $suggester = User::factory()->create();
        $fix = app(LodgeCorrectionService::class)->submit($chapter, $suggester, ['website_url' => 'https://new.example.org'], null);
        $other = app(LodgeCorrectionService::class)->submit($markLodge, $suggester, ['website_url' => 'https://new.example.org'], null);
        $body = ['fields' => ['website_url'], 'values' => ['website_url' => 'https://new.example.org']];

        $this->actingAs($this->editor)->get(route('admin.chapters.fixes.index', $this->section->slug))->assertOk();
        $this->actingAs($this->editor)->post(route('admin.chapters.fixes.apply', [$this->section->slug, $other->id]), $body)->assertNotFound();
        $this->actingAs($this->editor)->post(route('admin.chapters.fixes.apply', [$this->section->slug, $fix->id]), $body)->assertRedirect();

        $this->assertSame('https://new.example.org', $chapter->fresh()->website_url);
        $this->assertSame('https://old.example.org', $markLodge->fresh()->website_url);
    }

    public function test_lodge_member_without_section_role_gets_403(): void
    {
        $member = User::factory()->create();
        $this->section->users()->attach($member->id, ['role' => 'member', 'status' => 'active']);
        $lodge = $this->lodge($this->roseCroix);

        $this->update($lodge, $member)->assertForbidden();
        $this->assertNotSame('Renamed Chapter', $lodge->fresh()->name);
    }

    public function test_store_forces_the_sections_order(): void
    {
        $this->actingAs($this->editor)->post(route('admin.chapters.listings.store', $this->section->slug), [
            'club_type_id' => $this->mark->id,
            'province_id' => $this->durham->id,
            'name' => 'New Chapter',
        ])->assertRedirect();

        $this->assertSame($this->roseCroix->id, Lodge::where('name', 'New Chapter')->firstOrFail()->club_type_id);
    }

    public function test_listing_outside_the_province_is_refused(): void
    {
        $other = Province::create(['grand_lodge_id' => $this->durham->grand_lodge_id, 'name' => 'Northumberland', 'code' => 'northumberland', 'country' => 'England']);
        $hall = MasonicHall::factory()->create(['province_id' => $other->id]);
        $lodge = $this->lodge($this->roseCroix, ['name' => 'Stella']);
        $base = ['club_type_id' => $this->roseCroix->id, 'province_id' => $this->durham->id, 'name' => 'Changed'];
        $store = route('admin.chapters.listings.store', $this->section->slug);
        $update = route('admin.chapters.listings.update', [$this->section->slug, $lodge->id]);

        $this->actingAs($this->editor)->post($store, [...$base, 'province_id' => $other->id])->assertSessionHasErrors('province_id');
        $this->actingAs($this->editor)->post($store, [...$base, 'masonic_hall_id' => $hall->id])->assertSessionHasErrors('masonic_hall_id');
        $this->actingAs($this->editor)->put($update, [...$base, 'province_id' => $other->id])->assertSessionHasErrors('province_id');
        $this->actingAs($this->editor)->put($update, [...$base, 'masonic_hall_id' => $hall->id])->assertSessionHasErrors('masonic_hall_id');

        $this->assertSame('Stella', $lodge->fresh()->name);
        $this->assertSame(0, Lodge::where('name', 'Changed')->count());
    }

    public function test_non_section_club_gives_404(): void
    {
        $lodgeClub = Club::factory()->create();
        $lodgeClub->users()->attach($this->editor->id, ['role' => 'coach', 'status' => 'active']);

        $this->actingAs($this->editor)->get(route('admin.chapters.listings.index', $lodgeClub->slug))->assertNotFound();
    }
}
