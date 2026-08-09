{{--
    Лист занятия для печати с телефона (expo-print).

    Самостоятельный документ: телефон печатает ровно этот HTML, страницы вокруг
    нет. Числа раскладки приходят из AsanaPrintLayout — той же, что считает
    печать из веб-админки, поэтому лист получается одинаковым.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $program->title }}</title>
    <style>
        @page { size: A4; margin: 10mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            color: #000;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        h1 { margin: 0 0 3mm; font-size: 16pt; font-weight: 700; }
        .note { margin: 0 0 5mm; font-size: 10pt; line-height: 1.4; color: #333; }

        .grid {
            display: grid;
            grid-template-columns: repeat({{ $layout['columns'] }}, minmax(0, 1fr));
            gap: {{ $layout['gap_y_mm'] }}mm {{ $layout['gap_x_mm'] }}mm;
            align-items: start;
            width: 100%;
        }

        .cell {
            margin: 0;
            text-align: center;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .cell img {
            display: block;
            width: 100%;
            height: auto;
            max-height: {{ $layout['image_mm'] }}mm;
            object-fit: contain;
            margin: 0 auto 1.5mm;
        }

        /* Панорамный лист (например «Сурья намаскар») в узкой колонке
           превращается в нечитаемую полоску — он занимает строку целиком. */
        .cell--wide { grid-column: 1 / -1; }
        .cell--wide img { max-height: 62mm; }

        figcaption {
            font-size: {{ $layout['caption_pt'] }}pt;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        .num { font-weight: 700; }
        .name { font-weight: 600; }
        .hint {
            display: block;
            margin-top: 0.6mm;
            font-size: {{ round($layout['caption_pt'] * 0.9, 1) }}pt;
            color: #444;
        }
    </style>
</head>
<body>
    <h1>{{ $program->title }}</h1>
    @if (filled($program->note))
        <p class="note">{{ $program->note }}</p>
    @endif

    <div class="grid">
        @foreach ($cells as $cell)
            <figure class="cell @if ($cell['wide']) cell--wide @endif">
                @if ($cell['image'])
                    <img src="{{ $cell['image'] }}" alt="">
                @endif
                <figcaption>
                    <span class="num">{{ $cell['number'] }}.</span>
                    <span class="name">{{ $cell['title'] }}</span>
                    {{-- В плотной сетке подпись к позе только мешает. --}}
                    @if (filled($cell['note']) && $layout['columns'] < 8)
                        <span class="hint">{{ $cell['note'] }}</span>
                    @endif
                </figcaption>
            </figure>
        @endforeach
    </div>
</body>
</html>
