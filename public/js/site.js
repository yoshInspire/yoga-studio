const header = document.getElementById('header');
const burger = document.getElementById('burger');
const mobileMenu = document.getElementById('mobileMenu');

const onScroll = () => {
  const scrolled = window.scrollY > 4;
  header?.classList.toggle('is-scrolled', scrolled);
};
window.addEventListener('scroll', onScroll, { passive: true });
onScroll();

const toggleMenu = (open) => {
  if (!mobileMenu || !burger) return;
  const willOpen = open ?? !mobileMenu.classList.contains('is-open');
  mobileMenu.classList.toggle('is-open', willOpen);
  burger.classList.toggle('is-open', willOpen);
  burger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  document.body.style.overflow = willOpen ? 'hidden' : '';
};

burger?.addEventListener('click', () => toggleMenu());
mobileMenu?.querySelectorAll('a').forEach((a) => {
  a.addEventListener('click', () => toggleMenu(false));
});

const reveals = document.querySelectorAll('.reveal');
const io = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        io.unobserve(entry.target);
      }
    });
  },
  { threshold: 0.12, rootMargin: '0px 0px -8% 0px' }
);
reveals.forEach((el) => io.observe(el));

// ===== Подробные окна направлений =====
const dirModal = document.getElementById('dirModal');
if (dirModal) {
  const details = dirModal.querySelectorAll('.dir-detail');
  let lastFocused = null;

  const resetSlides = (el) => {
    const slides = el.querySelectorAll('.dir-detail__slide');
    slides.forEach((s, idx) => s.classList.toggle('is-active', idx === 0));
  };

  const openDir = (slug) => {
    let found = false;
    details.forEach((el) => {
      const match = el.dataset.dir === slug;
      el.hidden = !match;
      if (match) {
        found = true;
        resetSlides(el);
      }
    });
    if (!found) return;
    lastFocused = document.activeElement;
    dirModal.classList.add('is-open');
    dirModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    dirModal.scrollTop = 0;
    dirModal.querySelector('.dir-modal__close')?.focus();
  };

  const closeDir = () => {
    dirModal.classList.remove('is-open');
    dirModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocused) lastFocused.focus();
  };

  document.querySelectorAll('[data-dir]').forEach((btn) => {
    if (btn.closest('.dir-modal')) return;
    btn.addEventListener('click', () => openDir(btn.dataset.dir));
  });

  dirModal.querySelectorAll('[data-close]').forEach((el) => {
    el.addEventListener('click', closeDir);
  });

  dirModal.querySelectorAll('.dir-detail__nav').forEach((btn) => {
    btn.addEventListener('click', () => {
      const hero = btn.closest('.dir-detail__hero');
      if (!hero) return;
      const slides = [...hero.querySelectorAll('.dir-detail__slide')];
      if (slides.length < 2) return;
      let i = slides.findIndex((s) => s.classList.contains('is-active'));
      if (i < 0) i = 0;
      slides[i].classList.remove('is-active');
      i = btn.dataset.nav === 'next' ? (i + 1) % slides.length : (i - 1 + slides.length) % slides.length;
      slides[i].classList.add('is-active');
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && dirModal.classList.contains('is-open')) closeDir();
  });
}

// ===== Вкладки дней расписания =====
const schedDays = document.getElementById('schedDays');
if (schedDays) {
  const tabs = schedDays.querySelectorAll('.sched__day');
  const panels = document.querySelectorAll('.sched__panel');
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((t) => {
        const on = t === tab;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
      });
      panels.forEach((p) => p.classList.toggle('is-hidden', p.dataset.panel !== tab.dataset.day));
    });
  });
}

// ===== Аккордеон FAQ =====
const faqList = document.getElementById('faqList');
if (faqList) {
  faqList.querySelectorAll('.faq__q').forEach((q) => {
    q.addEventListener('click', () => {
      const item = q.closest('.faq__item');
      const answer = item.querySelector('.faq__a');
      const isOpen = item.classList.toggle('is-open');
      answer.style.maxHeight = isOpen ? answer.scrollHeight + 'px' : null;
    });
  });
}

// ===== Маска телефона +7 (___) ___-__-__ =====
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

const isLoginText = (value) => /[@a-zA-Zа-яА-ЯёЁ._-]/.test(value);

const shouldFormatAsPhone = (value, mode) => {
  const trimmed = value.trim();
  if (!trimmed) return false;

  if (mode === 'login') {
    if (isLoginText(trimmed)) return false;
    if (!/^[\d+\s()-]+$/.test(trimmed)) return false;
    const digits = trimmed.replace(/\D/g, '');
    // Одна цифра «7» — скорее начало email/логина, не телефон
    if (digits.length === 1 && digits === '7') return false;
    return true;
  }

  return true;
};

