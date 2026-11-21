<?php

namespace App\Models;

use Database\Factories\HallClaimFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone's request to run a hall's board. A superadmin checks they have a part in the hall before approving, which
 * creates the hall club with them as owner. Status changes are only made through HallClaimService.
 */
class HallClaim extends Model
{
    /** @use HasFactory<HallClaimFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const INFO_REQUESTED = 'info_requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const OPEN = [self::PENDING, self::INFO_REQUESTED];

    public const ROLES = [
        'trustee' => 'A director or trustee of the hall company',
        'secretary' => 'Hall secretary or manager',
        'treasurer' => 'Treasurer',
        'officer' => 'Another officer or committee member',
    ];

    /** The most claims one person may have waiting at once. */
    public const MAX_OPEN_PER_USER = 5;

    protected $fillable = ['masonic_hall_id', 'user_id', 'status', 'claimant_role', 'message', 'phone'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<MasonicHall, $this>
     */
    public function hall(): BelongsTo
    {
        return $this->belongsTo(MasonicHall::class, 'masonic_hall_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /**
     * @param  Builder<HallClaim>  $query
     * @return Builder<HallClaim>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN);
    }
}
