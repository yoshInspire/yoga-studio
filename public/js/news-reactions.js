(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

  const applySummary = (root, summary) => {
    if (!summary) return;

    root.querySelectorAll('[data-reaction]').forEach((button) => {
      const type = button.dataset.reaction;
      const isActive = summary.user_reaction === type;
      button.classList.toggle('is-active', isActive);
    });

    root.querySelectorAll('[data-count]').forEach((counter) => {
      const type = counter.dataset.count;
      const count = Number(summary.counts?.[type] ?? 0);
      counter.textContent = count > 0 ? String(count) : '';
    });
  };

  document.querySelectorAll('[data-news-reactions]').forEach((root) => {
    root.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-reaction]');
      if (!button || !root.contains(button)) return;

      const canReact = root.dataset.canReact === '1';
      const loginUrl = root.dataset.loginUrl;

      if (!canReact) {
        if (button.dataset.requiresLogin === '1' && loginUrl) {
          window.location.href = loginUrl;
        }
        return;
      }

      if (!csrf) return;

      root.querySelectorAll('.news-reactions__btn').forEach((el) => el.classList.add('is-busy'));

      try {
        const response = await fetch(root.dataset.action, {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ reaction: button.dataset.reaction }),
          credentials: 'same-origin',
        });

        if (response.status === 401 || response.status === 403) {
          if (loginUrl) window.location.href = loginUrl;
          return;
        }

        if (!response.ok) return;

        const summary = await response.json();
        applySummary(root, summary);
      } catch (_error) {
        // ignore network errors
      } finally {
        root.querySelectorAll('.news-reactions__btn').forEach((el) => el.classList.remove('is-busy'));
      }
    });
  });
})();
