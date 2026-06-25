<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaSubclassTypeCode extends Model
{
    protected $fillable = ['fa_subclass_code', 'type_code', 'description', 'has_no_physical_size'];

    protected function casts(): array
    {
        return ['has_no_physical_size' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'fa_subclass_code';
    }
}
