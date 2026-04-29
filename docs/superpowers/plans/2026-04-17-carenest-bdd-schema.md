# CareNest BDD Schema — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Créer les 7 migrations, 6 modèles Eloquent, 1 observer, le guard `child`, et le job stub `ProcessSessionClosure` — couche BDD complète du MVP CareNest.

**Architecture:** Schéma multi-école, 8 tables (schools, users, school_user, school_settings, children, chat_sessions, alerts, admin_notes). Guard `child` séparé de `web`. Aucun message brut stocké — conformité RGPD / loi 09-08.

**Tech Stack:** Laravel 11 · MySQL 8.0 · PHPUnit (SQLite in-memory pour les tests) · Eloquent ORM

---

## File Map

| Fichier | Action | Rôle |
|---|---|---|
| `database/migrations/2026_04_17_000001_create_schools_table.php` | Create | Table schools |
| `database/migrations/2026_04_17_000002_create_school_user_table.php` | Create | Pivot admin ↔ école |
| `database/migrations/2026_04_17_000003_create_school_settings_table.php` | Create | Config par école |
| `database/migrations/2026_04_17_000004_create_children_table.php` | Create | Enfants (auth child guard) |
| `database/migrations/2026_04_17_000005_create_chat_sessions_table.php` | Create | Sessions chat (JAMAIS les messages bruts) |
| `database/migrations/2026_04_17_000006_create_alerts_table.php` | Create | Alertes générées |
| `database/migrations/2026_04_17_000007_create_admin_notes_table.php` | Create | Notes optionnelles admin |
| `app/Models/School.php` | Create | Modèle école |
| `app/Models/SchoolSetting.php` | Create | Modèle settings école |
| `app/Models/Child.php` | Create | Modèle enfant (Authenticatable) |
| `app/Models/ChatSession.php` | Create | Modèle session chat |
| `app/Models/Alert.php` | Create | Modèle alerte |
| `app/Models/AdminNote.php` | Create | Modèle note admin |
| `app/Models/User.php` | Modify | Ajouter relations schools, adminNotes |
| `app/Observers/ChildObserver.php` | Create | Auto-calcul age_group |
| `app/Providers/AppServiceProvider.php` | No change | Observer déclaré via `#[ObservedBy]` — aucune modification |
| `config/auth.php` | Modify | Ajouter guard `child` + provider `children` |
| `app/Jobs/ProcessSessionClosure.php` | Create | Stub job recalcul score |
| `database/factories/SchoolFactory.php` | Create | Factory école |
| `database/factories/ChildFactory.php` | Create | Factory enfant |
| `database/seeders/DatabaseSeeder.php` | Modify | Seed école + enfants de démo |
| `tests/Feature/Schema/MigrationsTest.php` | Create | Vérifie structure BDD |
| `tests/Unit/Observers/ChildObserverTest.php` | Create | Teste calcul age_group |
| `tests/Unit/Models/SchoolTest.php` | Create | Teste relations School |
| `tests/Unit/Models/ChildTest.php` | Create | Teste relations + score Child |

---

## Task 1 — Migration `schools`

**Files:**
- Create: `database/migrations/2026_04_17_000001_create_schools_table.php`
- Create: `tests/Feature/Schema/MigrationsTest.php`

- [ ] **Step 1 : Créer le fichier de test**

```php
<?php
// tests/Feature/Schema/MigrationsTest.php
namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schools_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('schools'));
        foreach (['id','name','address','city','phone','email','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('schools', $col), "Missing column: $col");
        }
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
php artisan test --filter=MigrationsTest::test_schools_table_has_expected_columns
```
Attendu : **FAIL** — "Table schools doesn't exist"

- [ ] **Step 3 : Créer la migration**

```php
<?php
// database/migrations/2026_04_17_000001_create_schools_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('address', 255)->nullable();
            $table->string('city', 100);
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
```

- [ ] **Step 4 : Vérifier que le test passe**

