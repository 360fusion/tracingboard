<?php

namespace App\Models;

use Database\Factories\ProvincialOfficerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a province section's officers list: office, name and rank as free text (the person need not be a user).
 */
class ProvincialOfficer extends Model
{
    /** @use HasFactory<ProvincialOfficerFactory> */
    use HasFactory;

    protected $fillable = ['club_id', 'office', 'name', 'rank', 'position'];
}
