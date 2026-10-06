{{ $briefing->title }}

{{ $briefing->content }}
@if (! empty($briefing->decisions_pending))

Precisa da sua decisão:
@foreach ($briefing->decisions_pending as $decision)
- {{ $decision['title'] ?? '' }}@if (! empty($decision['link'])) — {{ $base }}{{ $decision['link'] }}@endif

@endforeach
@endif

Abrir na consola: {{ $base }}/briefings/{{ $briefing->id }}
