(() => {
  // Копирование через скрытое поле — для старых браузеров и страниц без
  // доступа к буферу обмена (navigator.clipboard есть только на HTTPS).
  const copyFallback = (text) => {
    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    let ok = false;
    try {
      ok = document.execCommand('copy');
    } catch (_error) {
      ok = false;
    }
    field.remove();
    return ok;
  };

  document.querySelectorAll('[data-news-share]').forEach((root) => {
    const button = root.querySelector('[data-share-copy]');
    const label = root.querySelector('[data-share-copy-label]');
    if (!button || !label) return;

    const initial = label.textContent;
    let timer = null;

    button.addEventListener('click', async () => {
      const url = root.dataset.shareUrl;
      let ok = false;

      try {
        await navigator.clipboard.writeText(url);
        ok = true;
      } catch (_error) {
        ok = copyFallback(url);
      }

      label.textContent = ok ? 'Ссылка скопирована' : url;
      button.classList.toggle('is-done', ok);
      clearTimeout(timer);
      timer = setTimeout(() => {
        label.textContent = initial;
        button.classList.remove('is-done');
      }, 2500);
    });
  });
})();
