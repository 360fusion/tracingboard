<?php

namespace App\Models;

use App\Support\OrderColours;
use Database\Factories\ClubTypeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubType extends Model
{
    /** @use HasFactory<ClubTypeFactory> */
    use HasFactory;

    public const HALL = 'hall';

    public const PROVINCE_GROUP = 'province_group';

    public const PROVINCE_SECTION = 'province_section';

    /** Club types that are not a lodge or chapter of their own: they have no members. */
    public const GROUP_CODES = [self::HALL, self::PROVINCE_GROUP, self::PROVINCE_SECTION];

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
            'colour' => OrderColours::for(self::HALL),
            'available_modules' => ['accounting', 'communications', 'events', 'newsletters', 'website_builder'],
            'default_settings' => [],
            'terminology' => ['club' => 'Hall', 'members' => 'Board', 'master' => 'Chairman'],
        ]);
    }

    /** The club type of a province's group (its people and website); made on first use if the migration has not run. */
    public static function provinceGroup(): self
    {
        return static::firstOrCreate(['code' => self::PROVINCE_GROUP], [
            'name' => 'Province group',
            'colour' => OrderColours::for(self::PROVINCE_GROUP),
            'available_modules' => ['communications', 'website_builder'],
            'default_settings' => [],
            'terminology' => ['club' => 'Province', 'members' => 'People'],
        ]);
    }

    /** The club type of one order's section of a province group; made on first use if the migration has not run. */
    public static function provinceSection(): self
    {
        return static::firstOrCreate(['code' => self::PROVINCE_SECTION], [
            'name' => 'Province section',
            'colour' => OrderColours::for(self::PROVINCE_SECTION),
            'available_modules' => ['accounting', 'communications', 'events', 'newsletters', 'website_builder'],
            'default_settings' => [],
            'terminology' => ['club' => 'Section', 'members' => 'People'],
        ]);
    }

    /**
     * The types a lodge or chapter can be.
     *
     * @param  Builder<ClubType>  $query
     * @return Builder<ClubType>
     */
    public function scopeOrders(Builder $query): Builder
    {
        return $query->whereNotIn('code', self::GROUP_CODES);
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
