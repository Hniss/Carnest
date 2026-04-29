<?php
namespace App\Observers;

use App\Models\Child;

class ChildObserver
{
    public function creating(Child $child): void
    {
        $child->age_group = $this->resolveAgeGroup($child->age);
    }

    public function updating(Child $child): void
    {
        if ($child->isDirty('age')) {
            $child->age_group = $this->resolveAgeGroup($child->age);
        }
    }

    private function resolveAgeGroup(int $age): string
    {
        return match(true) {
            $age <= 7  => '5-7',
            $age <= 11 => '8-11',
            default    => '12-14',
        };
    }
}
