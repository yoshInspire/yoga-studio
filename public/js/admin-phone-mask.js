// Маска телефона +7 (___) ___-__-__ — как на сайте (site.js)
const formatRuPhone = (raw) => {
  let digits = raw.replace(/\D/g, '');
  if (digits.startsWith('8')) digits = `7${digits.slice(1)}`;
  if (digits.startsWith('7')) digits = digits.slice(1);
  digits = digits.slice(0, 10);
  if (!digits) return '';

  let out = '+7 (';
  out += digits.slice(0, 3);
  if (digits.length <= 3) return out;

  out += `) ${digits.slice(3, 6)}`;
  if (digits.length <= 6) return out;

  out += `-${digits.slice(6, 8)}`;
  if (digits.length <= 8) return out;

  return `${out}-${digits.slice(8, 10)}`;
};

const attachPhoneMask = (input) => {
  const apply = () => {
    const formatted = formatRuPhone(input.value);
    if (input.value === formatted) return;

    input.value = formatted;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };

  input.addEventListener('input', apply);
  input.addEventListener('blur', apply);

  if (input.value) {
    apply();
  }
};

const bindPhoneMasks = () => {
  document.querySelectorAll('[data-phone-mask]:not([data-phone-mask-bound])').forEach((input) => {
    input.dataset.phoneMaskBound = 'true';
    attachPhoneMask(input);
  });
};

bindPhoneMasks();

new MutationObserver(bindPhoneMasks).observe(document.body, {
  childList: true,
  subtree: true,
});

document.addEventListener('livewire:navigated', bindPhoneMasks);
