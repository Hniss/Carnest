@props(['stage'])
@php
    $meta = match ($stage) {
        'nouveau'       => ['cls' => 'badge-blue',    'label' => 'Nouveau'],
        'qualifie'      => ['cls' => 'badge-warning', 'label' => 'Qualifié'],
        'en_traitement' => ['cls' => 'badge-orange',  'label' => 'En traitement'],
        'suivi'         => ['cls' => 'badge-success', 'label' => 'Suivi'],
        'cloture'       => ['cls' => 'badge-neutral', 'label' => 'Clôturé'],
        default         => ['cls' => 'badge-neutral', 'label' => 'Inconnu'],
    };
@endphp
<span {{ $attributes->merge(['class' => 'badge ' . $meta['cls']]) }}>{{ $meta['label'] }}</span>
