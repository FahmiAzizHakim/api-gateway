<?php

namespace App\Http\Requests\Masterdata;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupMenuRequest extends FormRequest
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

        $rules = [
            'name'         => 'required|string|max:191',
            'desc'         => 'nullable|string|max:255',
            'activestatus' => 'required|boolean',
            'menus'        => 'nullable|array',
            'menus.*'      => 'integer|exists:menus,id',
        ];

        // Code is only settable on create; locked while editing (users reference it).
        if (!$id) {
            $rules['code'] = [
                'required', 'string', 'max:191', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('users_menugroup', 'code'),
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'activestatus' => $this->boolean('activestatus'),
        ]);
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'The code may only contain letters, numbers, underscores and dashes.',
        ];
    }
}