const attachPhoneMask = (input, mode = 'phone') => {
  const apply = () => {
    if (!shouldFormatAsPhone(input.value, mode)) return;

    const formatted = formatRuPhone(input.value);
    if (input.value !== formatted) input.value = formatted;
  };

  input.addEventListener('input', apply);
  input.addEventListener('blur', apply);
  if (input.value && shouldFormatAsPhone(input.value, mode)) {
    input.value = formatRuPhone(input.value);
  }
};

const togglePasswordVisibility = (btn) => {
  const field = btn.closest('.password-field');
  const input = field?.querySelector('input');
  if (!input) return;

  const showIcon = btn.querySelector('.password-field__icon--show');
  const hideIcon = btn.querySelector('.password-field__icon--hide');
  const visible = input.type === 'text';

  input.type = visible ? 'password' : 'text';
  btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
  btn.setAttribute('aria-label', visible ? 'Показать пароль' : 'Скрыть пароль');
  showIcon?.toggleAttribute('hidden', !visible);
  hideIcon?.toggleAttribute('hidden', visible);
};

// ===== Автоскрытие уведомлений на странице входа =====
document.querySelectorAll('[data-auto-dismiss]').forEach((alert) => {
  const delay = Number.parseInt(alert.dataset.autoDismiss, 10);
  if (!Number.isFinite(delay) || delay <= 0) return;

  window.setTimeout(() => {
    alert.classList.add('is-dismissing');
    window.setTimeout(() => alert.remove(), 300);
  }, delay);
});

// ===== Вкладки входа / регистрации =====
const authTabs = document.querySelectorAll('.auth__tab');
if (authTabs.length) {
  const forms = document.querySelectorAll('.auth__form');
  const authPanels = document.querySelectorAll('[data-auth-panel]');
  const authTabsWrap = document.querySelector('[data-auth-tabs]');
  const switchAuth = (name) => {
    authTabs.forEach((t) => {
      const on = t.dataset.tab === name;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    forms.forEach((f) => f.classList.toggle('is-hidden', f.dataset.form !== name));
    authPanels.forEach((panel) => {
      panel.classList.toggle('is-hidden', panel.dataset.authPanel !== name);
    });
    if (authTabsWrap) {
      authTabsWrap.classList.toggle('is-hidden', name === 'verify-email');
    }
  };
  authTabs.forEach((t) => t.addEventListener('click', () => switchAuth(t.dataset.tab)));
  document.querySelectorAll('[data-goto]').forEach((b) => b.addEventListener('click', () => switchAuth(b.dataset.goto)));

  const patronymicToggle = document.getElementById('patronymic-toggle');
  const patronymicField = document.getElementById('patronymic-field');
  if (patronymicToggle && patronymicField) {
    const syncPatronymic = () => {
      patronymicField.classList.toggle('is-hidden', !patronymicToggle.checked);
      if (!patronymicToggle.checked) {
        const input = patronymicField.querySelector('input');
        if (input) input.value = '';
      }
    };
    patronymicToggle.addEventListener('change', syncPatronymic);
    syncPatronymic();
  }

  document.querySelectorAll('[data-phone-mask]').forEach((input) => {
    const mode = input.dataset.phoneMask === 'login' ? 'login' : 'phone';
    attachPhoneMask(input, mode);
  });

}

document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
  btn.addEventListener('click', () => togglePasswordVisibility(btn));
});

// ===== Модалка «функция в разработке» =====
const soonModal = document.getElementById('soonModal');
if (soonModal) {
  const textEl = document.getElementById('soonModalText');
  const defaultText = textEl ? textEl.textContent : '';
  let lastFocusedSoon = null;

  const openSoon = (msg) => {
    if (textEl) textEl.textContent = msg && msg.trim() ? msg : defaultText;
    lastFocusedSoon = document.activeElement;
    soonModal.classList.add('is-open');
    soonModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    soonModal.querySelector('.soon-modal__close')?.focus();
  };

  const closeSoon = () => {
    soonModal.classList.remove('is-open');
    soonModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (lastFocusedSoon) lastFocusedSoon.focus();
  };

  document.querySelectorAll('[data-soon]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      openSoon(btn.getAttribute('data-soon'));
    });
  });

  soonModal.querySelectorAll('[data-soon-close]').forEach((el) => {
    el.addEventListener('click', closeSoon);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && soonModal.classList.contains('is-open')) closeSoon();
  });
}

// ===== Разделы личного кабинета =====
const lkNav = document.getElementById('lkNav');
if (lkNav) {
  const links = lkNav.querySelectorAll('.lk__navlink[data-sec]');
  const panels = document.querySelectorAll('.lk__panel');
  links.forEach((link) => {
    link.addEventListener('click', () => {
      links.forEach((l) => l.classList.toggle('is-active', l === link));
      panels.forEach((p) => p.classList.toggle('is-hidden', p.dataset.panel !== link.dataset.sec));
    });
  });
}
