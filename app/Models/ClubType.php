<?php

namespace App\Models;

use Database\Factories\ClubTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubType extends Model
{
    /** @use HasFactory<ClubTypeFactory> */
    use HasFactory;

    public const HALL = 'hall';

    protected $fillable = [
        'name',
        'code',
        'colour',
        'description',
        'website_url',
        'available_modules',
        'default_settings',
        'terminology',
        'rulers_schema',
    ];

    /** The club type of a hall's own club (its board); made on first use if the migration has not run. */
    public static function hall(): self
    {
        return static::firstOrCreate(['code' => self::HALL], [
            'name' => 'Masonic hall',
            'available_modules' => ['accounting', 'communications', 'events', 'newsletters', 'website_builder'],
            'default_settings' => [],
            'terminology' => ['club' => 'Hall', 'members' => 'Board', 'master' => 'Chairman'],
        ]);
    }

    protected function casts(): array
    {
        return [
            'available_modules' => 'array',
            'default_settings' => 'array',
            'terminology' => 'array',
            'rulers_schema' => 'array',
        ];
    }

    /**
     * @return HasMany<Club, $this>
     */
    public function clubs(): HasMany
    {
        return $this->hasMany(Club::class);
    }

    /**
     * @return HasMany<DefaultOfficerRole, $this>
     */
    public function defaultOfficerRoles(): HasMany
    {
        return $this->hasMany(DefaultOfficerRole::class);
    }

    /**
     * @return HasMany<DefaultRank, $this>
     */
    public function defaultRanks(): HasMany
    {
        return $this->hasMany(DefaultRank::class);
    }

    /**
     * @return HasMany<Lodge, $this>
     */
    public function lodges(): HasMany
    {
        return $this->hasMany(Lodge::class);
    }
}
