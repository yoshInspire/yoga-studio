<style>
    /* Мобильный приоритет: базовые стили — под телефон, шире — в медиазапросах. */
    .as { display: flex; flex-direction: column; gap: 14px; }

    /* Тач-цели не меньше 44px — палец и стилус на планшете. */
    .as-btn, .as-tool, .as-icon-btn, .as-chip { min-height: 44px; touch-action: manipulation; }

    /* --- Поля и кнопки --- */
    .as-input {
        width: 100%; min-height: 44px; padding: 10px 12px; border-radius: 10px;
        border: 1px solid rgba(0,0,0,.15); background: #fff; color: #111827;
        font-size: 16px; /* < 16px вызывает зум при фокусе на iOS */
    }
    .dark .as-input { background: #111827; color: #f9fafb; border-color: rgba(255,255,255,.15); }
    .as-input--area { resize: vertical; line-height: 1.4; }
    .as-input--sm { min-height: 40px; font-size: 15px; padding: 8px 10px; }

    .as-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        padding: 10px 16px; border-radius: 10px; border: 1px solid transparent;
        font-size: .92rem; font-weight: 600; cursor: pointer; line-height: 1.2;
    }
    .as-btn:disabled { opacity: .45; cursor: not-allowed; }
    .as-btn--primary { background: rgb(var(--primary-600)); color: #fff; }
    .as-btn--ghost {
        background: #f9fafb; color: #374151; border-color: rgba(0,0,0,.12);
    }
    .dark .as-btn--ghost { background: #1f2937; color: #e5e7eb; border-color: rgba(255,255,255,.12); }

    .as-icon-btn {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 44px; padding: 0 10px; border-radius: 10px;
        border: 1px solid rgba(0,0,0,.12); background: #fff; color: #374151;
        font-size: 1rem; cursor: pointer;
    }
    .dark .as-icon-btn { background: #1f2937; color: #e5e7eb; border-color: rgba(255,255,255,.12); }
    .as-icon-btn:disabled { opacity: .35; cursor: not-allowed; }
    .as-icon-btn--danger { color: #b91c1c; }

    .as-back {
        background: none; border: none; padding: 8px 0; cursor: pointer;
        font-size: .95rem; font-weight: 600; color: rgb(var(--primary-600));
    }

    /* --- Хлебные крошки --- */
    .as-crumbs {
        display: flex; flex-wrap: wrap; align-items: center; gap: 4px;
        font-size: .85rem;
    }
    .as-crumb {
        background: none; border: none; padding: 4px 2px; cursor: pointer;
        color: rgb(var(--primary-600)); font-size: .85rem; font-weight: 600;
    }
    .as-crumb--current { color: #6b7280; cursor: default; font-weight: 600; }
    .as-crumb__sep { color: #9ca3af; }

    /* --- Заголовок --- */
    .as-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .as-head__title {
        flex: 1 1 auto; margin: 0; font-size: 1.15rem; font-weight: 700;
        color: #111827; line-height: 1.25; min-width: 0; overflow-wrap: anywhere;
    }
    .dark .as-head__title { color: #f9fafb; }

    /* --- Создание --- */
    .as-create { display: flex; flex-direction: column; gap: 8px; }
    .as-create__row { display: flex; gap: 8px; }
    .as-create__row .as-input { flex: 1 1 auto; min-width: 0; }
    .as-create__row .as-btn { flex: 0 0 auto; }

    /* --- Списки папок и занятий --- */
    .as-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
    .as-row {
        display: flex; align-items: stretch; gap: 6px;
        border-radius: 12px; background: #fff; border: 1px solid rgba(0,0,0,.08);
        overflow: hidden;
    }
    .dark .as-row { background: #111827; border-color: rgba(255,255,255,.08); }
    .as-row__main {
        flex: 1 1 auto; min-width: 0; display: flex; align-items: center; gap: 10px;
        padding: 12px; background: none; border: none; cursor: pointer; text-align: left;
    }
    .as-row__icon { flex: 0 0 auto; font-size: 1.25rem; }
    .as-row__text { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .as-row__title {
        font-size: .98rem; font-weight: 600; color: #111827; line-height: 1.3;
        overflow-wrap: anywhere;
    }
    .dark .as-row__title { color: #f9fafb; }
    .as-row__meta { font-size: .78rem; color: #6b7280; }
    .as-row__chevron { flex: 0 0 auto; color: #9ca3af; font-size: 1.2rem; }
    .as-row__actions { flex: 0 0 auto; display: flex; align-items: center; gap: 4px; padding-right: 8px; }

    .as-empty {
        margin: 0; padding: 24px 16px; text-align: center;
        font-size: .9rem; color: #6b7280; line-height: 1.45;
    }

    /* --- Действия --- */
    .as-actions { display: flex; flex-wrap: wrap; gap: 8px; }
    .as-actions .as-btn { flex: 1 1 auto; }

    /* --- Последовательность поз --- */
    .as-seq { display: flex; flex-direction: column; gap: 10px; }
    .as-pose {
        display: grid;
        grid-template-columns: 44px 96px 1fr;
        gap: 10px; align-items: start;
        padding: 10px; border-radius: 12px;
        background: #fff; border: 1px solid rgba(0,0,0,.08);
    }
    .dark .as-pose { background: #111827; border-color: rgba(255,255,255,.08); }
    .as-pose__num {
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
        min-height: 72px; border-radius: 10px; background: #f3f4f6;
        font-size: 1rem; font-weight: 700; color: #4b5563;
        cursor: grab; touch-action: none; /* иначе перетаскивание конфликтует со скроллом */
    }
    .dark .as-pose__num { background: #1f2937; color: #d1d5db; }
    .as-pose__grip { font-size: .85rem; color: #9ca3af; }
    .as-pose__img {
        display: flex; align-items: center; justify-content: center;
        aspect-ratio: 4 / 3; border-radius: 10px; background: #fff;
        border: 1px solid rgba(0,0,0,.06); overflow: hidden;
    }
    .as-pose__img img { width: 100%; height: 100%; object-fit: contain; }
    .as-pose__noimg { font-size: .7rem; color: #9ca3af; }
    .as-pose__body { min-width: 0; display: flex; flex-direction: column; gap: 8px; }
    .as-pose__name {
        margin: 0; font-size: .92rem; font-weight: 600; color: #111827;
        line-height: 1.3; overflow-wrap: anywhere;
    }
    .dark .as-pose__name { color: #f9fafb; }
    .as-tag {
        display: inline-block; margin-left: 4px; padding: 2px 6px; border-radius: 6px;
        background: #fef3c7; color: #92400e; font-size: .68rem; font-weight: 600;
    }
    .as-pose__tools { display: flex; flex-wrap: wrap; gap: 6px; }

    /* --- Поиск и категории --- */
    .as-chips {
        display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px;
        -webkit-overflow-scrolling: touch; scrollbar-width: thin;
    }
    .as-chip {
        flex: 0 0 auto; padding: 8px 14px; border-radius: 999px;
        border: 1px solid rgba(0,0,0,.12); background: #fff; color: #4b5563;
        font-size: .82rem; font-weight: 600; cursor: pointer; white-space: nowrap;
    }
    .dark .as-chip { background: #1f2937; color: #d1d5db; border-color: rgba(255,255,255,.12); }
    .as-chip--on { background: rgb(var(--primary-600)); color: #fff; border-color: transparent; }

    /* --- Сетка библиотеки --- */
    .as-grid {
        display: grid; gap: 8px;
        grid-template-columns: repeat(3, minmax(0, 1fr)); /* телефон */
    }
    .as-card { position: relative; }
    .as-card__btn {
        width: 100%; display: flex; flex-direction: column; gap: 4px; padding: 6px;
        border-radius: 10px; border: 1px solid rgba(0,0,0,.08); background: #fff;
        cursor: pointer; text-align: center;
    }
    .dark .as-card__btn { background: #111827; border-color: rgba(255,255,255,.08); }
    .as-card__btn img {
        width: 100%; aspect-ratio: 4 / 3; object-fit: contain; background: #fff; border-radius: 6px;
    }
    .as-card__name {
        font-size: .68rem; line-height: 1.25; color: #4b5563;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }
    .dark .as-card__name { color: #d1d5db; }
    .as-card__del {
        position: absolute; top: -6px; right: -6px; width: 26px; height: 26px;
        border-radius: 50%; border: none; background: #dc2626; color: #fff;
        font-size: .9rem; line-height: 1; cursor: pointer;
    }

    /* --- Рисовалка --- */
    .as-draw {
        position: fixed; inset: 0; z-index: 50;
        display: flex; align-items: flex-end; justify-content: center;
        background: rgba(17,24,39,.55); padding: 0;
    }
    .as-draw__sheet {
        width: 100%; max-height: 100dvh; overflow-y: auto;
        display: flex; flex-direction: column; gap: 10px;
        padding: 12px 12px calc(12px + env(safe-area-inset-bottom));
        border-radius: 16px 16px 0 0; background: #fff;
    }
    .dark .as-draw__sheet { background: #0f172a; }
    .as-draw__head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .as-draw__title { font-size: 1rem; color: #111827; }
    .dark .as-draw__title { color: #f9fafb; }
    .as-draw__canvas-wrap {
        width: 100%; border-radius: 12px; overflow: hidden;
        border: 1px solid rgba(0,0,0,.12); background: #fff;
    }
    .as-draw__canvas {
        display: block; width: 100%; aspect-ratio: 4 / 3;
        touch-action: none; /* без этого палец скроллит страницу вместо рисования */
        cursor: crosshair;
    }
    .as-draw__tools { display: flex; flex-wrap: wrap; gap: 8px; }
    .as-draw__group {
        display: flex; gap: 4px; padding: 4px; border-radius: 10px; background: #f3f4f6;
    }
    .dark .as-draw__group { background: #1f2937; }
    .as-tool {
        display: inline-flex; align-items: center; justify-content: center;
        padding: 8px 12px; border-radius: 8px; border: none; background: transparent;
        font-size: .82rem; font-weight: 600; color: #4b5563; cursor: pointer;
    }
    .dark .as-tool { color: #d1d5db; }
    .as-tool:disabled { opacity: .4; cursor: not-allowed; }
    .as-tool--on { background: #fff; color: #111827; box-shadow: 0 1px 2px rgba(0,0,0,.12); }
    .dark .as-tool--on { background: #374151; color: #f9fafb; }
    .as-tool--w { min-width: 44px; }
    .as-dot { display: block; border-radius: 50%; background: currentColor; }
    .as-draw__foot { display: flex; gap: 8px; }
    .as-draw__foot .as-btn { flex: 1 1 auto; }

    /* --- Печать --- */
    .as-print { display: none; }

    /* --- Планшет --- */
    @media (min-width: 640px) {
        .as-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .as-card__name { font-size: .74rem; }
        .as-pose { grid-template-columns: 48px 132px 1fr; gap: 12px; padding: 12px; }
        .as-actions .as-btn { flex: 0 0 auto; }
        .as-draw { align-items: center; padding: 16px; }
        .as-draw__sheet { max-width: 720px; border-radius: 16px; max-height: 92dvh; }
    }

    @media (min-width: 900px) {
        .as { max-width: 860px; }
        .as-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    }

    @media print {
        /* Печатаем только раскладку занятия. */
        .fi-topbar, .fi-sidebar, .fi-header, .as-crumbs, .as-head, .as-note,
        .as-actions, .as-seq, .as-draw, .fi-footer { display: none !important; }
        .as-print { display: block !important; }
        .as-print__title { margin: 0 0 4px; font-size: 18pt; }
        .as-print__note { margin: 0 0 12px; font-size: 10pt; color: #444; }
        .as-print__grid {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
        }
        .as-print__cell { margin: 0; break-inside: avoid; text-align: center; }
        .as-print__cell img { width: 100%; height: auto; }
        .as-print__cell figcaption { font-size: 8pt; line-height: 1.2; }
    }
</style>
