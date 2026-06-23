<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $fillable = ['name', 'period', 'year', 'sign_name', 'sign_nip', 'letter_prefix', 'template_path', 'layout_json', 'is_active'];

    protected function casts(): array
    {
        return ['layout_json' => 'array', 'is_active' => 'boolean'];
    }

    protected $appends = ['template_url'];

    public function getTemplateUrlAttribute(): string
    {
        return asset('storage/'.$this->template_path);
    }
}
