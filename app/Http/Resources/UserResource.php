<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signed-in account, as the frontend is allowed to see it.
 *
 * Shaped here rather than returning the model, so a column added to `users`
 * cannot leak into an API response by accident.
 *
 * `website_id` is the one field the rest of the system turns on: it decides
 * which site's rows this account may touch, and it travels to the services in
 * the token's claims (App\Models\User::getJWTCustomClaims).
 */
class UserResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => (int) $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'website_id' => $this->website_id ? (int) $this->website_id : null,
            'roles_code' => $this->roles_code,
            'is_active'  => (bool) $this->is_active,
        ];
    }
}
