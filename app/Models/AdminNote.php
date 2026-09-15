<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNote extends Model
{
    protected $fillable = ['child_id', 'alert_id', 'user_id', 'referent_id', 'content'];

    /** D10 (v3) — contenu de note chiffré au repos ; jamais filtré en SQL. */
    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** D2 (v3) — auteur côté référent de la note interne. */
    public function referent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referent_id');
    }
}
