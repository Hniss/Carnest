<?php
namespace Tests\Unit\Services;

use App\Services\AlertLevelResolver;
use PHPUnit\Framework\TestCase;

class AlertLevelResolverTest extends TestCase
{
    private AlertLevelResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AlertLevelResolver();
    }

    public function test_red_zone_is_critical(): void
    {
        $level = $this->resolver->resolve('detresse', 'red', ['je veux disparaître']);
        $this->assertSame('critical', $level);
    }

    public function test_yellow_zone_is_low(): void
    {
        $level = $this->resolver->resolve('stress', 'yellow', ["j'ai un peu peur de l'évaluation"]);
        $this->assertSame('low', $level);
    }

    public function test_orange_isolated_recent_is_moderate(): void
    {
        $level = $this->resolver->resolve('isolement', 'orange', ["personne ne joue avec moi aujourd'hui"]);
        $this->assertSame('moderate', $level);
    }

    public function test_orange_harassment_with_retaliation_fear_is_high(): void
    {
        $messages = [
            "des élèves se moquent de moi tous les jours",
            "j'ai peur d'en parler à la maîtresse parce qu'ils vont encore plus m'embêter",
            "ça se passe devant tout le monde",
        ];
        $level = $this->resolver->resolve('harcelement', 'orange', $messages);
        $this->assertSame('high', $level);
    }

    public function test_orange_family_fear_is_high(): void
    {
        $messages = [
            "j'ai peur de rentrer à la maison",
            "mes parents vont crier à cause de ma note",
        ];
        $level = $this->resolver->resolve('detresse', 'orange', $messages);
        $this->assertSame('high', $level);
    }

    public function test_orange_long_isolation_is_high(): void
    {
        $messages = [
            "je suis tout seul à la cantine",
            "je mange toujours seul depuis deux semaines",
        ];
        $level = $this->resolver->resolve('isolement', 'orange', $messages);
        $this->assertSame('high', $level);
    }

    public function test_orange_distress_with_repetition_is_high(): void
    {
        $messages = [
            "je suis triste presque tous les jours",
            "ça dure depuis longtemps",
        ];
        $level = $this->resolver->resolve('detresse', 'orange', $messages);
        $this->assertSame('high', $level);
    }

    /**
     * P10 (V4) — humiliation par un adulte (alert_type=humiliation_adulte) en zone
     * orange doit produire un niveau 'high' d'office, même sans répétition explicite.
     */
    public function test_humiliation_adulte_orange_is_high(): void
    {
        $messages = [
            "ma maîtresse m'insulte et me traite d'idiote",
        ];
        $level = $this->resolver->resolve('humiliation_adulte', 'orange', $messages);
        $this->assertSame('high', $level);
    }

    /**
     * P10 (V4) — humiliation par un adulte avec mention de répétition reste high.
     */
    public function test_humiliation_adulte_with_repetition_is_high(): void
    {
        $messages = [
            "ma maîtresse m'insulte tous les jours et me dit que je suis nulle",
        ];
        $level = $this->resolver->resolve('humiliation_adulte', 'orange', $messages);
        $this->assertSame('high', $level);
    }
}
