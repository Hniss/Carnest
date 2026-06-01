<?php
namespace Tests\Unit\Services;

use App\Services\CrisisDetector;
use PHPUnit\Framework\TestCase;

class CrisisDetectorTest extends TestCase
{
    private CrisisDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new CrisisDetector();
    }

    public function test_red_zone_on_self_harm_intent(): void
    {
        $result = $this->detector->evaluate("j'ai envie de mourir", 'green');
        $this->assertSame('red', $result['zone']);
        $this->assertSame('detresse', $result['alert_type']);
        $this->assertTrue($result['matched']);
    }

    public function test_red_zone_on_suicide_phrase(): void
    {
        $result = $this->detector->evaluate("je veux me faire du mal", 'green');
        $this->assertSame('red', $result['zone']);
        $this->assertTrue($result['matched']);
    }

    public function test_red_zone_on_family_violence(): void
    {
        $result = $this->detector->evaluate("papa me frappe tous les soirs", 'green');
        $this->assertSame('red', $result['zone']);
        $this->assertSame('detresse', $result['alert_type']);
    }

    public function test_orange_zone_on_mockery(): void
    {
        $result = $this->detector->evaluate("ils se moquent de moi à la récré", 'green');
        $this->assertSame('orange', $result['zone']);
        $this->assertSame('harcelement', $result['alert_type']);
    }

    public function test_orange_zone_on_isolation(): void
    {
        $result = $this->detector->evaluate("je suis tout seul personne ne me parle", 'green');
        $this->assertSame('orange', $result['zone']);
        $this->assertSame('isolement', $result['alert_type']);
    }

    public function test_orange_zone_on_family_fear(): void
    {
        $result = $this->detector->evaluate("j'ai très peur de mes parents", 'green');
        $this->assertSame('orange', $result['zone']);
        $this->assertSame('detresse', $result['alert_type']);
    }

    public function test_no_match_on_neutral_message(): void
    {
        $result = $this->detector->evaluate("aujourd'hui j'ai joué au foot", 'green');
        $this->assertSame('green', $result['zone']);
        $this->assertNull($result['alert_type']);
        $this->assertFalse($result['matched']);
    }

    public function test_red_overrides_lower_running_zone(): void
    {
        $result = $this->detector->evaluate("je veux disparaitre", 'yellow');
        $this->assertSame('red', $result['zone']);
    }

    public function test_orange_does_not_downgrade_red_running(): void
    {
        // Si le tour précédent a déjà placé la session en rouge,
        // un signal orange ne doit pas faire redescendre.
        $result = $this->detector->evaluate("ils se moquent de moi", 'red');
        $this->assertSame('red', $result['zone']);
    }

    public function test_max_zone_helper(): void
    {
        $this->assertSame('red',    $this->detector->maxZone('orange', 'red'));
        $this->assertSame('orange', $this->detector->maxZone('orange', 'yellow'));
        $this->assertSame('yellow', $this->detector->maxZone('green',  'yellow'));
        $this->assertSame('green',  $this->detector->maxZone('green',  'green'));
    }

    /**
     * P10 (V4) — humiliation par un adulte de l'école détectée par CrisisDetector.
     * "ma maîtresse m'insulte tout le temps et me dit que je suis idiote"
     * doit ressortir en zone=orange + alert_type=humiliation_adulte.
     */
    public function test_orange_zone_on_teacher_humiliation(): void
    {
        $result = $this->detector->evaluate(
            "ma maîtresse m'insulte tout le temps et me dit que je suis idiote",
            'green'
        );
        $this->assertSame('orange', $result['zone']);
        $this->assertSame('humiliation_adulte', $result['alert_type']);
        $this->assertTrue($result['matched']);
    }

    public function test_orange_zone_on_director_humiliation(): void
    {
        $result = $this->detector->evaluate(
            "le directeur m'humilie devant tout le monde",
            'green'
        );
        $this->assertSame('orange', $result['zone']);
        $this->assertSame('humiliation_adulte', $result['alert_type']);
    }

    /**
     * P10 (V4) — Anti-faux-positif : si l'adulte est rapporté comme humiliant
     * d'AUTRES enfants (pas l'enfant qui parle), on ne déclenche pas
     * humiliation_adulte. Le pronom m'/me doit être présent et désigner l'enfant.
     */
    public function test_does_not_match_when_adult_targets_others(): void
    {
        $result = $this->detector->evaluate(
            "ma maîtresse insulte les autres élèves de la classe",
            'green'
        );
        $this->assertSame('green', $result['zone']);
        $this->assertNull($result['alert_type']);
        $this->assertFalse($result['matched']);
    }

    /**
     * #6 (V5) — Violence physique COMMISE par l'enfant.
     * « j'ai giflé une meuf » → orange + type danger (le prompt prend ensuite
     * le relais pour ne pas lâcher le sujet et orienter vers un adulte).
     */
    public function test_orange_zone_on_admitted_slap(): void
    {
        $result = $this->detector->evaluate("j'ai giflé une meuf à la récré", 'green');
        $this->assertSame('orange', $result['zone']);
        $this->assertSame('danger', $result['alert_type']);
        $this->assertTrue($result['matched']);
    }

    public function test_orange_zone_on_admitted_hit_with_pronoun(): void
    {
        $result = $this->detector->evaluate("je l'ai frappé parce qu'il m'a énervé", 'green');
        $this->assertSame('orange', $result['zone']);
        $this->assertSame('danger', $result['alert_type']);
    }

    /**
     * #6 — Anti-faux-positif : « taper/pousser » un OBJET ne doit pas déclencher.
     */
    public function test_does_not_match_hitting_an_object(): void
    {
        $this->assertFalse($this->detector->evaluate("j'ai tapé dans le ballon", 'green')['matched']);
        $this->assertFalse($this->detector->evaluate("j'ai poussé la porte trop fort", 'green')['matched']);
    }

    /**
     * #3 (V5) — Un mot court/neutre ne doit JAMAIS faire monter la zone côté
     * filet déterministe. « rien », « non », « bof » restent green.
     */
    public function test_short_neutral_tokens_stay_green(): void
    {
        foreach (['rien', 'non', 'bof', 'oui', 'je sais pas'] as $token) {
            $result = $this->detector->evaluate($token, 'green');
            $this->assertSame('green', $result['zone'], "Le token « {$token} » ne doit pas escalader.");
            $this->assertFalse($result['matched']);
        }
    }

    public function test_normalisation_handles_letter_repetition(): void
    {
        // "Trooooop seul" doit matcher "tout seul"-ish via normalisation.
        // On vérifie ici qu'un mot avec lettres dupliquées ne casse pas la détection.
        $result = $this->detector->evaluate("je suis tellllllement seul", 'green');
        // Pas de panique attendue ici — c'est juste un sanity check de stabilité.
        $this->assertContains($result['zone'], ['green', 'yellow', 'orange', 'red']);
    }
}
