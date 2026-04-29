<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'CareNest') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-stone-50 text-stone-900 antialiased">

<div class="min-h-screen flex">

    {{-- Sidebar --}}
    <aside class="hidden lg:flex w-[248px] shrink-0 flex-col bg-white border-r border-stone-200">
        {{-- Brand --}}
        <div class="px-5 pt-6 pb-5">
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2.5">
                <span class="w-8 h-8 rounded-lg bg-brand-700 text-white flex items-center justify-center shadow-sm">
                    <x-icon name="leaf" size="18" />
                </span>
                <span class="font-display font-extrabold text-lg tracking-tight text-stone-900">CareNest</span>
            </a>
        </div>

        {{-- Nav --}}
        <nav class="flex-1 px-3 space-y-0.5">
            <div class="eyebrow px-3 pt-4 pb-2">Pilotage</div>
            <a href="{{ route('dashboard') }}"
               class="nav-item {{ request()->routeIs('dashboard') ? 'nav-item-active' : '' }}">
                <x-icon name="dashboard" class="nav-icon" />
                Tableau de bord
            </a>
            <a href="{{ route('dashboard') }}#students" class="nav-item">
                <x-icon name="users" class="nav-icon" />
                Élèves
            </a>
            <a href="{{ route('dashboard') }}#alerts" class="nav-item">
                <x-icon name="bell" class="nav-icon" />
                Alertes
            </a>

            <div class="eyebrow px-3 pt-6 pb-2">Paramètres</div>
            <a href="#" class="nav-item">
                <x-icon name="settings" class="nav-icon" />
                Établissement
            </a>
        </nav>

        {{-- User card --}}
        <div class="p-3 border-t border-stone-100">
            <div class="flex items-center gap-3 p-2 rounded-lg">
                <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-900 flex items-center justify-center font-semibold text-sm shrink-0">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-stone-900 truncate">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-stone-500 truncate">{{ auth()->user()->email }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="p-1.5 rounded-md text-stone-400 hover:text-stone-900 hover:bg-stone-100 transition-colors"
                            aria-label="Déconnexion" title="Déconnexion">
                        <x-icon name="log-out" size="16" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Mobile top bar --}}
    <div class="lg:hidden fixed top-0 left-0 right-0 z-20 bg-white/95 backdrop-blur border-b border-stone-200 px-4 py-3 flex items-center justify-between">
        <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2">
            <span class="w-7 h-7 rounded-md bg-brand-700 text-white flex items-center justify-center">
                <x-icon name="leaf" size="16" />
            </span>
            <span class="font-display font-extrabold text-base">CareNest</span>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-ghost btn-sm">
                <x-icon name="log-out" size="14" />
                Quitter
            </button>
        </form>
    </div>

    {{-- Main --}}
    <main class="flex-1 min-w-0 pt-14 lg:pt-0">
        @if (isset($header))
            <header class="bg-white border-b border-stone-200">
                <div class="max-w-7xl mx-auto py-5 px-6 lg:px-10">{{ $header }}</div>
            </header>
        @endif
        <div class="max-w-7xl mx-auto px-6 lg:px-10 py-8 lg:py-10">
            {{ $slot }}
        </div>
    </main>

</div>

@livewireScripts
</body>
</html>
