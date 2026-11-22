<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A circular a province section sends to its chapters. Their Secretaries pass it on through their own pass-on screen;
 * the section never reaches chapter members itself.
 */
class SectionCircular extends Model
{
    protected $fillable = ['club_id', 'sent_by', 'title', 'body', 'attachment_path', 'attachment_name', 'chapter_count'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['body' => SanitizedHtml::class];
    }

    /** The body as plain text with its paragraphs, for the pass-on form's text box. */
    public function bodyText(): string
    {
        $text = preg_replace(['#</p>\s*<p[^>]*>#i', '#<br\s*/?>#i'], ["\n\n", "\n"], (string) $this->body);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }

    public function streamAttachmentResponse(): StreamedResponse
    {
        abort_unless($this->attachment_path && Storage::disk('local')->exists($this->attachment_path), 404);

        return Storage::disk('local')->response($this->attachment_path, $this->attachment_name ?: 'circular.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return BelongsTo<Club, $this> */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }
}
