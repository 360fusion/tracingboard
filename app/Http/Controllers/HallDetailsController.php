<?php

namespace App\Http\Controllers;

use App\Http\Requests\MasonicHallDetailsRequest;
use App\Models\MasonicHall;
use App\Support\ImageDownscaler;
use App\Support\UploadRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A hall's board edits its own directory entry. Changes are live on the public directory straight away.
 */
class HallDetailsController extends Controller
{
    public function edit(string $clubSlug): Response
    {
        $hall = $this->hall($clubSlug);

        return Inertia::render('Admin/Hall/Details', [
            'club' => $this->findClub($clubSlug),
            'hall' => $hall->only(['name', 'kind', 'address_line_1', 'address_line_2', 'town', 'county', 'postcode', 'country', 'telephone', 'email', 'website_url', 'slug']),
            'kinds' => MasonicHall::KINDS,
            'photos' => $hall->getMedia('photos')->map(fn ($media) => ['id' => $media->id, 'url' => $media->getUrl(), 'name' => $media->file_name])->values(),
        ]);
    }

    public function update(MasonicHallDetailsRequest $request, string $clubSlug): RedirectResponse
    {
        $this->hall($clubSlug)->update($request->validated());

        return back()->with('success', 'Hall details saved. They are live on the directory now.');
    }

    public function storePhoto(Request $request, string $clubSlug): RedirectResponse
    {
        $hall = $this->hall($clubSlug);
        $request->validate(['photo' => UploadRules::image(8192, true)]);

        ImageDownscaler::apply($request->file('photo'));
        $hall->addMediaFromRequest('photo')->toMediaCollection('photos');

        return back()->with('success', 'Photo added.');
    }

    public function destroyPhoto(string $clubSlug, int $mediaId): RedirectResponse
    {
        $this->hall($clubSlug)->getMedia('photos')->firstWhere('id', $mediaId)?->delete() ?? abort(404);

        return back()->with('success', 'Photo removed.');
    }

    private function hall(string $clubSlug): MasonicHall
    {
        return $this->findClub($clubSlug)->hall()->firstOrFail();
    }
}
