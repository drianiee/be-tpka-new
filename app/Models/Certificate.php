<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $fillable = ['name', 'period', 'year', 'sign_name', 'sign_nip', 'letter_prefix', 'template_file', 'sign_file', 'stamp_file', 'layout_json', 'is_active'];

    protected function casts(): array
    {
        return ['layout_json' => 'array', 'is_active' => 'boolean'];
    }

    protected $appends = ['template_url', 'template_path', 'sign_url', 'stamp_url'];

    public function getTemplateUrlAttribute(): string
    {
        return asset('storage/'.$this->template_file);
    }

    public function getTemplatePathAttribute(): ?string
    {
        return $this->template_file;
    }

    public function getSignUrlAttribute(): ?string
    {
        return $this->sign_file ? asset('storage/'.$this->sign_file) : null;
    }

    public function getStampUrlAttribute(): ?string
    {
        return $this->stamp_file ? asset('storage/'.$this->stamp_file) : null;
    }
}
