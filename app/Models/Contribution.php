<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Contribution extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid', 'teacher_id', 'year', 'month', 'amount', 'paid_at', 'reference', 'notes', 'source', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'integer',
            'paid_at' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Contribution $c) => $c->uuid ??= (string) Str::uuid());
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher_id,
            'teacher_name' => $this->teacher?->full_name,
            'year' => $this->year,
            'month' => $this->month,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at?->format('Y-m-d'),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'source' => $this->source,
            'recorded_by' => $this->recorder?->name ?? ($this->source === 'import' ? 'Uingizaji Excel' : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
