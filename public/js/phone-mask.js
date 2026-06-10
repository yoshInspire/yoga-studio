// Маска телефона +7 (___) ___-__-__ (сайт и админка)
(() => {
  const extractSubscriberDigits = (raw) => {
    let digits = String(raw).replace(/\D/g, '');
    if (!digits) return '';

    if (digits === '7' || digits === '8') {
      return '';
    }

    if (digits.length === 11 && digits.startsWith('8')) {
      digits = `7${digits.slice(1)}`;
    }

    if (digits.startsWith('8')) {
      digits = `7${digits.slice(1)}`;
    }

    if (digits.startsWith('7')) {
      digits = digits.slice(1);
    }

    return digits.slice(0, 10);
  };

  const formatRuPhone = (raw) => {
    const digits = extractSubscriberDigits(raw);
    if (!digits) return '+7 (';

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

  const bindPhoneMasks = (root = document) => {
    root.querySelectorAll('[data-phone-mask]:not([data-phone-mask-bound])').forEach((input) => {
      input.dataset.phoneMaskBound = 'true';
      attachPhoneMask(input);
    });
  };

  window.PhoneMask = {
    formatRuPhone,
    attach: attachPhoneMask,
    bind: bindPhoneMasks,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => bindPhoneMasks());
  } else {
    bindPhoneMasks();
  }
})();
