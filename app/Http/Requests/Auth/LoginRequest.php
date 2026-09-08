<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Signing in is what makes you authorized, so there is nothing to check.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * A stray capital or trailing space in a typed email is not a failed
     * login, so normalise before the lookup rather than rejecting.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * Just what auth('api')->attempt() needs -- never the whole payload, so an
     * extra field in the request body cannot reach the credentials query.
     *
     * @return array<string, string>
     */
    public function credentials(): array
    {
        return $this->only(['email', 'password']);
    }
}
