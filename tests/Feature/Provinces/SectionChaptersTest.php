<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\GrandLodge;
use App\Models\Lodge;
use App\Models\Page;
use App\Models\Province;
use App\Models\User;
use App\Services\Provinces\SectionChapters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SectionChaptersTest extends TestCase
{
    use RefreshDatabase;

    private Province $durham;

    private Province $northumberland;

    private Club $group;

    private Club $section;

    private ClubType $roseCroix;

    private ClubType $mark;

    protected function setUp(): void
    {
        parent::setUp();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $this->durham = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
        $this->northumberland = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Northumberland', 'code' => 'northumberland', 'country' => 'England']);
        $this->roseCroix = ClubType::factory()->create(['name' => 'Rose Croix', 'code' => 'rose_croix']);
        $this->mark = ClubType::factory()->create(['name' => 'Mark', 'code' => 'mark_lodge']);
        $this->group = Club::factory()->provinceGroup($this->durham)->create(['name' => 'Durham']);
        $this->section = Club::factory()->provinceSection($this->group, $this->roseCroix)->create(['name' => 'Durham Rose Croix']);
    }

    private function lodge(Province $province, ClubType $type, string $name, array $extra = []): Lodge
    {
        return Lodge::factory()->create(['province_id' => $province->id, 'club_type_id' => $type->id, 'name' => $name, ...$extra]);
    }

    public function test_chapters_are_the_orders_lodges_in_the_province(): void
    {
        $this->lodge($this->durham, $this->roseCroix, 'Stella Chapter');
        $this->lodge($this->northumberland, $this->roseCroix, 'Tyne Chapter');
        $this->lodge($this->durham, $this->mark, 'Wear Mark Lodge');

        $names = SectionChapters::for($this->section)->pluck('name')->all();

        $this->assertSame(['Stella Chapter'], $names);
    }

    public function test_managed_chapter_appears_once(): void
    {
        $club = Club::factory()->create(['name' => 'Aurora Chapter', 'club_type_id' => $this->roseCroix->id, 'province_id' => $this->durham->id]);
        $this->lodge($this->durham, $this->roseCroix, 'Aurora Chapter', ['club_id' => $club->id]);

        $rows = SectionChapters::for($this->section);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['on_platform']);
        $this->assertStringContainsString('/site/'.$club->slug, $rows[0]['site_url']);
    }

    public function test_chapters_list_makes_two_queries_whatever_the_count(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            SectionChapters::for($this->section->fresh());

            return count(DB::getQueryLog());
        };

        foreach (['A', 'B'] as $n) {
            $this->lodge($this->durham, $this->roseCroix, "$n Chapter");
            Club::factory()->create(['name' => "$n Club", 'club_type_id' => $this->roseCroix->id, 'province_id' => $this->durham->id]);
        }
        $few = $count();

        foreach (['C', 'D', 'E', 'F'] as $n) {
            $this->lodge($this->durham, $this->roseCroix, "$n Chapter");
            Club::factory()->create(['name' => "$n Club", 'club_type_id' => $this->roseCroix->id, 'province_id' => $this->durham->id]);
        }

        $this->assertSame($few, $count());
    }

    public function test_public_chapters_page_renders_for_a_visitor(): void
    {
        $this->lodge($this->durham, $this->roseCroix, 'Stella Chapter');

        $this->get(route('public.site.chapters', ['clubSlug' => $this->section->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('chapters', 1)->where('chapters.0.name', 'Stella Chapter'));
    }

    public function test_admin_chapters_page_is_for_the_section_only(): void
    {
        $admin = User::factory()->create();
        $this->section->users()->attach($admin->id, ['role' => 'admin', 'status' => 'active']);
        $this->lodge($this->durham, $this->roseCroix, 'Stella Chapter');

        $this->actingAs($admin)->get(route('admin.chapters.index', ['clubSlug' => $this->section->slug]))
            ->assertOk()->assertInertia(fn ($page) => $page->has('chapters', 1));
    }

    public function test_group_site_order_address_redirects_to_section(): void
    {
        $this->get('/site/'.$this->group->slug.'/rose-croix')
            ->assertRedirect(url('/site/'.$this->section->slug));

        $this->get('/site/'.$this->group->slug.'/royal-arch')->assertNotFound();
    }

    public function test_group_home_lists_its_sections(): void
    {
        $this->get('/site/'.$this->group->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sections.0.name', 'Durham Rose Croix')->where('sections.0.order', 'Rose Croix'));
    }

    public function test_real_pages_slugged_chapters_are_not_hidden(): void
    {
        $lodge = Club::factory()->create();
        $lodge->ensureDefaultPages();
        $make = fn (Club $club) => Page::create(['club_id' => $club->id, 'title' => 'Our chapters', 'slug' => 'chapters', 'is_published' => true, 'blocks' => []]);
        $make($lodge);
        $make($this->section);

        foreach ([$lodge, $this->section] as $club) {
            $this->get(route('public.site.chapters', ['clubSlug' => $club->slug]))
                ->assertOk()->assertInertia(fn ($page) => $page->where('page.title', 'Our chapters')->missing('chapters'));
        }
    }

    public function test_group_page_named_like_an_order_wins_over_the_redirect(): void
    {
        $this->group->ensureDefaultPages();
        Page::create(['club_id' => $this->group->id, 'title' => 'Rose Croix news', 'slug' => 'rose-croix', 'is_published' => true, 'blocks' => []]);

        $this->get('/site/'.$this->group->slug.'/rose-croix')->assertOk()->assertInertia(fn ($page) => $page->where('page.title', 'Rose Croix news'));
    }

    public function test_club_with_an_unlisted_directory_row_still_appears(): void
    {
        $club = Club::factory()->create(['name' => 'Quiet Chapter', 'club_type_id' => $this->roseCroix->id, 'province_id' => $this->durham->id]);
        $this->lodge($this->durham, $this->roseCroix, 'Quiet Chapter', ['club_id' => $club->id, 'status' => 'inactive']);

        $this->assertSame(['Quiet Chapter'], SectionChapters::for($this->section)->pluck('name')->all());
    }
}