```bash
php artisan test --filter=MigrationsTest::test_schools_table_has_expected_columns
```
Attendu : **PASS**

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_04_17_000001_create_schools_table.php tests/Feature/Schema/MigrationsTest.php
git commit -m "feat(db): add schools table migration + schema test"
```

---

## Task 2 — Migrations `school_user` + `school_settings`

**Files:**
- Create: `database/migrations/2026_04_17_000002_create_school_user_table.php`
- Create: `database/migrations/2026_04_17_000003_create_school_settings_table.php`
- Modify: `tests/Feature/Schema/MigrationsTest.php`

- [ ] **Step 1 : Ajouter les tests**

Dans `tests/Feature/Schema/MigrationsTest.php`, ajouter après le test existant :

```php
public function test_school_user_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('school_user'));
    foreach (['id','school_id','user_id','role','created_at'] as $col) {
        $this->assertTrue(Schema::hasColumn('school_user', $col), "Missing column: $col");
    }
}

public function test_school_settings_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('school_settings'));
    foreach (['id','school_id','alert_threshold','email_notifications','language','created_at','updated_at'] as $col) {
        $this->assertTrue(Schema::hasColumn('school_settings', $col), "Missing column: $col");
    }
}
```

- [ ] **Step 2 : Vérifier que les tests échouent**

```bash
php artisan test --filter=MigrationsTest
```
Attendu : 1 PASS + 2 FAIL

- [ ] **Step 3 : Créer les deux migrations**

```php
<?php
// database/migrations/2026_04_17_000002_create_school_user_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['director','counselor','teacher','staff'])->default('staff');
            $table->timestamp('created_at')->nullable();

            $table->unique(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_user');
    }
};
```

```php
<?php
// database/migrations/2026_04_17_000003_create_school_settings_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('alert_threshold')->default(30);
            $table->boolean('email_notifications')->default(true);
            $table->enum('language', ['fr','ar','en'])->default('fr');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_settings');
    }
};
```

- [ ] **Step 4 : Vérifier que tous les tests passent**

```bash
php artisan test --filter=MigrationsTest
```
Attendu : 3 PASS

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_04_17_000002_create_school_user_table.php database/migrations/2026_04_17_000003_create_school_settings_table.php tests/Feature/Schema/MigrationsTest.php
git commit -m "feat(db): add school_user and school_settings migrations"
```

---

## Task 3 — Migration `children`

**Files:**
- Create: `database/migrations/2026_04_17_000004_create_children_table.php`
- Modify: `tests/Feature/Schema/MigrationsTest.php`

- [ ] **Step 1 : Ajouter le test**

```php
public function test_children_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('children'));
    foreach ([
        'id','school_id','name','email','password',
        'age','age_group','classe','score_enfant',
        'status','last_session_at','remember_token',
        'created_at','updated_at',
    ] as $col) {
        $this->assertTrue(Schema::hasColumn('children', $col), "Missing column: $col");
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
php artisan test --filter=MigrationsTest::test_children_table_has_expected_columns
```
Attendu : **FAIL**

- [ ] **Step 3 : Créer la migration**

```php
<?php
// database/migrations/2026_04_17_000004_create_children_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('email', 150)->unique();
            $table->string('password');
            $table->unsignedTinyInteger('age');
            $table->enum('age_group', ['5-7', '8-11', '12-14']);
            $table->string('classe', 50);
            $table->float('score_enfant')->nullable()->default(null);
            $table->enum('status', ['ok', 'a_suivre'])->default('ok');
            $table->timestamp('last_session_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['school_id', 'score_enfant']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
    }
};
```

- [ ] **Step 4 : Vérifier que le test passe**

