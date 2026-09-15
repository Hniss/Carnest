<?php
namespace App\Observers;

use App\Models\Child;
use Illuminate\Support\Carbon;

class ChildObserver
{
    public function creating(Child $child): void
    {
        $this->syncAgeFromBirthDate($child);
        $child->age_group = Child::ageGroupFor((int) $child->age);
    }

    public function updating(Child $child): void
    {
        if ($child->isDirty('birth_date')) {
            $this->syncAgeFromBirthDate($child);
        }
        if ($child->isDirty('age') || $child->isDirty('birth_date')) {
            $child->age_group = Child::ageGroupFor((int) $child->age);
        }
    }

    /**
     * D3 (v3) — quand birth_date est renseignée, la colonne `age` est alignée
     * dessus (elle reste NOT NULL et sert de repli pour les enfants sans date).
     */
    private function syncAgeFromBirthDate(Child $child): void
    {
        $birth = $child->getAttributes()['birth_date'] ?? null;
        if (! empty($birth)) {
            $child->setRawAttributes(array_merge($child->getAttributes(), [
                'age' => Carbon::parse($birth)->age,
            ]));
        }
    }
}
