<style>
    /* Мобильный приоритет: база — телефон, расширения — в медиазапросах. */
    .as {
        --as-radius: 14px;
        --as-line: rgba(0, 0, 0, .07);
        --as-surface: #fff;
        --as-text: #111827;
        --as-muted: #6b7280;
        --as-soft: #f9fafb;

        display: flex;
        flex-direction: column;
        gap: 14px;
        padding-bottom: 84px; /* место под нижнюю панель действий */
    }
    .dark .as {
        --as-line: rgba(255, 255, 255, .09);
        --as-surface: #111827;
        --as-text: #f9fafb;
        --as-muted: #9ca3af;
        --as-soft: #1f2937;
    }
    [x-cloak] { display: none !important; }

    .as-i { width: 20px; height: 20px; flex: 0 0 auto; }

    /* --- Поля --- */
    .as-input {
        width: 100%; min-height: 44px; padding: 10px 13px;
        border-radius: 11px; border: 1px solid var(--as-line);
        background: var(--as-surface); color: var(--as-text);
        font-size: 16px; /* меньше 16px — iOS зумит при фокусе */
        transition: border-color .15s, box-shadow .15s;
    }
    .as-input:focus {
        outline: none;
        border-color: var(--primary-500);
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-500) 18%, transparent);
    }
    .as-input::placeholder { color: var(--as-muted); }
    .as-input--area { resize: vertical; line-height: 1.45; }
    .as-input--sm { min-height: 40px; font-size: 15px; padding: 8px 11px; }
    .as-input--search { padding-left: 42px; }

    /* --- Кнопки --- */
    .as-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        min-height: 44px; padding: 10px 16px; border-radius: 11px;
        border: 1px solid transparent; font-size: .92rem; font-weight: 600;
        line-height: 1.2; cursor: pointer; touch-action: manipulation;
        transition: background .15s, border-color .15s, opacity .15s;
    }
    .as-btn:disabled { opacity: .45; cursor: not-allowed; }
    .as-btn .as-i { width: 18px; height: 18px; }
    .as-btn--primary { background: var(--primary-600); color: #fff; }
    .as-btn--primary:hover:not(:disabled) { background: var(--primary-700); }
    .as-btn--ghost {
        background: var(--as-surface); color: var(--as-text); border-color: var(--as-line);
    }
    .as-btn--ghost:hover:not(:disabled) { background: var(--as-soft); }
    .as-btn--active { border-color: var(--primary-500); color: var(--primary-600); }

    .as-icon-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 40px; height: 40px; flex: 0 0 auto; border-radius: 10px;
        border: 1px solid var(--as-line); background: var(--as-surface);
        color: var(--as-muted); cursor: pointer; touch-action: manipulation;
        transition: background .15s, color .15s;
    }
    .as-icon-btn:hover:not(:disabled) { background: var(--as-soft); color: var(--as-text); }
    .as-icon-btn:disabled { opacity: .3; cursor: not-allowed; }
    .as-icon-btn--danger:hover:not(:disabled) { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
    .dark .as-icon-btn--danger:hover:not(:disabled) { background: rgba(220, 38, 38, .12); border-color: rgba(220, 38, 38, .3); }

    .as-back {
        display: inline-flex; align-items: center; gap: 4px; align-self: flex-start;
        padding: 6px 0; background: none; border: none; cursor: pointer;
        font-size: .92rem; font-weight: 600; color: var(--primary-600);
    }
    .as-back__icon { width: 16px; height: 16px; transform: rotate(180deg); }

    /* --- Хлебные крошки --- */
    .as-crumbs { display: flex; flex-wrap: wrap; align-items: center; gap: 2px; }
    .as-crumb {
        padding: 4px 2px; background: none; border: none; cursor: pointer;
        font-size: .84rem; font-weight: 600; color: var(--primary-600);
        max-width: 45vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .as-crumb--current { color: var(--as-muted); cursor: default; }
    .as-crumb__sep { width: 13px; height: 13px; color: #d1d5db; }
    .dark .as-crumb__sep { color: #4b5563; }

    /* --- Добавление --- */
    .as-add { display: flex; flex-direction: column; gap: 8px; }
    .as-add__buttons { display: flex; gap: 8px; }
    .as-add__buttons .as-btn { flex: 1 1 0; }
    .as-add__field { display: flex; gap: 8px; }
    .as-add__field .as-input { flex: 1 1 auto; min-width: 0; }
    .as-add__field .as-btn { flex: 0 0 auto; }

    /* --- Аватар-иконка --- */
    .as-avatar {
        display: inline-flex; align-items: center; justify-content: center;
        width: 42px; height: 42px; flex: 0 0 auto; border-radius: 12px;
    }
    .as-avatar--folder { background: #fef3c7; color: #b45309; }
    .as-avatar--program { background: color-mix(in srgb, var(--primary-500) 14%, transparent); color: var(--primary-600); }
    .dark .as-avatar--folder { background: rgba(180, 83, 9, .22); color: #fcd34d; }

    /* --- Карточки-строки --- */
    .as-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .as-card-row {
        display: flex; align-items: center; gap: 4px; overflow: hidden;
        border-radius: var(--as-radius); background: var(--as-surface);
        border: 1px solid var(--as-line);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        transition: border-color .15s, box-shadow .15s;
    }
    .as-card-row:hover { border-color: color-mix(in srgb, var(--primary-500) 45%, transparent); box-shadow: 0 2px 8px rgba(0, 0, 0, .06); }
    .as-card-row__main {
        flex: 1 1 auto; min-width: 0; display: flex; align-items: center; gap: 12px;
        padding: 11px 4px 11px 11px; background: none; border: none;
        cursor: pointer; text-align: left;
    }
    .as-card-row__text { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .as-card-row__title {
        font-size: .97rem; font-weight: 600; color: var(--as-text); line-height: 1.3;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .as-card-row__meta { font-size: .78rem; color: var(--as-muted); }
    .as-card-row__chevron { width: 16px; height: 16px; color: #d1d5db; }
    .dark .as-card-row__chevron { color: #4b5563; }
    .as-card-row__actions { display: flex; align-items: center; gap: 4px; padding-right: 8px; }

    /* --- Панель занятия --- */
    .as-panel {
        display: flex; flex-direction: column; gap: 11px; padding: 14px;
        border-radius: var(--as-radius); background: var(--as-surface);
        border: 1px solid var(--as-line); box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
    }
    .as-panel__head { display: flex; align-items: center; gap: 12px; }
    .as-panel__titles { flex: 1 1 auto; min-width: 0; }
    .as-panel__title {
        margin: 0; font-size: 1.1rem; font-weight: 700; line-height: 1.25;
        color: var(--as-text); overflow-wrap: anywhere;
    }
    .as-panel__meta { margin: 3px 0 0; font-size: .8rem; color: var(--as-muted); }

    /* --- Последовательность поз --- */
    .as-seq { display: flex; flex-direction: column; gap: 9px; }
    .as-pose {
        display: grid; grid-template-columns: 38px 92px 1fr; gap: 11px; align-items: start;
        padding: 10px; border-radius: var(--as-radius); background: var(--as-surface);
        border: 1px solid var(--as-line); box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
    }
    .as-pose.sortable-ghost { opacity: .4; }
    .as-pose.sortable-chosen { box-shadow: 0 8px 20px rgba(0, 0, 0, .14); }
    .as-pose__handle {
        display: flex; flex-direction: column; align-items: center; gap: 5px;
        padding: 8px 0; border-radius: 10px; background: var(--as-soft);
        cursor: grab; touch-action: none; /* иначе перетаскивание конфликтует со скроллом */
        min-height: 68px; justify-content: center;
    }
    .as-pose__handle:active { cursor: grabbing; }
    .as-pose__num { font-size: 1rem; font-weight: 700; color: var(--as-text); line-height: 1; }
    .as-pose__grip { width: 15px; height: 15px; color: #9ca3af; }
    .as-pose__img {
        display: flex; align-items: center; justify-content: center; overflow: hidden;
        aspect-ratio: 4 / 3; border-radius: 10px; background: #fff;
        border: 1px solid var(--as-line);
    }
    .as-pose__img img { width: 100%; height: 100%; object-fit: contain; }
    .as-pose__noimg { width: 26px; height: 26px; color: #d1d5db; }
    .as-pose__body { min-width: 0; display: flex; flex-direction: column; gap: 8px; }
    .as-pose__name {
        margin: 0; font-size: .92rem; font-weight: 600; color: var(--as-text);
        line-height: 1.3; overflow-wrap: anywhere;
    }
    .as-tag {
        display: inline-block; margin-left: 5px; padding: 2px 7px; border-radius: 999px;
        background: #fef3c7; color: #92400e; font-size: .66rem; font-weight: 700;
        vertical-align: middle;
    }
    .dark .as-tag { background: rgba(180, 83, 9, .25); color: #fcd34d; }
    .as-pose__tools { display: flex; flex-wrap: wrap; gap: 6px; }

    /* --- Библиотека --- */
    /* Панель поиска липнет к верху: с ~170 позами прокрутка длинная,
       а фильтр должен оставаться под рукой. Фон сплошной — иначе
       карточки просвечивают сквозь неё при прокрутке. */
    .as-libhead {
        display: flex; flex-direction: column; gap: 10px;
        position: sticky; top: 0; z-index: 5;
        padding: 10px; border-radius: var(--as-radius);
        background: var(--as-surface); border: 1px solid var(--as-line);
        box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
    }
    /* Внутри панели поля и чипы берут приглушённый фон — иначе сливаются с ней. */
    .as-libhead .as-input,
    .as-libhead .as-chip { background: var(--as-soft); }

    .as-search { position: relative; }
    .as-search__icon {
        position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
        width: 18px; height: 18px; color: var(--as-muted); pointer-events: none;
    }
    .as-chips {
        display: flex; gap: 6px; overflow-x: auto; padding-bottom: 2px;
        -webkit-overflow-scrolling: touch; scrollbar-width: none;
    }
    .as-chips::-webkit-scrollbar { display: none; }
    .as-chip {
        flex: 0 0 auto; min-height: 38px; padding: 8px 14px; border-radius: 999px;
        border: 1px solid var(--as-line); background: var(--as-surface); color: var(--as-muted);
        font-size: .81rem; font-weight: 600; cursor: pointer; white-space: nowrap;
        touch-action: manipulation; transition: background .15s, color .15s;
    }
    .as-chip:hover { color: var(--as-text); }
    .as-chip--on,
    .as-libhead .as-chip--on {
        background: var(--primary-600); color: #fff; border-color: transparent;
    }

    .as-grid { display: grid; gap: 9px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .as-card { position: relative; }
    .as-card__btn {
        width: 100%; display: flex; flex-direction: column; gap: 6px; padding: 7px;
        border-radius: 12px; border: 1px solid var(--as-line); background: var(--as-surface);
        cursor: pointer; text-align: center; transition: border-color .15s, box-shadow .15s;
    }
    .as-card__btn:hover {
        border-color: color-mix(in srgb, var(--primary-500) 50%, transparent);
        box-shadow: 0 3px 10px rgba(0, 0, 0, .07);
    }
    .as-card__thumb {
        position: relative; display: block; border-radius: 8px; overflow: hidden; background: #fff;
    }
    .as-card__thumb img { width: 100%; aspect-ratio: 4 / 3; object-fit: contain; display: block; }
    .as-card__add {
        position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
        background: color-mix(in srgb, var(--primary-600) 88%, transparent); color: #fff;
        opacity: 0; transition: opacity .15s;
    }
    .as-card__btn:hover .as-card__add, .as-card__btn:focus-visible .as-card__add { opacity: 1; }
    .as-card__name {
        font-size: .69rem; line-height: 1.3; color: var(--as-muted); min-height: 1.8em;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .as-card__del {
        position: absolute; top: -5px; right: -5px; width: 24px; height: 24px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 50%; border: 2px solid var(--as-surface); background: #dc2626; color: #fff;
        cursor: pointer; padding: 0;
    }
    .as-card__del .as-i { width: 12px; height: 12px; stroke-width: 2.5; }

    /* Раздел своей зарисовки прямо в карточке: нативный select даёт
       системный список — на телефоне это удобнее любого своего меню. */
    .as-card__cat {
        width: 100%; margin-top: 4px; padding: 5px 6px; border-radius: 7px;
        border: 1px solid var(--as-line); background: var(--as-soft); color: var(--as-muted);
        font-size: .66rem; line-height: 1.2; cursor: pointer;
        min-height: 32px; touch-action: manipulation;
    }
    .as-card__cat:focus { outline: none; border-color: var(--primary-500); }

    /* Поле с подписью в окне рисования */
    .as-field { display: flex; flex-direction: column; gap: 5px; }
    .as-field__label { font-size: .78rem; font-weight: 600; color: var(--as-muted); }
    .as-select { appearance: auto; cursor: pointer; }

    /* Выбор раскладки печати */
    .as-printbar {
        display: flex; flex-direction: column; gap: 8px; padding: 12px;
        border-radius: var(--as-radius); background: var(--as-surface);
        border: 1px solid var(--as-line);
    }
    .as-printbar__field { display: flex; flex-direction: column; gap: 5px; }
    .as-printbar__hint { margin: 0; font-size: .8rem; line-height: 1.4; color: var(--as-muted); }
    .as-printbar__hint strong { color: var(--as-text); }

    .as-hint { margin: 0; text-align: center; font-size: .8rem; color: var(--as-muted); }

    /* --- Пустые состояния --- */
    .as-empty {
        display: flex; flex-direction: column; align-items: center; gap: 8px;
        padding: 34px 20px; text-align: center;
        border-radius: var(--as-radius); background: var(--as-surface);
        border: 1px dashed var(--as-line);
    }
    .as-empty__icon {
        display: flex; align-items: center; justify-content: center;
        width: 48px; height: 48px; border-radius: 14px;
        background: var(--as-soft); color: var(--as-muted);
    }
    .as-empty__icon .as-i { width: 24px; height: 24px; }
    .as-empty__title { margin: 4px 0 0; font-size: 1rem; font-weight: 600; color: var(--as-text); }
    .as-empty__text { margin: 0; max-width: 34ch; font-size: .85rem; line-height: 1.5; color: var(--as-muted); }

    /* --- Нижняя панель действий --- */
    .as-bar {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 20;
        display: flex; gap: 8px; padding: 10px 14px calc(10px + env(safe-area-inset-bottom));
        background: var(--as-surface); border-top: 1px solid var(--as-line);
        box-shadow: 0 -2px 12px rgba(0, 0, 0, .06);
    }
    .as-bar .as-btn { flex: 1 1 0; min-width: 0; }
    .as-bar__print { flex: 0 0 auto !important; }

    /* --- Рисовалка --- */
    .as-draw { position: fixed; inset: 0; z-index: 50; display: flex; align-items: flex-end; justify-content: center; }
    .as-draw__backdrop { position: absolute; inset: 0; background: rgba(17, 24, 39, .6); }
    .as-draw__sheet {
        position: relative; width: 100%; max-height: 100dvh; overflow-y: auto;
        display: flex; flex-direction: column; gap: 11px;
        padding: 8px 14px calc(14px + env(safe-area-inset-bottom));
        border-radius: 18px 18px 0 0; background: var(--as-surface);
    }
    .as-draw__grabber {
        width: 38px; height: 4px; margin: 0 auto 4px; border-radius: 999px; background: #d1d5db;
    }
    .dark .as-draw__grabber { background: #4b5563; }
    .as-draw__head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .as-draw__title { font-size: 1.02rem; color: var(--as-text); }
    .as-draw__sub { margin: 3px 0 0; font-size: .78rem; line-height: 1.4; color: var(--as-muted); }
    /* Лист «в точку», как в тетради: по точкам легче держать пропорции и
       рисовать ровнее. Сетка лежит под холстом и в сохранённый файл не
       попадает — холст прозрачный, белый фон подставляется при сохранении. */
    .as-draw__canvas-wrap {
        border-radius: 12px; overflow: hidden; border: 1px solid var(--as-line);
        background-color: #fff;
        background-image: radial-gradient(circle, rgba(17, 24, 39, .22) 1px, transparent 1.2px);
        background-size: 18px 18px;
        background-position: 9px 9px;
    }
    .as-draw__canvas {
        display: block; width: 100%; aspect-ratio: 4 / 3;
        touch-action: none; /* без этого палец скроллит страницу вместо рисования */
        cursor: crosshair;
    }
    .as-draw__tools { display: flex; flex-wrap: wrap; gap: 8px; }
    .as-draw__foot { display: flex; gap: 8px; }
    .as-draw__foot .as-btn { flex: 1 1 0; }

    /* Сегментированные переключатели инструментов */
    .as-seg { display: flex; gap: 3px; padding: 3px; border-radius: 11px; background: var(--as-soft); }
    .as-seg__btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        min-height: 38px; padding: 7px 11px; border-radius: 9px; border: none;
        background: transparent; color: var(--as-muted);
        font-size: .8rem; font-weight: 600; cursor: pointer; touch-action: manipulation;
    }
    .as-seg__btn .as-i { width: 17px; height: 17px; }
    .as-seg__btn:disabled { opacity: .35; cursor: not-allowed; }
    .as-seg__btn--w { min-width: 40px; }
    .as-seg__btn--on {
        background: var(--as-surface); color: var(--as-text);
        box-shadow: 0 1px 3px rgba(0, 0, 0, .13);
    }
    .as-dot { display: block; border-radius: 50%; background: currentColor; }

    /* --- Печать --- */
    .as-print { display: none; }

    /* --- Планшет --- */
    @media (min-width: 640px) {
        .as { padding-bottom: 14px; }
        .as-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .as-card__name { font-size: .75rem; }
        .as-pose { grid-template-columns: 44px 128px 1fr; gap: 13px; padding: 12px; }
        .as-panel { padding: 16px; }
        .as-add__buttons .as-btn { flex: 0 0 auto; }

        /* Панель действий перестаёт быть прилипшей — экран уже не тесный */
        .as-bar {
            position: static; padding: 0; background: none; border: none; box-shadow: none;
        }
        .as-bar .as-btn { flex: 0 0 auto; }

        .as-draw { align-items: center; padding: 20px; }
        .as-draw__sheet { max-width: 760px; border-radius: 18px; max-height: 92dvh; padding-top: 16px; }
        .as-draw__grabber { display: none; }
    }

    @media (min-width: 900px) {
        .as { max-width: 880px; }
        .as-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    }

    @media print {
        @page { size: A4 portrait; margin: 12mm; }

        .fi-topbar, .fi-sidebar, .fi-header, .fi-footer,
        .as-crumbs, .as-panel, .as-seq, .as-bar, .as-draw, .as-libhead,
        .as-printbar { display: none !important; }

        /* Сбрасываем экранные ограничения: печать не должна зависеть от того,
           с телефона её запустили или с компьютера. */
        .as { display: block; padding: 0; margin: 0; max-width: none; gap: 0; }
        .as-print { display: block !important; width: 100%; color: #000; }

        .as-print__title { margin: 0 0 3mm; font-size: 16pt; font-weight: 700; }
        .as-print__note { margin: 0 0 5mm; font-size: 10pt; line-height: 1.4; color: #333; }

        /* Три колонки: человечек крупный, но лист не раздувается на десяток
           страниц. Панорамные листы занимают всю ширину — иначе от них
           остаётся нечитаемая полоска. */
        /* Число колонок и высота картинки подбираются под выбранное
           количество листов — значения приходят в переменных. */
        .as-print__grid {
            display: grid;
            grid-template-columns: repeat(var(--as-print-cols, 3), 1fr);
            gap: 6mm 5mm;
            align-items: start;
        }
        .as-print__cell {
            margin: 0;
            break-inside: avoid;
            page-break-inside: avoid;
            text-align: center;
        }
        .as-print__cell--wide { grid-column: 1 / -1; }

        .as-print__cell img {
            display: block;
            width: 100%;
            height: auto;
            max-height: var(--as-print-img, 60mm);
            object-fit: contain;
            margin: 0 auto 1.5mm;
        }
        /* На всю ширину листа самая «высокая» панорама выходит ~56 мм,
           так что ограничение её не режет — оно только страхует от
           неожиданно крупной картинки. */
        .as-print__cell--wide img { max-height: 62mm; }

        .as-print__cell figcaption {
            font-size: 9.5pt;
            line-height: 1.3;
            text-align: center;
        }
        .as-print__num { font-weight: 700; }
        .as-print__name { font-weight: 600; }
        .as-print__note-item {
            display: block;
            margin-top: 0.8mm;
            font-size: 8.5pt;
            color: #444;
        }
    }
</style>
