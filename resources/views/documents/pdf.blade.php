<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<title>{{ $spec->title }}</title>
<style>
    @page { margin: 26mm 20mm 22mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; line-height: 1.5; color: #1f2328; }
    header.band { position: fixed; top: -18mm; left: 0; right: 0; height: 10mm; border-bottom: 1.5pt solid {{ $brand->color }}; }
    header.band .name { font-size: 8pt; font-weight: bold; color: {{ $brand->color }}; letter-spacing: .06em; text-transform: uppercase; }
    header.band img { height: 8mm; float: right; }
    footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; }
    h1.title { font-size: 20pt; color: {{ $brand->color }}; margin: 0 0 2mm 0; line-height: 1.2; }
    .meta { color: #6b7280; font-size: 8.5pt; margin-bottom: 8mm; }
    h1 { font-size: 15pt; color: {{ $brand->color }}; margin: 7mm 0 2mm; }
    h2 { font-size: 12.5pt; color: {{ $brand->color }}; margin: 6mm 0 2mm; }
    h3 { font-size: 11pt; margin: 5mm 0 1.5mm; }
    p { margin: 0 0 2.5mm; }
    ul, ol { margin: 0 0 3mm 0; padding-left: 6mm; }
    li { margin-bottom: 1mm; }
    table { width: 100%; border-collapse: collapse; margin: 3mm 0 5mm; font-size: 9pt; }
    th { background: #{{ $brand->tint() }}; color: #111827; text-align: left; font-weight: bold; }
    th, td { border-bottom: .5pt solid #d0d7de; padding: 1.6mm 2mm; vertical-align: top; }
    blockquote { margin: 3mm 0; padding: 1mm 4mm; border-left: 2pt solid {{ $brand->color }}; color: #4b5563; }
    code { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5pt; background: #f3f4f6; }
    hr { border: 0; border-top: .5pt solid #d0d7de; margin: 5mm 0; }
    .cover { page-break-after: always; height: 230mm; position: relative; }
    .cover .bar { width: 22mm; height: 3mm; background: {{ $brand->color }}; margin-bottom: 10mm; }
    .cover .org { font-size: 10pt; font-weight: bold; color: {{ $brand->color }}; text-transform: uppercase; letter-spacing: .08em; }
    .cover h1 { font-size: 28pt; color: #111827; margin: 60mm 0 4mm; line-height: 1.15; }
    .cover .sub { font-size: 12pt; color: #4b5563; }
    .cover .date { position: absolute; bottom: 0; font-size: 9pt; color: #6b7280; }
    .letterhead { margin-bottom: 12mm; }
    .letterhead .date { text-align: right; color: #4b5563; margin-top: 6mm; }
</style>
</head>
<body>
@if ($spec->template->value === 'relatorio')
    <div class="cover">
        @if ($logo)<img src="{{ $logo }}" style="height: 14mm; margin-bottom: 8mm;">@endif
        <div class="bar"></div>
        <div class="org">{{ $brand->name }}</div>
        <h1>{{ $spec->title }}</h1>
        @if ($spec->subtitle)<div class="sub">{{ $spec->subtitle }}</div>@endif
        <div class="date">{{ $spec->dateLabel() }}</div>
    </div>
@endif

<header class="band">
    @if ($logo && $spec->template->value !== 'carta')<img src="{{ $logo }}">@endif
    <span class="name">{{ $brand->name }}</span>
</header>
<footer>{{ $brand->footer ?? $brand->name }} · {{ $spec->title }}</footer>

@if ($spec->template->value === 'carta')
    <div class="letterhead">
        @if ($logo)<img src="{{ $logo }}" style="height: 14mm;">@endif
        <div class="date">{{ $spec->dateLabel() }}</div>
    </div>
@elseif ($spec->template->value === 'documento')
    <h1 class="title">{{ $spec->title }}</h1>
    <div class="meta">@if ($spec->subtitle){{ $spec->subtitle }} · @endif{{ $spec->dateLabel() }}</div>
@endif

{!! $content !!}
</body>
</html>
