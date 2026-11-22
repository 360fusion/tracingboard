<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\GrandLodge;
use App\Models\Province;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvinceClubTypesTest extends TestCase
{
    use RefreshDatabase;

    private function province(): Province
    {
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);

        return Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
    }

    public function test_short_name_drops_the_body_word_and_degree(): void
    {
        $this->assertSame('Rose Croix', (new ClubType(['code' => 'rose_croix', 'name' => 'Rose Croix Chapter (18°)']))->shortName());
        $this->assertSame('Mark Master Masons', (new ClubType(['code' => 'mark_lodge', 'name' => 'Mark Master Masons Lodge']))->shortName());
        $this->assertSame('Rose Croix', (new ClubType(['code' => 'rose_croix', 'name' => 'Rose Croix']))->shortName());
    }

    public function test_group_types_are_not_orders(): void
    {
        ClubType::hall();
        ClubType::provinceGroup();
        ClubType::provinceSection();
        ClubType::factory()->create(['code' => 'rose_croix']);
        ClubType::factory()->create(['code' => 'rowing']);

        $codes = ClubType::orders()->pluck('code')->all();

        $this->assertNotContains('rowing', $codes);

        $this->assertNotContains('hall', $codes);
        $this->assertNotContains('province_group', $codes);
        $this->assertNotContains('province_section', $codes);
        $this->assertContains('rose_croix', $codes);
    }

    public function test_not_groups_leaves_out_halls_groups_and_sections(): void
    {
        $province = $this->province();
        $order = ClubType::factory()->create(['code' => 'rose_croix']);
        $lodge = Club::factory()->create();
        Club::factory()->hall()->create();
        $group = Club::factory()->provinceGroup($province)->create();
        Club::factory()->provinceSection($group, $order)->create();

        $this->assertSame([$lodge->id], Club::notGroups()->pluck('id')->all());
    }

    public function test_section_belongs_to_group_and_order(): void
    {
        $province = $this->province();
        $order = ClubType::factory()->create(['code' => 'rose_croix']);
        $group = Club::factory()->provinceGroup($province)->create();
        $section = Club::factory()->provinceSection($group, $order)->create();

        $this->assertTrue($section->parentClub->is($group));
        $this->assertSame('rose_croix', $section->orderClubType->code);
        $this->assertTrue($group->sections->contains($section));
        $this->assertTrue($section->isGroup());
        $this->assertTrue($section->isProvinceSection());
        $this->assertTrue($group->isProvinceGroup());
    }
}
