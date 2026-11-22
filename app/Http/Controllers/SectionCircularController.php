<?php

namespace App\Http\Controllers;

use App\Domains\ClubAccounting\Enums\LodgeOffice;
use App\Domains\ClubAccounting\Models\Member;
use App\Enums\ClubMemberStatus;
use App\Models\Club;
use App\Models\SectionCircular;
use App\Models\User;
use App\Notifications\SectionCircularNotification;
use App\Support\ClubPermissions;
use App\Support\GroupAudience;
use App\Support\UploadRules;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A province section's circulars. Each goes to its chapters' Secretaries and pass-on staff, who pass it on to their own
 * members from their pass-on screen (PassOnController); the section never emails chapter members itself.
 */
class SectionCircularController extends Controller
{
    public function index(string $clubSlug): Response
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        return Inertia::render('Admin/Province/Circulars', [
            'club' => $club->only(['id', 'name', 'slug']),
            'circulars' => SectionCircular::where('club_id', $club->id)->latest('id')->get()
                ->map(fn (SectionCircular $circular) => [
                    'id' => $circular->id,
                    'title' => $circular->title,
                    'sent_on' => $circular->created_at->format('j M Y'),
                    'chapters' => $circular->chapter_count,
                    'attachment_name' => $circular->attachment_name,
                ]),
        ]);
    }

    public function store(Request $request, string $clubSlug): RedirectResponse
    {
        $club = $this->findClub($clubSlug);
        abort_unless($club->isProvinceSection(), 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:20000', 'required_without:attachment'],
            'attachment' => ['nullable', 'file', 'mimes:pdf', 'max:10240', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value instanceof UploadedFile && ($error = UploadRules::assertSafeUpload($value))) {
                    $fail($error);
                }
            }],
        ], ['body.required_without' => 'Write the circular or attach the PDF.']);

        $chapterIds = GroupAudience::memberClubIds($club);
        $file = $request->file('attachment');

        $circular = SectionCircular::create([
            'club_id' => $club->id,
            'sent_by' => $request->user()->id,
            'title' => $data['title'],
            'body' => PassOnController::asHtml($data['body'] ?? null),
            'attachment_path' => $file?->store('section-circulars/'.$club->id, 'local'),
            'attachment_name' => $file ? Str::limit($file->getClientOriginalName(), 150, '') : null,
            'chapter_count' => $chapterIds->count(),
        ]);

        $recipients = $this->recipients($chapterIds);
        foreach ($recipients as ['user' => $user, 'chapter' => $chapter]) {
            $user->notify(new SectionCircularNotification($circular, $club, $chapter));
        }

        return back()->with('success', 'Sent to '.$chapterIds->count().' '.GroupAudience::chapterWord($club, $chapterIds->count()).'. Their Secretaries pass it on to their members.');
    }

    /**
     * Everyone who may pass things on in a chapter (PassOnController::canSend): staff whose role holds
     * pass_on_communications under that chapter's own permissions, and Secretary office holders. Each person once,
     * with the first chapter they send for. A fixed number of queries, however many chapters.
     *
     * @param  Collection<int, int>  $chapterIds
     * @return Collection<int, array{user: User, chapter: Club}>
     */
    private function recipients(Collection $chapterIds): Collection
    {
        if ($chapterIds->isEmpty()) {
            return collect();
        }

        $chapters = Club::with('clubType')->whereIn('id', $chapterIds)->get()->keyBy('id');

        $staff = DB::table('club_user')->whereIn('club_id', $chapterIds)->where('status', ClubMemberStatus::Active->value)->get(['club_id', 'user_id', 'role'])
            ->filter(fn (object $row) => ClubPermissions::allows($chapters[$row->club_id], $row->role, 'pass_on_communications'))
            ->map(fn (object $row) => ['user_id' => (int) $row->user_id, 'club_id' => (int) $row->club_id]);

        $members = Member::whereIn('club_id', $chapterIds)->whereNotNull('user_id')->active()->get();
        Member::loadActiveOffices($members);
        $secretaries = $members
            ->filter(fn (Member $member) => collect($member->active_offices)
                ->contains(fn ($office) => ($office instanceof LodgeOffice ? $office : LodgeOffice::tryFrom((string) $office)) === LodgeOffice::Secretary))
            ->map(fn (Member $member) => ['user_id' => (int) $member->user_id, 'club_id' => (int) $member->club_id]);

        $chapterByUser = $staff->concat($secretaries)->unique('user_id')->mapWithKeys(fn (array $pair) => [$pair['user_id'] => $chapters->get($pair['club_id'])]);

        return User::whereIn('id', $chapterByUser->keys())->get()
            ->map(fn (User $user) => ['user' => $user, 'chapter' => $chapterByUser->get($user->id)])
            ->filter(fn (array $recipient) => $recipient['chapter'] instanceof Club)
            ->values();
    }
}
