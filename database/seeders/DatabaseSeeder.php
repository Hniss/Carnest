<?php
namespace Database\Seeders;

use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // D10 (v3) — aucun mot de passe de démonstration dans le dépôt : lus depuis
        // l'environnement, sinon générés et affichés une seule fois en console.
        $adminPassword = $this->demoPassword('DEMO_ADMIN_PASSWORD', 'administrateur');
        $childPassword = $this->demoPassword('DEMO_CHILD_PASSWORD', 'élèves');

        $school = School::create([
            'name'  => 'École Agdal',
            'city'  => 'Rabat',
            'email' => 'contact@agdal.carenest.ma',
        ]);

        $director = User::create([
            'name'     => 'Mme Benali',
            'email'    => 'admin@carenest.ma',
            'password' => Hash::make($adminPassword),
        ]);
        $school->users()->attach($director->id, ['role' => 'director']);

        $children = [
            ['name' => 'Yassine', 'email' => 'yassine@carenest.ma', 'age' => 10, 'classe' => 'CM2',  'gender' => 'm'],
            ['name' => 'Amina',   'email' => 'amina@carenest.ma',   'age' => 8,  'classe' => 'CE2',  'gender' => 'f'],
            ['name' => 'Omar',    'email' => 'omar@carenest.ma',    'age' => 11, 'classe' => '5ème', 'gender' => 'm'],
            ['name' => 'Sara',    'email' => 'sara@carenest.ma',    'age' => 9,  'classe' => 'CM1',  'gender' => 'f'],
            ['name' => 'Karim',   'email' => 'karim@carenest.ma',   'age' => 12, 'classe' => '6ème', 'gender' => 'm'],
        ];

        foreach ($children as $data) {
            Child::create([
                ...$data,
                'school_id' => $school->id,
                'password'  => Hash::make($childPassword),
            ]);
        }
    }

    private function demoPassword(string $envKey, string $label): string
    {
        $value = env($envKey);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $generated = Str::password(16);
        $this->command?->warn("{$envKey} absent : mot de passe {$label} de démonstration généré (non enregistré) : {$generated}");

        return $generated;
    }
}
