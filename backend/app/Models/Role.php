<?php

namespace App\Models;

use App\Enums\RoleCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    protected $casts = [
        'code' => RoleCode::class,
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
