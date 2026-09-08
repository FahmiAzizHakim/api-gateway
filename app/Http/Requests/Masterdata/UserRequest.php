<?php

namespace App\Http\Requests\Masterdata;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Route-level: the auth:api middleware gates these endpoints.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id'); // null on create

        return [
            'name'       => 'required|string|max:100',
            'email'      => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($id),
            ],
            // Password required on create, optional on edit (leave blank to keep current).
            'password'   => [$id ? 'nullable' : 'required', 'min:8', 'confirmed'],
            'roles_code' => 'required|exists:users_menugroup,code',
            'is_active'  => 'required|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
