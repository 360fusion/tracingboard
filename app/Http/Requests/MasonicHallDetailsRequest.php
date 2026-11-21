<?php

namespace App\Http\Requests;

use App\Models\MasonicHall;
use Illuminate\Validation\Rule;

/**
 * What a hall's board may edit about its hall. The province, slug and source links are not here: only the platform changes them.
 */
class MasonicHallDetailsRequest extends ClubRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function detailRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'kind' => ['sometimes', Rule::in(array_keys(MasonicHall::KINDS))],
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'town' => 'nullable|string|max:100',
            'county' => 'nullable|string|max:100',
            'postcode' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:100',
            'telephone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website_url' => ['nullable', 'url', 'max:255', 'regex:#^https?://#i'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::detailRules();
    }
}
