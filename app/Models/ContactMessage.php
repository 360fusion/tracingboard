<?php

namespace App\Models;

use Database\Factories\ContactMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message from the public site's contact form (ContactController). Superadmins read them and mark each one handled.
 */
class ContactMessage extends Model
{
    /** @use HasFactory<ContactMessageFactory> */
    use HasFactory;

    /** What the message is about: the form's choices, in order. */
    public const TOPICS = [
        'access' => 'Using Tracing Board for my lodge',
        'question' => 'A general question',
        'pricing' => 'Pricing',
        'problem' => 'Something is not working',
        'other' => 'Something else',
    ];

    protected $fillable = ['name', 'email', 'lodge', 'topic', 'message'];

    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
