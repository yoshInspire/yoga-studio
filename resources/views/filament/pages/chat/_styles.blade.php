@once
    <style>
        /* Палитра студии — та же, что в приложении (design/tokens.json). */
        .chat {
            --chat-bg: #f4f1e6;
            --chat-paper: #fffdf8;
            --chat-line: #dcdecb;
            --chat-soft: #e4ede2;
            --chat-ink: #2f331f;
            --chat-muted: #6d6e57;
            --chat-sage: #56633f;
            --chat-clay: #9d5a3f;

            display: grid;
            grid-template-columns: minmax(240px, 320px) 1fr;
            gap: 1rem;
            height: min(72vh, 720px);
        }

        @media (max-width: 900px) {
            .chat { grid-template-columns: 1fr; height: auto; }
            .chat__list { max-height: 320px; }
        }

        .chat__list,
        .chat__thread {
            background: var(--chat-paper);
            border: 1px solid var(--chat-line);
            border-radius: 14px;
            overflow: hidden;
        }

        .chat__list { overflow-y: auto; padding: .5rem; }

        .chat__search { display: block; padding: .25rem .25rem .5rem; }
        .chat__search input {
            width: 100%;
            padding: .5rem .75rem;
            border: 1.5px solid var(--chat-line);
            border-radius: 999px;
            background: var(--chat-bg);
            color: var(--chat-ink);
            font-size: .9rem;
        }

        .chat__row {
            display: flex;
            gap: .6rem;
            width: 100%;
            padding: .6rem;
            border: 1px solid transparent;
            border-radius: 12px;
            background: none;
            text-align: left;
            cursor: pointer;
        }
        .chat__row:hover { background: var(--chat-bg); }
        .chat__row--active { background: var(--chat-soft); border-color: var(--chat-line); }
        .chat__row--unread { border-color: var(--chat-clay); }

        .chat__avatar {
            flex: none;
            display: grid;
            place-items: center;
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--chat-sage);
            color: #fffdf9;
            font-weight: 700;
            font-size: .8rem;
        }

        .chat__rowBody { flex: 1; min-width: 0; }
        .chat__rowTop, .chat__rowBottom { display: flex; align-items: center; gap: .5rem; }
        .chat__name {
            flex: 1;
            font-weight: 600;
            color: var(--chat-ink);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .chat__when { font-size: .72rem; color: var(--chat-muted); }
        .chat__preview {
            flex: 1;
            font-size: .8rem;
            color: var(--chat-muted);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .chat__badge {
            flex: none;
            min-width: 20px;
            padding: 0 6px;
            border-radius: 999px;
            background: var(--chat-clay);
            color: #fffdf9;
            font-size: .7rem;
            font-weight: 700;
            text-align: center;
        }

        .chat__thread { display: flex; flex-direction: column; }

        .chat__head {
            display: flex; align-items: center; gap: .75rem;
            padding: .85rem 1rem;
            border-bottom: 1px solid var(--chat-line);
        }
        .chat__head strong { display: block; color: var(--chat-ink); }
        .chat__head small { color: var(--chat-muted); }

        .chat__feed {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: .4rem;
            padding: 1rem;
            overflow-y: auto;
            background: var(--chat-bg);
            /* Лента растёт снизу вверх, как в мессенджерах. */
            justify-content: flex-end;
        }

        .chat__day {
            align-self: center;
            padding: .15rem .7rem;
            border-radius: 999px;
            background: #ede8d8;
            color: var(--chat-muted);
            font-size: .72rem;
            margin: .4rem 0;
        }

        .chat__bubbleRow { display: flex; }
        .chat__bubbleRow--mine { justify-content: flex-end; }

        .chat__bubble {
            max-width: 78%;
            padding: .6rem .8rem;
            border: 1px solid var(--chat-line);
            border-radius: 16px 16px 16px 16px;
            border-top-left-radius: 6px;
            background: var(--chat-paper);
            color: var(--chat-ink);
        }
        .chat__bubble--mine {
            background: var(--chat-sage);
            border-color: var(--chat-sage);
            color: #fffdf9;
            border-top-left-radius: 16px;
            border-top-right-radius: 6px;
        }
        .chat__bubble p { margin: 0; white-space: pre-wrap; word-break: break-word; }
        .chat__photo { display: block; max-width: 260px; border-radius: 10px; margin-bottom: .35rem; }
        .chat__time { display: block; margin-top: .25rem; font-size: .68rem; opacity: .75; text-align: right; }

        .chat__composer { border-top: 1px solid var(--chat-line); padding: .75rem 1rem; }
        .chat__composerRow { display: flex; gap: .5rem; align-items: flex-end; }
        .chat__composerRow textarea {
            flex: 1;
            padding: .5rem .75rem;
            border: 1.5px solid var(--chat-line);
            border-radius: 12px;
            background: var(--chat-bg);
            color: var(--chat-ink);
            resize: vertical;
        }
        .chat__file {
            padding: .5rem .8rem;
            border: 1.5px solid var(--chat-line);
            border-radius: 999px;
            background: var(--chat-soft);
            color: var(--chat-sage);
            font-size: .82rem;
            cursor: pointer;
            white-space: nowrap;
        }
        .chat__send {
            padding: .55rem 1.1rem;
            border: none;
            border-radius: 999px;
            background: var(--chat-clay);
            color: #fffdf9;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .chat__send:disabled { opacity: .6; cursor: default; }

        .chat__attach {
            display: flex; align-items: center; gap: .6rem;
            margin-bottom: .5rem; padding: .4rem .6rem;
            background: var(--chat-soft); border-radius: 10px;
            font-size: .82rem; color: var(--chat-sage);
        }
        .chat__attach img { width: 36px; height: 36px; object-fit: cover; border-radius: 8px; }
        .chat__attach button { margin-left: auto; background: none; border: none; color: var(--chat-muted); cursor: pointer; }

        .chat__empty { padding: 1.5rem; color: var(--chat-muted); text-align: center; }
        .chat__error { margin-top: .4rem; color: #a44530; font-size: .8rem; }
    </style>

    <script>
        // Держим ленту прокрученной вниз: после опроса Livewire перерисовывает
        // список, и без этого свежее сообщение оказывается ниже видимой части.
        document.addEventListener('livewire:navigated', scrollChatFeed);
        document.addEventListener('livewire:update', scrollChatFeed);
        document.addEventListener('DOMContentLoaded', scrollChatFeed);

        function scrollChatFeed() {
            requestAnimationFrame(() => {
                const feed = document.getElementById('chat-feed');
                if (feed) feed.scrollTop = feed.scrollHeight;
            });
        }
    </script>
@endonce
