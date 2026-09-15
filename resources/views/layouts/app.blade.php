<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'CareNest') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}" />

    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Nunito:wght@600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none !important}</style>
</head>
<body class="bg-stone-50 text-stone-900 antialiased">

@php $homeUrl = auth()->user()->homePath(); @endphp
<div class="min-h-screen flex" x-data="{ drawer: false }" @keydown.escape.window="drawer = false">

    {{-- Sidebar (desktop) --}}
    <aside class="hidden lg:flex w-[248px] shrink-0 flex-col bg-white border-r border-stone-200">
        <div class="px-5 pt-6 pb-5 flex items-center justify-between">
            <a href="{{ $homeUrl }}" class="inline-flex items-center">
                <x-carenest-logo variant="full" class="h-9 w-auto" />
            </a>
            <livewire:shared.notification-bell />
        </div>

        <nav class="flex-1 px-3 space-y-0.5">
            @include('layouts.partials.nav-links')
        </nav>

        <div class="p-3 border-t border-stone-100">
            <div class="flex items-center gap-3 p-2 rounded-lg">
                <div class="w-9 h-9 rounded-full bg-brand-100 text-brand-900 flex items-center justify-center font-semibold text-sm shrink-0">
                    {{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium text-stone-900 truncate">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-stone-500 truncate">{{ \App\Models\User::roleLabel(auth()->user()->role) }}</div>
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
    <div class="lg:hidden fixed top-0 left-0 right-0 z-20 bg-white/95 backdrop-blur border-b border-stone-200 px-4 py-2.5 flex items-center justify-between gap-2">
        <button type="button" @click="drawer = true" class="p-2 -ml-2 rounded-lg text-stone-600 hover:bg-stone-100" aria-label="Ouvrir le menu">
            <x-icon name="menu" size="20" />
        </button>
        <a href="{{ $homeUrl }}" class="inline-flex items-center">
            <x-carenest-logo variant="full" class="h-7 w-auto" />
        </a>
        <livewire:shared.notification-bell />
    </div>

    {{-- Mobile drawer --}}
    <div x-show="drawer" x-cloak class="lg:hidden fixed inset-0 z-30">
        <div class="absolute inset-0 bg-stone-900/40" @click="drawer = false"></div>
        <aside class="absolute top-0 left-0 bottom-0 w-[280px] max-w-[85vw] bg-white shadow-elevated flex flex-col"
               x-show="drawer" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full">
            <div class="px-5 pt-5 pb-4 flex items-center justify-between">
                <x-carenest-logo variant="full" class="h-8 w-auto" />
                <button type="button" @click="drawer = false" class="p-2 rounded-lg text-stone-500 hover:bg-stone-100" aria-label="Fermer le menu">
                    <x-icon name="x" size="18" />
                </button>
            </div>
            <nav class="flex-1 px-3 space-y-0.5 overflow-y-auto" @click="drawer = false">
                @include('layouts.partials.nav-links')
            </nav>
            <div class="p-3 border-t border-stone-100">
                <div class="flex items-center gap-3 p-2">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-stone-900 truncate">{{ auth()->user()->name }}</div>
                        <div class="text-xs text-stone-500 truncate">{{ \App\Models\User::roleLabel(auth()->user()->role) }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost btn-sm">
                            <x-icon name="log-out" size="14" />
                            Déconnexion
                        </button>
                    </form>
                </div>
            </div>
        </aside>
    </div>

    {{-- Main --}}
    <main class="flex-1 min-w-0 pt-14 lg:pt-0">
        @if (isset($header))
            <header class="bg-white border-b border-stone-200">
                <div class="max-w-7xl mx-auto py-5 px-6 lg:px-10">{{ $header }}</div>
            </header>
        @endif
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-10 py-6 sm:py-8 lg:py-10">
            {{ $slot }}
        </div>
    </main>

</div>

@livewireScripts
</body>
</html>
