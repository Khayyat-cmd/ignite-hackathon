<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Responder extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'role', 'phone_number', 'authorized', 'available'];

    protected $hidden = ['phone_number'];

    protected function casts(): array
    {
        return ['phone_number' => 'encrypted', 'authorized' => 'boolean', 'available' => 'boolean', 'signals' => 'array', 'demo_position' => 'array'];
    }
}
