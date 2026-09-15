@props(['status'])
@php
    $meta = match ($status) {
        'surveillance'         => ['cls' => 'badge-blue',    'label' => 'Surveillance'],
        'accompagnement'       => ['cls' => 'badge-warning', 'label' => 'Accompagnement'],
        'intervention_urgente' => ['cls' => 'badge-danger',  'label' => 'Intervention urgente'],
        'termine'              => ['cls' => 'badge-neutral', 'label' => 'Terminé'],
        default                => ['cls' => 'badge-success', 'label' => 'Aucun suivi'],
    };
@endphp
<span {{ $attributes->merge(['class' => 'badge ' . $meta['cls']]) }}>{{ $meta['label'] }}</span>
