<?php
namespace App\Livewire\Admin;

use App\Models\Alert;
use App\Models\Child;
use App\Models\School;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public string $alertFilter = 'all';

    /**
     * D10 (v3) — cloisonnement multi-école : l'alerte doit appartenir à une
     * école de l'utilisateur connecté (même patron que ChildProfile), sinon 403.
     */
    public function resolveAlert(int $alertId): void
    {
        $alert = Alert::find($alertId);
        abort_unless($alert, 404);

        $userSchoolIds = Auth::user()->schools()->pluck('schools.id');
        abort_unless($userSchoolIds->contains($alert->school_id), 403);

        $alert->update(['status' => 'resolved']);
    }

    public function render()
    {
        $user = Auth::user();

        $school = $user->schools()->with('setting')->first();

        $children = $school
            ? Child::where('school_id', $school->id)->orderBy('status')->get()
            : collect();

        $climateScore = $children->whereNotNull('score_enfant')->avg('score_enfant');

        $alertsQuery = Alert::with('child')
            ->where('school_id', optional($school)->id);

        $alerts = match($this->alertFilter) {
            'unread'   => $alertsQuery->where('status', 'unread')->latest()->get(),
            'resolved' => $alertsQuery->where('status', 'resolved')->latest()->get(),
            default    => $alertsQuery->latest()->get(),
        };

        $unreadCount = Alert::where('school_id', optional($school)->id)
            ->where('status', 'unread')->count();

        return view('livewire.admin.dashboard', compact(
            'school', 'children', 'climateScore', 'alerts', 'unreadCount'
        ));
    }
}
