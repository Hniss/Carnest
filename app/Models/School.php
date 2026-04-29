<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class School extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'address', 'city', 'phone', 'email'];

    public function setting(): HasOne
    {
        return $this->hasOne(SchoolSetting::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'school_user')
                    ->withPivot('role');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Child::class);
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
