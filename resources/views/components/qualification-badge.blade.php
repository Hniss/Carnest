@props(['qualification'])
@php
    $meta = match ($qualification) {
        'pertinent'    => ['cls' => 'badge-warning', 'label' => 'Pertinent'],
        'faux_positif' => ['cls' => 'badge-neutral', 'label' => 'Faux positif'],
        'a_surveiller' => ['cls' => 'badge-blue',    'label' => 'À surveiller'],
        'confirme'     => ['cls' => 'badge-orange',  'label' => 'Confirmée'],
        'urgent'       => ['cls' => 'badge-danger',  'label' => 'Urgente'],
        default        => ['cls' => 'badge-neutral', 'label' => 'Non qualifiée'],
    };
@endphp
<span {{ $attributes->merge(['class' => 'badge ' . $meta['cls']]) }}>{{ $meta['label'] }}</span>
