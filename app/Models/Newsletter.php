<?php

namespace App\Models;

use App\Casts\SanitizedHtml;
use App\Enums\ClubRole;
use App\Observers\NewsletterTeamEvents;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[ObservedBy(NewsletterTeamEvents::class)]
class Newsletter extends Model implements HasMedia
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    /** @use InteractsWithMedia<Media> */
    use InteractsWithMedia;

    protected $fillable = [
        'club_id',
        'newsletter_type_id',
        'subject',
        'content',
        'attachments',
        'target_roles',
        'status',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => SanitizedHtml::class,
            'attachments' => 'array',
            'target_roles' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Club, $this>
     */
    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    /**
     * @return HasMany<NewsletterDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NewsletterDelivery::class);
    }

    /**
     * @return BelongsTo<NewsletterType, $this>
     */
    public function newsletterType(): BelongsTo
    {
        return $this->belongsTo(NewsletterType::class);
    }

    /**
     * The club roles a newsletter reaches. The form has no box for owners, so they come with admins.
     *
     * @param  list<string>  $roles
     * @return list<string>
     */
    public static function rolesReached(array $roles): array
    {
        return in_array(ClubRole::Admin->value, $roles, true) ? array_values(array_unique([...$roles, ClubRole::Owner->value])) : $roles;
    }

    /**
     * Whether someone with this club role is among those it was sent to.
     */
    public function reachesRole(?string $role): bool
    {
        return empty($this->target_roles) || in_array($role, self::rolesReached($this->target_roles), true);
    }
}
