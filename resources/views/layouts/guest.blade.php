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
</head>
<body class="bg-stone-50 text-stone-900 antialiased min-h-screen">

<div class="min-h-screen grid lg:grid-cols-2">

    {{-- Left: branding --}}
    <div class="hidden lg:flex relative overflow-hidden bg-brand-900 text-white p-12 flex-col">
        <div class="absolute inset-0 opacity-20" aria-hidden="true">
            <svg class="w-full h-full" viewBox="0 0 400 600" preserveAspectRatio="xMidYMid slice" fill="none">
                <circle cx="80"  cy="120" r="180" stroke="white" stroke-width="0.5" />
                <circle cx="320" cy="480" r="240" stroke="white" stroke-width="0.5" />
                <circle cx="200" cy="300" r="100" stroke="white" stroke-width="0.5" />
            </svg>
        </div>

        <a href="/" class="relative inline-flex items-center">
            <x-carenest-logo variant="full-white" class="h-10 w-auto" />
        </a>

        <div class="relative mt-auto max-w-md">
            <p class="eyebrow text-brand-200 mb-3">Pour les écoles</p>
            <h2 class="font-display font-extrabold text-3xl leading-tight text-white mb-4">
                Repérer la détresse émotionnelle avant qu'elle ne s'installe.
            </h2>
            <p class="text-brand-100/80 text-[15px] leading-relaxed">
                Un assistant d'écoute pour vos élèves, un tableau de bord clair pour vos équipes.
                Respectueux des données, ancré dans la loi&nbsp;09-08.
            </p>
        </div>
    </div>

    {{-- Right: form --}}
    <div class="flex flex-col items-center justify-center px-6 py-12">
        <div class="w-full max-w-[400px]">
            <div class="lg:hidden mb-8 flex items-center">
                <x-carenest-logo variant="full" class="h-9 w-auto" />
            </div>

            {{ $slot }}
        </div>
    </div>

</div>

@livewireScripts
</body>
</html>
