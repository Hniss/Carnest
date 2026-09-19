<?php
namespace Database\Seeders;

use App\Models\Alert;
use App\Models\AlertAction;
use App\Models\AlertLifecycle;
use App\Models\ChatSession;
use App\Models\Child;
use App\Models\FollowUp;
use App\Models\ParentChild;
use App\Models\ParentMessage;
use App\Models\ParentSynthesis;
use App\Models\ParentThread;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Données de démonstration — entièrement FICTIVES (mention « Démo » partout).
 * D10 (v3) : aucun mot de passe dans le dépôt (DEMO_ADMIN_PASSWORD / DEMO_CHILD_PASSWORD ;
 * DEMO_STAFF_PASSWORD facultatif pour les comptes référent et parent).
 * Lot 1 : référent, parent consentant, alertes traitées (cycle de vie, actions, suivi),
 * fil de messagerie et synthèse parent.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = $this->demoPassword('DEMO_ADMIN_PASSWORD', 'administrateur');
        $childPassword = $this->demoPassword('DEMO_CHILD_PASSWORD', 'élèves');
        $staffPassword = env('DEMO_STAFF_PASSWORD') ?: $adminPassword;

        $school = School::create([
            'name'  => 'École Agdal (Démo)',
            'city'  => 'Rabat',
            'email' => 'contact@agdal.carenest.ma',
        ]);
        $school->setting()->create(['school_id' => $school->id]);

        $director = User::create([
            'name'     => 'Mme Benali (Démo)',
            'email'    => 'admin@carenest.ma',
            'password' => Hash::make($adminPassword),
            'role'     => 'admin',
            'email_verified_at' => now(),
        ]);
        $school->users()->attach($director->id, ['role' => 'director']);

        $referent = User::create([
            'name'     => 'M. Tazi (Démo)',
            'email'    => 'referent@carenest.ma',
            'password' => Hash::make($staffPassword),
            'role'     => 'referent',
            'phone'    => '+212600000010',
            'email_verified_at' => now(),
        ]);
        $school->users()->attach($referent->id, ['role' => 'referent']);

        $children = [
            ['name' => 'Yassine Démo', 'email' => 'yassine@carenest.ma', 'age' => 10, 'classe' => 'CM2',  'gender' => 'm'],
            ['name' => 'Amina Démo',   'email' => 'amina@carenest.ma',   'age' => 8,  'classe' => 'CE2',  'gender' => 'f'],
            ['name' => 'Omar Démo',    'email' => 'omar@carenest.ma',    'age' => 11, 'classe' => '5ème', 'gender' => 'm'],
            ['name' => 'Sara Démo',    'email' => 'sara@carenest.ma',    'age' => 9,  'classe' => 'CM1',  'gender' => 'f'],
            ['name' => 'Karim Démo',   'email' => 'karim@carenest.ma',   'age' => 12, 'classe' => '6ème', 'gender' => 'm'],
        ];

        $created = [];
        foreach ($children as $data) {
            $created[$data['email']] = Child::create([
                ...$data,
                'birth_date' => now()->subYears($data['age'])->subMonths(3)->toDateString(),
                'school_id'  => $school->id,
                'password'   => Hash::make($childPassword),
            ]);
        }
        $yassine = $created['yassine@carenest.ma'];
        $amina   = $created['amina@carenest.ma'];
        $omar    = $created['omar@carenest.ma'];

        // ── Parent consentant (au nom de l'école, loi 09-08) ─────────────────
        $parent = User::create([
            'name'     => 'Parent Démo',
            'email'    => 'parent@carenest.ma',
            'password' => Hash::make($staffPassword),
            'role'     => 'parent',
            'phone'    => '+212600000020',
            'email_verified_at' => now(),
        ]);
        $parent->children()->attach($yassine->id, [
            'relation'          => 'mere',
            'consent_given'     => true,
            'consent_text'      => ParentChild::consentText($school->name),
            'consent_timestamp' => now()->subDays(20),
            'consent_ip'        => '127.0.0.1',
        ]);
        $parent->children()->attach($amina->id, [
            'relation'          => 'mere',
            'consent_given'     => true,
            'consent_text'      => ParentChild::consentText($school->name),
            'consent_timestamp' => now()->subDays(20),
            'consent_ip'        => '127.0.0.1',
        ]);

        // ── Sessions et alertes de types variés ──────────────────────────────
        $session = function (Child $child, string $zone, int $daysAgo, bool $lowConfidence = false) use ($school) {
            return ChatSession::create([
                'child_id' => $child->id, 'school_id' => $school->id, 'zone' => $zone,
                'low_confidence' => $lowConfidence,
                'ai_summary' => 'Résumé de démonstration : échange calme, ' . ($zone === 'green' ? 'ressenti positif.' : 'quelques signes de difficulté évoqués.'),
                'started_at' => now()->subDays($daysAgo)->subMinutes(20), 'ended_at' => now()->subDays($daysAgo),
                'last_activity_at' => now()->subDays($daysAgo), 'prompt_version' => 'v3.0', 'model' => 'demo',
            ]);
        };
        $alert = function (Child $child, ChatSession $s, string $type, string $level, array $signals, int $daysAgo) use ($school) {
            $a = Alert::create([
                'session_id' => $s->id, 'child_id' => $child->id, 'school_id' => $school->id,
                'type' => $type, 'level' => $level, 'summary' => $s->ai_summary, 'signals' => $signals,
                'prompt_version' => 'v3.0', 'model' => 'demo',
            ]);
            $a->forceFill(['created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)])->save();
            return $a;
        };

        $session($yassine, 'green', 15);
        $session($amina, 'green', 9, true);
        $session($omar, 'yellow', 6);

        // 1) Alerte harcèlement traitée de bout en bout (qualification, action, suivi, synthèse) — Yassine.
        $sY = $session($yassine, 'orange', 12);
        $aY = $alert($yassine, $sY, 'harcelement', 'high', ['moqueries répétées', 'peur de la récréation'], 12);
        $aY->update(['status' => 'read']);
        AlertLifecycle::create(['alert_id' => $aY->id, 'status' => 'qualifie', 'qualification' => 'pertinent', 'changed_by' => $referent->id, 'changed_at' => now()->subDays(11)]);
        AlertAction::create(['alert_id' => $aY->id, 'action_type' => 'entretien', 'performed_by' => $referent->id, 'performed_at' => now()->subDays(10), 'notes' => 'Entretien de démonstration : élève rassuré, situation à suivre.']);
        AlertLifecycle::create(['alert_id' => $aY->id, 'status' => 'en_traitement', 'changed_by' => $referent->id, 'changed_at' => now()->subDays(10)]);
        FollowUp::create(['child_id' => $yassine->id, 'alert_id' => $aY->id, 'status' => 'accompagnement', 'next_review_date' => now()->addDays(4)->toDateString(), 'objectif' => 'Vérifier le climat en récréation', 'responsable_id' => $referent->id]);
        AlertLifecycle::create(['alert_id' => $aY->id, 'status' => 'suivi', 'changed_by' => $referent->id, 'changed_at' => now()->subDays(9)]);

        // 2) Alerte isolement à qualifier — Omar (file d'attente).
        $sO = $session($omar, 'orange', 1);
        $alert($omar, $sO, 'isolement', 'moderate', ['reste seul en récréation', 'peu d\'échanges'], 1);

        // 3) Alerte stress à confirmer — Amina.
        $sA = $session($amina, 'orange', 3);
        $aA = $alert($amina, $sA, 'stress', 'moderate', ['inquiétude avant les contrôles'], 3);
        $aA->update(['adjudication' => 'a_confirmer']);

        // 4) Alerte détresse clôturée — Sara.
        $sara = $created['sara@carenest.ma'];
        $sS = $session($sara, 'orange', 25);
        $aS = $alert($sara, $sS, 'detresse', 'moderate', ['tristesse exprimée'], 25);
        AlertLifecycle::create(['alert_id' => $aS->id, 'status' => 'qualifie', 'qualification' => 'a_surveiller', 'changed_by' => $referent->id, 'changed_at' => now()->subDays(24)]);
        AlertLifecycle::create(['alert_id' => $aS->id, 'status' => 'cloture', 'changed_by' => $referent->id, 'changed_at' => now()->subDays(20)]);
        $aS->update(['status' => 'resolved']);

        // Score et dernière session de chaque élève (normalement posés par ProcessSessionClosure).
        foreach (Child::all() as $c) {
            $last = ChatSession::where('child_id', $c->id)->whereNotNull('ended_at')->latest('ended_at')->first();
            if ($last) {
                $c->forceFill(['last_session_at' => $last->ended_at, 'score_enfant' => \App\Enums\ZoneScore::fromZone($last->zone)])->save();
            }
        }

        // ── Fil de messagerie parent ↔ référent (Yassine) ────────────────────
        $thread = ParentThread::create([
            'school_id' => $school->id, 'child_id' => $yassine->id, 'parent_id' => $parent->id,
            'referent_id' => $referent->id, 'subject' => 'Point sur la semaine (démo)', 'important' => true,
        ]);
        ParentMessage::create(['thread_id' => $thread->id, 'sender_id' => $referent->id, 'sender_role' => 'referent', 'body' => 'Bonjour, je vous propose un échange cette semaine au sujet de Yassine.', 'sent_at' => now()->subDays(9), 'read_at' => now()->subDays(8)]);
        ParentMessage::create(['thread_id' => $thread->id, 'sender_id' => $parent->id, 'sender_role' => 'parent', 'body' => 'Bonjour, merci. Jeudi après 17 h me convient.', 'sent_at' => now()->subDays(8), 'read_at' => now()->subDays(8)]);

        // ── Synthèse envoyée au parent (formulation imposée) ─────────────────
        ParentSynthesis::create([
            'child_id' => $yassine->id, 'alert_id' => $aY->id, 'parent_id' => $parent->id, 'sent_by' => $referent->id,
            'identified'      => ParentSynthesis::DEFAULT_IDENTIFIED,
            'school_did'      => str_replace('{date}', now()->subDays(10)->format('d/m/Y'), ParentSynthesis::DEFAULT_SCHOOL_DID),
            'school_proposes' => str_replace('{période}', 'autour du ' . now()->addDays(4)->format('d/m/Y'), ParentSynthesis::DEFAULT_SCHOOL_PROPOSES),
            'parent_can'      => ParentSynthesis::DEFAULT_PARENT_CAN,
            'sent_at' => now()->subDays(9),
        ]);
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
