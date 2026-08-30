<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrowdObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return config('aman.demo_enabled') && ! app()->environment('production');
    }

    public function rules(): array
    {
        return [
            'sampleId' => 'required|uuid',
            'deviceCount' => 'required|integer|between:0,10000000',
            'observedAt' => ['required', 'date', 'regex:/T.*(?:Z|[+-]\d{2}:\d{2})$/'],
            'source' => 'prohibited',
        ];
    }
}
