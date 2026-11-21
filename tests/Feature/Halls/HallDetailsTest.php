<?php

namespace Tests\Feature\Halls;

use App\Models\Club;
use App\Models\MasonicHall;
use App\Models\User;
use App\Services\Halls\HallClubs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HallDetailsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: MasonicHall, 1: Club, 2: User} */
    private function hallWithSecretary(): array
    {
        $hall = MasonicHall::factory()->create(['ugle_url' => 'https://ugle.example/h', 'source_url' => 'https://src.example/h', 'postcode' => 'AB1 2CD', 'latitude' => 51.5, 'longitude' => -0.1]);
        $club = app(HallClubs::class)->createFor($hall, User::factory()->create());
        $user = User::factory()->create();
        $club->users()->attach($user->id, ['role' => 'admin', 'status' => 'active']);

        return [$hall->fresh(), $club, $user];
    }

    /** @return array<string, mixed> */
    private function payload(MasonicHall $hall, array $over = []): array
    {
        return $over + ['name' => $hall->name, 'telephone' => $hall->telephone];
    }

    public function test_secretary_updates_the_hall_and_the_directory_shows_it(): void
    {
        [$hall, $club, $user] = $this->hallWithSecretary();

        $this->actingAs($user)->put("/{$club->slug}/admin/hall", $this->payload($hall, ['telephone' => '01234 567890']))->assertSessionHasNoErrors();

        $this->get("/lodges/halls/{$hall->slug}")->assertOk()->assertSee('01234 567890', false);
    }

    public function test_board_cannot_change_province_slug_or_source_urls(): void
    {
        [$hall, $club, $user] = $this->hallWithSecretary();
        $before = $hall->only(['province_id', 'slug', 'ugle_url', 'source_url']);

        $this->actingAs($user)->put("/{$club->slug}/admin/hall", $this->payload($hall, [
            'province_id' => 999, 'slug' => 'hijacked', 'ugle_url' => 'https://evil.example', 'source_url' => 'https://evil.example',
        ]));

        $this->assertSame($before, $hall->fresh()->only(['province_id', 'slug', 'ugle_url', 'source_url']));
    }

    public function test_changing_postcode_clears_coordinates(): void
    {
        [$hall, $club, $user] = $this->hallWithSecretary();

        $this->actingAs($user)->put("/{$club->slug}/admin/hall", $this->payload($hall, ['postcode' => 'ZZ9 9ZZ']));

        $this->assertNull($hall->fresh()->latitude);
    }

    public function test_photo_upload_uses_upload_rules(): void
    {
        Storage::fake('public');
        [$hall, $club, $user] = $this->hallWithSecretary();

        $this->actingAs($user)->post("/{$club->slug}/admin/hall/photos", ['photo' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
            ->assertSessionHasErrors('photo');
        $this->assertCount(0, $hall->getMedia('photos'));

        $this->actingAs($user)->post("/{$club->slug}/admin/hall/photos", ['photo' => UploadedFile::fake()->image('big.jpg', 3000, 2000)])
            ->assertSessionHasNoErrors();
        $media = $hall->fresh()->getFirstMedia('photos');
        $this->assertNotNull($media);
        $this->assertLessThanOrEqual(1920, getimagesize($media->getPath())[0]);

        $this->get("/lodges/halls/{$hall->slug}")->assertSee($media->file_name, false);

        $this->actingAs($user)->delete("/{$club->slug}/admin/hall/photos/{$media->id}");
        $this->assertCount(0, $hall->fresh()->getMedia('photos'));
    }

    public function test_another_halls_board_cannot_edit_this_hall(): void
    {
        [$hall, $club] = $this->hallWithSecretary();
        [, , $other] = $this->hallWithSecretary();

        $this->actingAs($other)->put("/{$club->slug}/admin/hall", $this->payload($hall, ['telephone' => '1']))->assertForbidden();
        $this->actingAs($other)->get("/{$club->slug}/admin/hall")->assertForbidden();
    }
}
