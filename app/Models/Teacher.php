<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Teacher extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'number', 'full_name', 'name_key', 'phone', 'email', 'is_active', 'notes'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'number' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Teacher $t) => $t->uuid ??= (string) Str::uuid());

        static::saving(function (Teacher $teacher) {
            $teacher->full_name = self::cleanName($teacher->full_name);
            $teacher->name_key = self::keyFor($teacher->full_name);
        });
    }

    /** Collapses the irregular spacing found in the source spreadsheet. */
    public static function cleanName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    public static function keyFor(string $name): string
    {
        return mb_strtoupper(self::cleanName($name));
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }
}
