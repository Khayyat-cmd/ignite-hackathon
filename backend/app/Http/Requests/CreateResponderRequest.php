<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateResponderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'role' => 'required|in:crowd_marshal,medical,security',
            'phone_number' => ['required', 'string', 'regex:/^\+[1-9][0-9]{4,14}$/'],
            'authorized' => 'required|boolean',
        ];
    }
}
