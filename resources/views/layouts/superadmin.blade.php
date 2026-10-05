<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'Espace super-admin' }} · CareNest</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-stone-50 text-stone-900 min-h-screen">
@php
    $links = [
        ['superadmin.dashboard', 'Tableau de bord'],
        ['superadmin.schools', 'Écoles'],
        ['superadmin.ai-keys', "Clés d'IA"],
        ['superadmin.mail', "Boîte d'envoi"],
    ];
@endphp
<header class="bg-white border-b border-stone-200">
    <div class="max-w-6xl mx-auto px-4 sm:px-6">
        <div class="flex items-center justify-between gap-4 h-16">
            <a href="{{ route('superadmin.dashboard') }}" class="flex items-center gap-3 shrink-0">
                <x-carenest-logo variant="full" class="h-8 w-auto" />
                <span class="badge badge-neutral hidden sm:inline-flex">Super-admin</span>
            </a>
            <div class="flex items-center gap-3 min-w-0">
                <span class="text-sm text-stone-600 truncate hidden sm:inline">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="btn-ghost btn-sm">Se déconnecter</button>
                </form>
            </div>
        </div>
        <nav class="flex gap-1 overflow-x-auto pb-2 -mx-1 px-1" aria-label="Navigation super-admin">
            @foreach ($links as [$name, $label])
                <a href="{{ route($name) }}"
                   class="nav-item whitespace-nowrap {{ request()->routeIs($name.'*') ? 'nav-item-active' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</header>
<main class="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
    {{ $slot }}
</main>
</body>
</html>
