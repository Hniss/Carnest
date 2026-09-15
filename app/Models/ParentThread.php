<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParentThread extends Model
{
    protected $fillable = ['school_id', 'child_id', 'parent_id', 'referent_id', 'subject', 'important'];

    protected function casts(): array
    {
        return ['important' => 'boolean'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function referent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ParentMessage::class, 'thread_id')->orderBy('sent_at');
    }

    /** Messages non lus pour un rôle donné (écrits par l'autre partie). */
    public function unreadCountFor(string $role): int
    {
        return $this->messages()->whereNull('read_at')->where('sender_role', '!=', $role)->count();
    }
}
