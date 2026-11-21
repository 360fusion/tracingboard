<?php

namespace App\Http\Requests\HallClaim;

use App\Http\Requests\AppRequest;
use App\Models\HallClaim;
use Illuminate\Validation\Rule;

/**
 * The form posted to HallClaimController::store().
 */
class StoreHallClaimRequest extends AppRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'claimant_role' => ['required', Rule::in(array_keys(HallClaim::ROLES))],
            'message' => 'required|string|min:10|max:2000',
            'phone' => 'nullable|string|max:50',
            'confirm' => 'accepted',
        ];
    }
}
