{{-- Navigation par rôle (lot 1). Utilisé dans la barre latérale et le tiroir mobile. --}}
@php
    $user = auth()->user();
    $items = match (true) {
        $user->isReferent() => [
            ['group' => 'Pilotage'],
            ['label' => 'Vue d\'ensemble', 'icon' => 'dashboard',      'href' => route('referent.overview'),            'active' => request()->routeIs('referent.overview')],
            ['label' => 'Élèves',         'icon' => 'users',          'href' => route('referent.students'),            'active' => request()->routeIs('referent.students*')],
            ['label' => 'Alertes',        'icon' => 'bell',           'href' => route('referent.overview') . '#alerts', 'active' => request()->routeIs('referent.alerts.*')],
            ['label' => 'Messagerie',     'icon' => 'message-circle', 'href' => route('referent.messages'),            'active' => request()->routeIs('referent.messages')],
            ['group' => 'Organisation'],
            ['label' => 'Délégation',     'icon' => 'key',            'href' => route('referent.delegation'),          'active' => request()->routeIs('referent.delegation')],
        ],
        $user->isAdmin() && $user->delegatedSchools()->isNotEmpty() && ! request()->routeIs('dashboard', 'admin.*') => [
            ['group' => 'Délégation'],
            ['label' => 'Alertes déléguées', 'icon' => 'bell', 'href' => route('referent.overview'), 'active' => request()->routeIs('referent.*')],
            ['group' => 'Administration'],
            ['label' => 'Tableau de bord', 'icon' => 'dashboard', 'href' => route('dashboard'), 'active' => false],
        ],
        default => [
            ['group' => 'Pilotage'],
            ['label' => 'Tableau de bord', 'icon' => 'dashboard', 'href' => route('dashboard'),         'active' => request()->routeIs('dashboard')],
            ['label' => 'Élèves',          'icon' => 'users',     'href' => route('admin.students'),    'active' => request()->routeIs('admin.students', 'admin.children.show')],
            ['label' => 'Comptes école',   'icon' => 'user-plus', 'href' => route('admin.accounts'),    'active' => request()->routeIs('admin.accounts')],
            ['group' => 'Conformité'],
            ['label' => 'Journal d\'accès', 'icon' => 'shield-check', 'href' => route('admin.access-log'), 'active' => request()->routeIs('admin.access-log')],
            ['group' => 'Paramètres'],
            ['label' => 'Établissement',   'icon' => 'settings',  'href' => route('admin.settings'),    'active' => request()->routeIs('admin.settings')],
        ],
    };
    if ($user->isAdmin() && $user->delegatedSchools()->isNotEmpty() && request()->routeIs('dashboard', 'admin.*')) {
        $items[] = ['group' => 'Délégation'];
        $items[] = ['label' => 'Alertes déléguées', 'icon' => 'bell', 'href' => route('referent.overview'), 'active' => false];
    }
@endphp
@foreach ($items as $item)
    @if (isset($item['group']))
        <div class="eyebrow px-3 pt-4 pb-2">{{ $item['group'] }}</div>
    @else
        <a href="{{ $item['href'] }}" class="nav-item {{ $item['active'] ? 'nav-item-active' : '' }}" @if (! str_contains($item['href'], '#')) wire:navigate @endif>
            <x-icon :name="$item['icon']" class="nav-icon" />
            {{ $item['label'] }}
        </a>
    @endif
@endforeach