```bash
php artisan test --filter=MigrationsTest::test_children_table_has_expected_columns
```
Attendu : **PASS**

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_04_17_000004_create_children_table.php tests/Feature/Schema/MigrationsTest.php
git commit -m "feat(db): add children table migration"
```

---

## Task 4 — Migration `chat_sessions`

**Files:**
- Create: `database/migrations/2026_04_17_000005_create_chat_sessions_table.php`
- Modify: `tests/Feature/Schema/MigrationsTest.php`

- [ ] **Step 1 : Ajouter le test**

```php
public function test_chat_sessions_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('chat_sessions'));
    foreach ([
        'id','child_id','school_id','zone',
        'ai_summary','low_confidence',
        'started_at','ended_at','created_at','updated_at',
    ] as $col) {
        $this->assertTrue(Schema::hasColumn('chat_sessions', $col), "Missing column: $col");
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
php artisan test --filter=MigrationsTest::test_chat_sessions_table_has_expected_columns
```
Attendu : **FAIL**

- [ ] **Step 3 : Créer la migration**

```php
<?php
// database/migrations/2026_04_17_000005_create_chat_sessions_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->enum('zone', ['green','yellow','orange','red'])->nullable()->default(null);
            $table->text('ai_summary')->nullable();
            $table->boolean('low_confidence')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable()->default(null);
            $table->timestamps();

            $table->index(['child_id', 'ended_at']);
            $table->index(['school_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
```

- [ ] **Step 4 : Vérifier que le test passe**

```bash
php artisan test --filter=MigrationsTest::test_chat_sessions_table_has_expected_columns
```
Attendu : **PASS**

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_04_17_000005_create_chat_sessions_table.php tests/Feature/Schema/MigrationsTest.php
git commit -m "feat(db): add chat_sessions table migration (no raw messages stored)"
```

---

## Task 5 — Migrations `alerts` + `admin_notes`

**Files:**
- Create: `database/migrations/2026_04_17_000006_create_alerts_table.php`
- Create: `database/migrations/2026_04_17_000007_create_admin_notes_table.php`
- Modify: `tests/Feature/Schema/MigrationsTest.php`

- [ ] **Step 1 : Ajouter les tests**

```php
public function test_alerts_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('alerts'));
    foreach ([
        'id','session_id','child_id','school_id',
        'type','level','status','notified_at',
        'created_at','updated_at',
    ] as $col) {
        $this->assertTrue(Schema::hasColumn('alerts', $col), "Missing column: $col");
    }
}

public function test_admin_notes_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('admin_notes'));
    foreach (['id','alert_id','user_id','content','created_at','updated_at'] as $col) {
        $this->assertTrue(Schema::hasColumn('admin_notes', $col), "Missing column: $col");
    }
}
```

- [ ] **Step 2 : Vérifier que les tests échouent**

```bash
php artisan test --filter=MigrationsTest
```
Attendu : 5 PASS + 2 FAIL

- [ ] **Step 3 : Créer les deux migrations**

```php
<?php
// database/migrations/2026_04_17_000006_create_alerts_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->foreignId('child_id')->constrained('children')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['harcelement','detresse','stress','tristesse','danger','isolement']);
            $table->enum('level', ['critical','moderate']);
            $table->enum('status', ['unread','read','resolved'])->default('unread');
            $table->timestamp('notified_at')->nullable()->default(null);
            $table->timestamps();

            $table->index(['school_id', 'status']);
            $table->index(['child_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
```

```php
<?php
// database/migrations/2026_04_17_000007_create_admin_notes_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notes');
    }
};
```

- [ ] **Step 4 : Vérifier que tous les tests passent**

```bash
php artisan test --filter=MigrationsTest
```
Attendu : **7 PASS**

- [ ] **Step 5 : Commit**

```bash
git add database/migrations/2026_04_17_000006_create_alerts_table.php database/migrations/2026_04_17_000007_create_admin_notes_table.php tests/Feature/Schema/MigrationsTest.php
git commit -m "feat(db): add alerts and admin_notes migrations"
```

---

## Task 6 — Modèles `School` + `SchoolSetting`

**Files:**
- Create: `app/Models/School.php`
- Create: `app/Models/SchoolSetting.php`
- Create: `database/factories/SchoolFactory.php`
- Create: `tests/Unit/Models/SchoolTest.php`

- [ ] **Step 1 : Créer le test**

```php
<?php
// tests/Unit/Models/SchoolTest.php
namespace Tests\Unit\Models;

use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_has_settings_relationship(): void
    {
        $school = School::factory()->create();
        $this->assertInstanceOf(SchoolSetting::class, $school->setting);
    }

    public function test_school_has_users_relationship(): void
    {
        $school = School::factory()->create();
        $user = User::factory()->create();
        $school->users()->attach($user->id, ['role' => 'director']);

        $this->assertCount(1, $school->users);
        $this->assertEquals('director', $school->users->first()->pivot->role);
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
php artisan test --filter=SchoolTest
```
Attendu : **FAIL** — "Class School not found"

- [ ] **Step 3 : Créer la factory**

```php
<?php
// database/factories/SchoolFactory.php
namespace Database\Factories;

use App\Models\SchoolSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

class SchoolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'    => $this->faker->company() . ' School',
            'city'    => $this->faker->city(),
            'email'   => $this->faker->unique()->companyEmail(),
            'phone'   => $this->faker->phoneNumber(),
            'address' => $this->faker->address(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($school) {
            SchoolSetting::create(['school_id' => $school->id]);
        });
    }
}
```

- [ ] **Step 4 : Créer les modèles**

```php
<?php
// app/Models/School.php
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
                    ->withPivot('role')
                    ->withTimestamps();
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
```

```php
<?php
// app/Models/SchoolSetting.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolSetting extends Model
{
    protected $fillable = [
        'school_id',
        'alert_threshold',
        'email_notifications',
        'language',
    ];

    protected function casts(): array
    {
        return [
            'email_notifications' => 'boolean',
            'alert_threshold'     => 'integer',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
```

- [ ] **Step 5 : Vérifier que les tests passent**

```bash
php artisan test --filter=SchoolTest
```
Attendu : **2 PASS**

- [ ] **Step 6 : Commit**

```bash
git add app/Models/School.php app/Models/SchoolSetting.php database/factories/SchoolFactory.php tests/Unit/Models/SchoolTest.php
git commit -m "feat(models): add School and SchoolSetting models with relationships"
```

---

## Task 7 — Modèle `Child` + `ChildObserver` + Guard `child`

**Files:**
- Create: `app/Models/Child.php`
- Create: `app/Observers/ChildObserver.php`
- Create: `database/factories/ChildFactory.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/auth.php`
- Create: `tests/Unit/Observers/ChildObserverTest.php`
- Create: `tests/Unit/Models/ChildTest.php`

- [ ] **Step 1 : Créer les tests**

```php
<?php
// tests/Unit/Observers/ChildObserverTest.php
namespace Tests\Unit\Observers;

use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChildObserverTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider ageGroupProvider */
    public function test_age_group_is_auto_calculated(int $age, string $expectedGroup): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => $age]);

        $this->assertEquals($expectedGroup, $child->age_group);
    }

    public static function ageGroupProvider(): array
    {
        return [
            'age 5'  => [5,  '5-7'],
            'age 7'  => [7,  '5-7'],
            'age 8'  => [8,  '8-11'],
            'age 11' => [11, '8-11'],
            'age 12' => [12, '12-14'],
            'age 14' => [14, '12-14'],
        ];
    }

    public function test_age_group_updates_when_age_changes(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create(['age' => 7]);
        $this->assertEquals('5-7', $child->age_group);

        $child->update(['age' => 12]);
        $this->assertEquals('12-14', $child->fresh()->age_group);
    }
}
```

```php
<?php
// tests/Unit/Models/ChildTest.php
namespace Tests\Unit\Models;

use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChildTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_belongs_to_school(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();

        $this->assertEquals($school->id, $child->school->id);
    }

    public function test_child_status_defaults_to_ok(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();

        $this->assertEquals('ok', $child->status);
        $this->assertNull($child->score_enfant);
    }
}
```

- [ ] **Step 2 : Vérifier que les tests échouent**

```bash
php artisan test --filter="ChildObserverTest|ChildTest"
```
Attendu : **FAIL** — "Class Child not found"

- [ ] **Step 3 : Créer l'observer**

```php
<?php
// app/Observers/ChildObserver.php
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
```

- [ ] **Step 4 : Créer le modèle Child**

```php
<?php
// app/Models/Child.php
namespace App\Models;

use App\Observers\ChildObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[ObservedBy(ChildObserver::class)]
class Child extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'school_id', 'name', 'email', 'password',
        'age', 'age_group', 'classe',
        'score_enfant', 'status', 'last_session_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'        => 'hashed',
            'score_enfant'    => 'float',
            'last_session_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
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
```

- [ ] **Step 5 : Créer la factory**

```php
<?php
// database/factories/ChildFactory.php
namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ChildFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'     => $this->faker->firstName(),
            'email'    => $this->faker->unique()->safeEmail(),
            'password' => 'password',
            'age'      => $this->faker->numberBetween(5, 14),
            // age_group est calculé automatiquement par ChildObserver::creating()
            'classe'   => $this->faker->randomElement(['CE1','CE2','CM1','CM2','5ème','6ème']),
        ];
    }
}
```

- [ ] **Step 6 : Enregistrer l'observer dans AppServiceProvider**

Ouvrir `app/Providers/AppServiceProvider.php` et modifier la méthode `boot` :

```php
<?php
// app/Providers/AppServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Observer enregistré via attribut #[ObservedBy] sur Child
        // Rien à faire ici pour Laravel 11+
    }
}
```

> Note : Laravel 11 supporte l'attribut `#[ObservedBy]` directement sur le modèle. Aucune inscription manuelle nécessaire.

- [ ] **Step 7 : Configurer le guard `child`**

Modifier `config/auth.php` — ajouter dans `guards` et `providers` :

```php
'guards' => [
    'web' => [
        'driver'   => 'session',
        'provider' => 'users',
    ],
    'child' => [                    // ← ajouter
        'driver'   => 'session',
        'provider' => 'children',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model'  => env('AUTH_MODEL', User::class),
    ],
    'children' => [                 // ← ajouter
        'driver' => 'eloquent',
        'model'  => App\Models\Child::class,
    ],
],

'passwords' => [
    'users' => [
        'provider' => 'users',
        'table'    => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
        'expire'   => 60,
        'throttle' => 60,
    ],
    // Mot de passe enfant reset = feature post-MVP, pas de config ici
],
```

- [ ] **Step 8 : Vérifier que tous les tests passent**

```bash
php artisan test --filter="ChildObserverTest|ChildTest"
```
Attendu : **8 PASS**

- [ ] **Step 9 : Commit**

```bash
git add app/Models/Child.php app/Observers/ChildObserver.php database/factories/ChildFactory.php config/auth.php tests/Unit/Observers/ChildObserverTest.php tests/Unit/Models/ChildTest.php
git commit -m "feat(models): add Child model, ChildObserver (age_group auto), child auth guard"
```

---

## Task 8 — Modèles `ChatSession`, `Alert`, `AdminNote`

**Files:**
- Create: `app/Models/ChatSession.php`
- Create: `app/Models/Alert.php`
- Create: `app/Models/AdminNote.php`
- Modify: `app/Models/User.php`
- Create: `tests/Unit/Models/ChatSessionTest.php`

- [ ] **Step 1 : Créer le test**

```php
<?php
// tests/Unit/Models/ChatSessionTest.php
namespace Tests\Unit\Models;

use App\Models\Alert;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_session_has_null_ended_at(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();

        $session = ChatSession::create([
            'child_id'  => $child->id,
            'school_id' => $school->id,
            'started_at' => now(),
        ]);

        $this->assertNull($session->ended_at);
        $this->assertNull($session->zone);
    }

    public function test_session_has_one_alert(): void
    {
        $school = School::factory()->create();
        $child = Child::factory()->for($school)->create();

        $session = ChatSession::create([
            'child_id'   => $child->id,
            'school_id'  => $school->id,
            'started_at' => now(),
            'ended_at'   => now(),
            'zone'       => 'red',
        ]);

        Alert::create([
            'session_id' => $session->id,
            'child_id'   => $child->id,
            'school_id'  => $school->id,
            'type'       => 'detresse',
            'level'      => 'critical',
        ]);

        $this->assertInstanceOf(Alert::class, $session->alert);
        $this->assertEquals('critical', $session->alert->level);
    }
}
```

- [ ] **Step 2 : Vérifier que le test échoue**

```bash
php artisan test --filter=ChatSessionTest
```
Attendu : **FAIL** — "Class ChatSession not found"

- [ ] **Step 3 : Créer les trois modèles**

```php
<?php
// app/Models/ChatSession.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatSession extends Model
{
    protected $fillable = [
        'child_id', 'school_id', 'zone',
        'ai_summary', 'low_confidence',
        'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'low_confidence' => 'boolean',
            'started_at'     => 'datetime',
            'ended_at'       => 'datetime',
        ];
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function alert(): HasOne
    {
        return $this->hasOne(Alert::class, 'session_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
```

```php
<?php
// app/Models/Alert.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Alert extends Model
{
    protected $fillable = [
        'session_id', 'child_id', 'school_id',
        'type', 'level', 'status', 'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(AdminNote::class);
    }
}
```

```php
<?php
// app/Models/AdminNote.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminNote extends Model
{
    protected $fillable = ['alert_id', 'user_id', 'content'];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4 : Mettre à jour le modèle User**

Ajouter les relations dans `app/Models/User.php` en conservant les attributs PHP 8.1 existants :

```php
<?php
// app/Models/User.php
namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_user')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function adminNotes(): HasMany
    {
        return $this->hasMany(AdminNote::class);
    }
}
```

- [ ] **Step 5 : Vérifier que tous les tests passent**

```bash
php artisan test --filter=ChatSessionTest
```
Attendu : **2 PASS**

- [ ] **Step 6 : Lancer tous les tests**

```bash
php artisan test
```
Attendu : tous les tests passent (aucune régression)

- [ ] **Step 7 : Commit**

```bash
git add app/Models/ChatSession.php app/Models/Alert.php app/Models/AdminNote.php app/Models/User.php tests/Unit/Models/ChatSessionTest.php
git commit -m "feat(models): add ChatSession, Alert, AdminNote models + User relations"
```

---

## Task 9 — Job stub `ProcessSessionClosure`

**Files:**
- Create: `app/Jobs/ProcessSessionClosure.php`

- [ ] **Step 1 : Créer le job**

```php
<?php
// app/Jobs/ProcessSessionClosure.php
namespace App\Jobs;

use App\Models\ChatSession;
use App\Models\Child;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSessionClosure implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const ZONE_SCORES = [
        'green'  => 100,
        'yellow' => 70,
        'orange' => 35,
        'red'    => 0,
    ];

    public function __construct(private readonly ChatSession $session) {}

    public function handle(): void
    {
        $child = $this->session->child;

        $scoreEnfant = $this->calculateScoreEnfant($child);

        $child->score_enfant    = $scoreEnfant;
        $child->last_session_at = $this->session->ended_at;

        if ($scoreEnfant !== null) {
            $child->status = $scoreEnfant < 50 ? 'a_suivre' : 'ok';
        }

        $child->save();
    }

    private function calculateScoreEnfant(Child $child): ?float
    {
        $sessions = $child->chatSessions()
            ->whereNotNull('ended_at')
            ->whereNotNull('zone')
            ->where('ended_at', '>=', now()->subDays(7))
            ->get();

        if ($sessions->isEmpty()) {
            return null;
        }

        $total = $sessions->sum(fn ($s) => self::ZONE_SCORES[$s->zone] ?? 0);

        return $total / $sessions->count();
    }
}
```

- [ ] **Step 2 : Vérifier la syntaxe**

```bash
php artisan about
```
Attendu : pas d'erreur PHP

- [ ] **Step 3 : Commit**

```bash
git add app/Jobs/ProcessSessionClosure.php
git commit -m "feat(jobs): add ProcessSessionClosure job stub (score_enfant + status)"
```

---

## Task 10 — Seeder de démo

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1 : Mettre à jour le seeder**

```php
<?php
// database/seeders/DatabaseSeeder.php
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
        // École de démo
        $school = School::create([
            'name'  => 'École Agdal',
            'city'  => 'Rabat',
            'email' => 'contact@agdal.carenest.ma',
        ]);

        // Admin directeur
        $director = User::create([
            'name'     => 'Mme Benali',
            'email'    => 'admin@carenest.ma',
            'password' => Hash::make('admin123'),
        ]);
        $school->users()->attach($director->id, ['role' => 'director']);

        // Enfants de démo
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
```

- [ ] **Step 2 : Exécuter le seeder (base de données de dev)**

```bash
php artisan migrate:fresh --seed
```
Attendu : "Seeding: DatabaseSeeder" sans erreur

- [ ] **Step 3 : Vérifier les données**

```bash
php artisan tinker
>>> App\Models\School::with('users','children')->first()
```
Attendu : 1 école · 1 admin · 5 enfants avec `age_group` correct

- [ ] **Step 4 : Lancer tous les tests une dernière fois**

```bash
php artisan test
```
Attendu : **tous les tests passent**

- [ ] **Step 5 : Commit final**

```bash
git add database/seeders/DatabaseSeeder.php
git commit -m "feat(seed): add demo school, admin, and 5 children for CareNest dev"
```

---

## Résumé des fichiers créés / modifiés

| Fichier | Statut |
|---|---|
| `database/migrations/2026_04_17_000001_create_schools_table.php` | ✅ Créé |
| `database/migrations/2026_04_17_000002_create_school_user_table.php` | ✅ Créé |
| `database/migrations/2026_04_17_000003_create_school_settings_table.php` | ✅ Créé |
| `database/migrations/2026_04_17_000004_create_children_table.php` | ✅ Créé |
| `database/migrations/2026_04_17_000005_create_chat_sessions_table.php` | ✅ Créé |
| `database/migrations/2026_04_17_000006_create_alerts_table.php` | ✅ Créé |
| `database/migrations/2026_04_17_000007_create_admin_notes_table.php` | ✅ Créé |
| `app/Models/School.php` | ✅ Créé |
| `app/Models/SchoolSetting.php` | ✅ Créé |
| `app/Models/Child.php` | ✅ Créé |
| `app/Models/ChatSession.php` | ✅ Créé |
| `app/Models/Alert.php` | ✅ Créé |
| `app/Models/AdminNote.php` | ✅ Créé |
| `app/Models/User.php` | ✅ Modifié |
| `app/Observers/ChildObserver.php` | ✅ Créé |
| `app/Jobs/ProcessSessionClosure.php` | ✅ Créé |
| `database/factories/SchoolFactory.php` | ✅ Créé |
| `database/factories/ChildFactory.php` | ✅ Créé |
| `database/seeders/DatabaseSeeder.php` | ✅ Modifié |
| `config/auth.php` | ✅ Modifié |
| `tests/Feature/Schema/MigrationsTest.php` | ✅ Créé |
| `tests/Unit/Observers/ChildObserverTest.php` | ✅ Créé |
| `tests/Unit/Models/SchoolTest.php` | ✅ Créé |
| `tests/Unit/Models/ChildTest.php` | ✅ Créé |
| `tests/Unit/Models/ChatSessionTest.php` | ✅ Créé |
