<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    protected $fillable = ['name', 'contact_name', 'contact_email', 'contact_phone', 'is_active'];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }
}
