<?php

namespace Tests\Feature\Referent;

use App\Livewire\Referent\Delegation;
use App\Livewire\Referent\Messages;
use App\Models\Child;
use App\Models\ParentThread;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesRoles;
use Tests\TestCase;

class MessagesAndDelegationTest extends TestCase
{
    use RefreshDatabase, CreatesRoles;

    public function test_referent_opens_thread_sends_message_and_parent_is_notified(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $child  = Child::factory()->for($school)->create();
        $parent = $this->makeParent($child, true);
        $this->actingAs($ref);

        Livewire::test(Messages::class)
            ->set('newParentId', $parent->id)->set('newChildId', $child->id)
            ->set('newSubject', 'Point de la semaine')->set('newBody', 'Bonjour, pouvons-nous échanger ?')
            ->call('createThread')->assertHasNoErrors();

        $thread = ParentThread::first();
        $this->assertSame($ref->id, $thread->referent_id);
        $this->assertDatabaseHas('parent_messages', ['thread_id' => $thread->id, 'sender_role' => 'referent']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $parent->id, 'type' => 'message']);

        Livewire::test(Messages::class)->call('toggleImportant', $thread->id);
        $this->assertTrue($thread->fresh()->important);
    }

    public function test_referent_cannot_open_thread_of_another_school(): void
    {
        $school = School::factory()->create();
        $ref    = $this->makeReferent($school);
        $other  = School::factory()->create();
        $child  = Child::factory()->for($other)->create();
        $parent = $this->makeParent($child, true);
        $thread = ParentThread::create(['school_id' => $other->id, 'child_id' => $child->id, 'parent_id' => $parent->id, 'referent_id' => null, 'subject' => 'Ailleurs']);
        $this->actingAs($ref);

        Livewire::test(Messages::class)->call('selectThread', $thread->id)->assertForbidden();
    }

    public function test_delegation_lifecycle_is_audited_and_delegate_notified_on_activation(): void
    {
        $school   = School::factory()->create();
        $ref      = $this->makeReferent($school);
        $delegate = User::factory()->create(['role' => 'admin']);
        School::factory()->create()->users()->attach($delegate->id, ['role' => 'staff']);
        $this->actingAs($ref);

        $c = Livewire::test(Delegation::class)
            ->set('delegateId', $delegate->id)
            ->set('startDate', now()->toDateString())->set('endDate', now()->addDays(5)->toDateString())
            ->call('create')->assertHasNoErrors();
        $this->assertDatabaseHas('referent_delegations', ['school_id' => $school->id, 'referent_id' => $ref->id, 'delegate_id' => $delegate->id, 'activated_at' => null]);
        $id = $school->delegations()->first()->id;

        $c->call('activate', $id);
        $this->assertNotNull($school->delegations()->first()->activated_at);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $delegate->id, 'type' => 'delegation']);
        $this->assertNotNull($delegate->delegationFor($school));

        $c->call('revoke', $id);
        $this->assertNotNull($school->delegations()->first()->revoked_at);
        $this->assertNull($delegate->delegationFor($school));

        foreach (['referent.delegation.create', 'referent.delegation.activate', 'referent.delegation.revoke'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['actor_id' => $ref->id, 'action' => $action]);
        }
    }
}
