<?php
namespace Database\Seeders;

use App\Models\Child;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::create([
            'name'  => 'École Agdal',
            'city'  => 'Rabat',
            'email' => 'contact@agdal.carenest.ma',
        ]);

        $director = User::create([
            'name'     => 'Mme Benali',
            'email'    => 'admin@carenest.ma',
            'password' => Hash::make('admin123'),
        ]);
        $school->users()->attach($director->id, ['role' => 'director']);

        $children = [
            ['name' => 'Yassine', 'email' => 'yassine@carenest.ma', 'age' => 10, 'classe' => 'CM2'],
            ['name' => 'Amina',   'email' => 'amina@carenest.ma',   'age' => 8,  'classe' => 'CE2'],
            ['name' => 'Omar',    'email' => 'omar@carenest.ma',    'age' => 11, 'classe' => '5ème'],
            ['name' => 'Sara',    'email' => 'sara@carenest.ma',    'age' => 9,  'classe' => 'CM1'],
            ['name' => 'Karim',   'email' => 'karim@carenest.ma',   'age' => 12, 'classe' => '6ème'],
        ];

        foreach ($children as $data) {
            Child::create([
                ...$data,
                'school_id' => $school->id,
                'password'  => Hash::make('demo123'),
            ]);
        }
    }
}
