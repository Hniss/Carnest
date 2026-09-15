@props(['level', 'resolved' => false])
@php
    $meta = match ($resolved ? 'resolved' : $level) {
        'critical' => ['cls' => 'badge-danger',  'label' => 'Critique'],
        'high'     => ['cls' => 'badge-orange',  'label' => 'Élevée'],
        'moderate' => ['cls' => 'badge-warning', 'label' => 'Modérée'],
        'low'      => ['cls' => 'badge-blue',    'label' => 'Faible'],
        'resolved' => ['cls' => 'badge-neutral', 'label' => 'Clôturée'],
        default    => ['cls' => 'badge-neutral', 'label' => ucfirst((string) $level)],
    };
@endphp
<span {{ $attributes->merge(['class' => 'badge ' . $meta['cls']]) }}>{{ $meta['label'] }}</span>
