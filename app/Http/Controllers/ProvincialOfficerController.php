<?php

namespace App\Http\Controllers;

use App\Models\ProvincialOfficer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A province section's list of provincial officers (office, name, rank as free text), shown on its public site.
 */
class ProvincialOfficerController extends Controller
{
    public function index(string $clubSlug): Response
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        return Inertia::render('Admin/Province/Officers', [
            'club' => $club->only(['id', 'name', 'slug']),
            'officers' => ProvincialOfficer::where('club_id', $club->id)->orderBy('position')->orderBy('id')->get(['id', 'office', 'name', 'rank']),
        ]);
    }

    public function store(Request $request, string $clubSlug): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        ProvincialOfficer::create($this->validated($request) + [
            'club_id' => $club->id,
            'position' => (int) ProvincialOfficer::where('club_id', $club->id)->max('position') + 1,
        ]);

        return back()->with('success', 'Officer added.');
    }

    public function update(Request $request, string $clubSlug, int $id): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        ProvincialOfficer::where('club_id', $club->id)->findOrFail($id)->update($this->validated($request));

        return back()->with('success', 'Officer updated.');
    }

    public function destroy(string $clubSlug, int $id): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        ProvincialOfficer::where('club_id', $club->id)->findOrFail($id)->delete();

        return back()->with('success', 'Officer removed.');
    }

    public function reorder(Request $request, string $clubSlug): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']])['ids'];

        // Exactly this club's officers, each once: a partial list would leave unlisted rows colliding with the new positions.
        if (ProvincialOfficer::where('club_id', $club->id)->whereIn('id', $ids)->count() !== count($ids)
            || ProvincialOfficer::where('club_id', $club->id)->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'The list must contain every officer on this page, once each.']);
        }

        DB::transaction(function () use ($club, $ids) {
            foreach (array_values($ids) as $i => $id) {
                ProvincialOfficer::where('club_id', $club->id)->whereKey($id)->update(['position' => $i + 1]);
            }
        });

        return back();
    }

    /**
     * @return array{office: string, name: string, rank: ?string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'office' => ['required', 'string', 'max:150'],
            'name' => ['required', 'string', 'max:150'],
            'rank' => ['nullable', 'string', 'max:150'],
        ]);

        return ['office' => trim($data['office']), 'name' => trim($data['name']), 'rank' => filled($data['rank'] ?? null) ? trim($data['rank']) : null];
    }
}
