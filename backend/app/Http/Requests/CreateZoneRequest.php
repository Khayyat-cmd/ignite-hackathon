<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'area_sqm' => 'required|numeric|between:1,100000000',
            'warning_density' => 'required|numeric|between:0.01,20',
            'critical_density' => 'required|numeric|gt:warning_density|max:20',
            'people_per_device' => 'nullable|numeric|between:0.01,100',
            'calibration_note' => 'required_with:people_per_device|nullable|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ];
    }
}
