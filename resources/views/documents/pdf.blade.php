<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<title>{{ $spec->title }}</title>
@php
    $color = $brand->color;
    $accent = $brand->accentColor();
    $tint = '#'.$brand->tint(0.92);
    $line = '#'.$brand->tint(0.8);
    $template = $spec->template->value;
@endphp
<style>
@if ($fonts)
    @font-face { font-family: '{{ $brand->pdfFont() }}'; font-weight: normal; font-style: normal; src: url('{{ $fonts['regular'] }}') format('truetype'); }
    @font-face { font-family: '{{ $brand->pdfFont() }}'; font-weight: bold; font-style: normal; src: url('{{ $fonts['bold'] }}') format('truetype'); }
@endif
    @page { margin: 26mm 20mm 24mm 20mm; }
    body { font-family: '{{ $brand->pdfFont() }}', 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.4; color: #1F2937; }

    h1, h2, h3, h4 { line-height: 1.25; page-break-after: avoid; }
    h1 { font-size: 17pt; color: #111827; margin: 0 0 5mm; }
    h2 { font-size: 12.5pt; color: {{ $color }}; margin: 8mm 0 3mm; padding-bottom: 1.6mm; border-bottom: .8pt solid {{ $line }}; }
    h3 { font-size: 10pt; color: #111827; margin: 5mm 0 2mm; }
    h4 { font-size: 9.5pt; color: #374151; margin: 4mm 0 1.5mm; }
    p { margin: 0 0 2.6mm; }
    a { color: {{ $color }}; text-decoration: none; }
    strong { color: #111827; }
    ul, ol { margin: 0 0 3mm 0; padding-left: 5.5mm; }
    li { margin-bottom: .8mm; }
    li > ul, li > ol { margin-top: 1.2mm; margin-bottom: 1mm; }
    blockquote { margin: 3mm 0; padding: 2mm 4mm; background: {{ $tint }}; border-left: 2.5pt solid {{ $accent }}; color: #374151; }
    code { font-family: 'DejaVu Sans Mono', monospace; font-size: 8.5pt; background: #F3F4F6; }
    hr { border: 0; border-top: .6pt solid #E5E7EB; margin: 5mm 0; }

    table { width: 100%; border-collapse: collapse; margin: 3mm 0 5mm; font-size: 8.8pt; page-break-inside: auto; }
    tr { page-break-inside: avoid; }
    th, td { padding: 2mm 2.6mm; vertical-align: top; text-align: left; line-height: 1.3; }
    .num { text-align: right; }
    td.num { white-space: nowrap; }

    table.data thead th { background: {{ $color }}; color: #FFFFFF; font-weight: bold; font-size: 8pt; text-transform: uppercase; letter-spacing: .03em; vertical-align: bottom; }
    table.data tbody td { border-bottom: .6pt solid #E5E7EB; }
    table.data tbody tr:nth-child(even) td { background: #F8FAFC; }
    table.data tbody td strong { color: #111827; }

    table.info { background: {{ $tint }}; border-left: 2.5pt solid {{ $color }}; margin: 2mm 0 5mm; }
    table.info th { width: 34%; color: #4B5563; font-weight: normal; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .04em; padding: .8mm 3mm; }
    table.info td { color: #111827; font-weight: bold; padding: .8mm 3mm; }
    table.info tr:first-child th, table.info tr:first-child td { padding-top: 2.5mm; }
    table.info tr:last-child th, table.info tr:last-child td { padding-bottom: 2.5mm; }

    table.totals { width: 50%; margin: 2mm 0 6mm 50%; page-break-inside: avoid; }
    table.totals th { color: #4B5563; font-weight: normal; border-bottom: .6pt solid #E5E7EB; }
    table.totals td { text-align: right; white-space: nowrap; color: #111827; border-bottom: .6pt solid #E5E7EB; }
    table.totals th strong, table.totals td strong { font-weight: normal; }
    table.totals tr.grand th, table.totals tr.grand td { background: {{ $color }}; color: #FFFFFF; font-weight: bold; font-size: 10.5pt; border-bottom: 0; }
    table.totals tr.grand th strong, table.totals tr.grand td strong { color: #FFFFFF; font-weight: bold; }

    /* Report cover: logo, title block, and a band in the brand colour at the foot of the page. */
    .cover { height: 238mm; position: relative; page-break-after: always; }
    .cover .logo { height: 16mm; }
    .cover .kicker { margin-top: 62mm; font-size: 8.5pt; font-weight: bold; color: {{ $accent }}; text-transform: uppercase; letter-spacing: .14em; }
    .cover .bar { width: 18mm; height: 2.2mm; background: {{ $color }}; margin: 4mm 0 7mm; }
    .cover h1 { font-size: 30pt; line-height: 1.12; color: #111827; margin: 0 0 5mm; width: 88%; }
    .cover .sub { font-size: 12.5pt; color: #4B5563; width: 85%; }
    .cover .band { position: absolute; left: 0; right: 0; bottom: 0; background: {{ $color }}; color: #FFFFFF; padding: 6mm 7mm; }
    .cover .band .date { font-size: 10pt; font-weight: bold; }
    .cover .band .org { font-size: 8pt; margin-top: 1.5mm; color: #FFFFFF; }

    .doc-title { margin-bottom: 7mm; }
    .doc-title .bar { width: 14mm; height: 1.8mm; background: {{ $accent }}; margin-bottom: 4mm; }
    .doc-title h1 { font-size: 20pt; margin: 0 0 1.5mm; }
    .doc-title .meta { color: #6B7280; font-size: 8.5pt; }

    .letterhead { margin: -8mm 0 12mm; border-bottom: .8pt solid {{ $line }}; padding-bottom: 4mm; }
    .letterhead img { height: 15mm; }
    .letterhead .name { font-size: 11pt; font-weight: bold; color: {{ $color }}; }
    .letterhead .date { text-align: right; color: #4B5563; font-size: 9pt; margin-top: 6mm; }

    img.asset { max-width: 100%; max-height: 60mm; }
    p.figure { margin: 3mm 0 4mm; }
    p.figure img.asset { max-height: 28mm; }
@if ($css)
    /* The document type's own style (a file of its skill). */
    {!! $css !!}
@endif
</style>
</head>
<body>
@if ($template === 'relatorio')
    <div class="cover">
        @if ($logo)<img class="logo" src="{{ $logo }}">@endif
        <div class="kicker">{{ $brand->name }}</div>
        <div class="bar"></div>
        <h1>{{ $spec->title }}</h1>
        @if ($spec->subtitle)<div class="sub">{{ $spec->subtitle }}</div>@endif
        <div class="band">
            <div class="date">{{ $spec->dateLabel() }}</div>
            <div class="org">{{ $brand->footer ?? $brand->name }}</div>
        </div>
    </div>
@elseif ($template === 'carta')
    <div class="letterhead">
        @if ($logo)<img src="{{ $logo }}">@else<div class="name">{{ $brand->name }}</div>@endif
        <div class="date">{{ $spec->dateLabel() }}</div>
    </div>
@else
    <div class="doc-title">
        <div class="bar"></div>
        <h1>{{ $spec->title }}</h1>
        <div class="meta">@if ($spec->subtitle){{ $spec->subtitle }} · @endif{{ $spec->dateLabel() }}</div>
    </div>
@endif

{!! $content !!}
</body>
</html>
