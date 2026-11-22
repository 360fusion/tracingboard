<?php

namespace Tests\Feature\Provinces;

use App\Models\Club;
use App\Models\ClubType;
use App\Models\GrandLodge;
use App\Models\Province;
use App\Models\User;
use App\Services\Provinces\ProvinceGroups;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SuperAdminProvinceGroupTest extends TestCase
{
    use RefreshDatabase;

    private Province $province;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $grandLodge = GrandLodge::create(['name' => 'United Grand Lodge of England', 'code' => 'ugle', 'country' => 'England']);
        $this->province = Province::create(['grand_lodge_id' => $grandLodge->id, 'name' => 'Durham', 'code' => 'durham', 'country' => 'England']);
    }

    private function postGroup(User $by)
    {
        return $this->actingAs($by)->post(route('superadmin.provinces.group', $this->province->id), ['name' => 'Olive Owner', 'email' => 'olive@example.com']);
    }

    private function groupCount(): int
    {
        return Club::where('club_type_id', ClubType::provinceGroup()->id)->where('province_id', $this->province->id)->count();
    }

    public function test_superadmin_creates_group_and_invites_owner(): void
    {
        $this->postGroup(User::factory()->create(['is_super_admin' => true]))->assertSessionMissing('error')->assertRedirect();

        $this->assertSame(1, $this->groupCount());
        $group = Club::where('province_id', $this->province->id)->firstOrFail();
        $this->assertSame('durham', $group->slug);
        $pivot = $group->users()->where('email', 'olive@example.com')->firstOrFail()->pivot;
        $this->assertSame('owner', $pivot->role);
        $this->assertSame('pending', $pivot->status);
    }

    public function test_repeat_setup_invites_into_the_existing_group(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->postGroup($admin);
        $this->actingAs($admin)->post(route('superadmin.provinces.group', $this->province->id), ['name' => 'Second Person', 'email' => 'second@example.com'])->assertSessionMissing('error');

        $this->assertSame(1, $this->groupCount());
        $pivot = app(ProvinceGroups::class)->groupFor($this->province)->users()->where('email', 'second@example.com')->firstOrFail()->pivot;
        $this->assertSame('owner', $pivot->role);
        $this->assertSame('pending', $pivot->status);
    }

    public function test_create_group_refuses_a_second_group(): void
    {
        $groups = app(ProvinceGroups::class);
        $groups->createGroup($this->province);

        try {
            $groups->createGroup($this->province);
            $this->fail('A second group was created.');
        } catch (DomainException $e) {
            $this->assertSame('This province already has a group.', $e->getMessage());
        }
        $this->assertSame(1, $this->groupCount());
    }

    public function test_non_superadmin_cannot_create_a_group(): void
    {
        $this->postGroup(User::factory()->create())->assertRedirect(); // the superadmin group redirects non-superadmins, as for halls
        $this->assertSame(0, $this->groupCount());
    }
}
