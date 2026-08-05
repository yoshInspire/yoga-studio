// Подключает маску телефона в Filament после динамических обновлений Livewire.
(() => {
  const bind = () => window.PhoneMask?.bind(document);

  bind();

  new MutationObserver(bind).observe(document.body, {
    childList: true,
    subtree: true,
  });

  document.addEventListener('livewire:navigated', bind);
})();
