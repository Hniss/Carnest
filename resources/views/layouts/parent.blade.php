<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'CareNest') }} — Espace parent</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}" />

    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none !important}</style>
</head>
<body class="bg-stone-50 text-stone-900 antialiased">
@php
    $items = [
        ['label' => 'Accueil',       'icon' => 'home',           'route' => 'parent.home',    'active' => request()->routeIs('parent.home')],
        ['label' => 'Journal',       'icon' => 'calendar',       'route' => 'parent.journal', 'active' => request()->routeIs('parent.journal')],
        ['label' => 'Messages',      'icon' => 'message-circle', 'route' => 'parent.messages','active' => request()->routeIs('parent.messages')],
        ['label' => 'Consentement',  'icon' => 'shield-check',   'route' => 'parent.consent', 'active' => request()->routeIs('parent.consent')],
        ['label' => 'Mes données',   'icon' => 'file-text',      'route' => 'parent.my-data', 'active' => request()->routeIs('parent.my-data')],
    ];
@endphp
<div class="min-h-dvh flex flex-col">

    {{-- Barre haute --}}
    <header class="sticky top-0 z-20 bg-white/95 backdrop-blur border-b border-stone-200">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
            <a href="{{ route('parent.home') }}" wire:navigate class="inline-flex items-center gap-2 shrink-0">
                <x-carenest-logo variant="full" class="h-7 w-auto shrink-0" />
                <span class="hidden sm:inline lg:hidden xl:inline whitespace-nowrap text-xs text-stone-500 border-l border-stone-200 pl-2">Espace parent</span>
            </a>
            <nav class="hidden lg:flex items-center gap-1" aria-label="Navigation">
                @foreach ($items as $it)
                    <a href="{{ route($it['route']) }}" wire:navigate class="nav-item py-1.5 whitespace-nowrap {{ $it['active'] ? 'nav-item-active' : '' }}">
                        <x-icon :name="$it['icon']" size="16" /> {{ $it['label'] }}
                    </a>
                @endforeach
            </nav>
            <div class="flex items-center gap-1">
                <livewire:shared.notification-bell />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-ghost btn-sm" aria-label="Déconnexion">
                        <x-icon name="log-out" size="14" />
                        <span class="hidden sm:inline">Quitter</span>
                    </button>
                </form>
            </div>
        </div>
    </header>

    <main class="flex-1 pb-24 lg:pb-10">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
            {{ $slot }}
        </div>
    </main>

    {{-- Navigation basse (mobile) --}}
    <nav class="lg:hidden fixed bottom-0 left-0 right-0 z-20 bg-white border-t border-stone-200" aria-label="Navigation mobile" style="padding-bottom: env(safe-area-inset-bottom)">
        <div class="grid grid-cols-5">
            @foreach ($items as $it)
                <a href="{{ route($it['route']) }}" wire:navigate
                   class="flex flex-col items-center justify-center gap-1 py-2.5 text-[11px] font-medium {{ $it['active'] ? 'text-brand-700' : 'text-stone-500' }}">
                    <x-icon :name="$it['icon']" size="20" />
                    {{ $it['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
</div>

@livewireScripts
</body>
</html>
