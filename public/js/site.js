const header = document.getElementById('header');
const topbar = document.querySelector('.topbar');
const burger = document.getElementById('burger');
const mobileMenu = document.getElementById('mobileMenu');

const onScroll = () => {
  const scrolled = window.scrollY > 4;
  header?.classList.toggle('is-scrolled', scrolled);
  topbar?.classList.toggle('is-collapsed', scrolled);
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

// ===== Вкладки входа / регистрации =====
const authTabs = document.querySelectorAll('.auth__tab');
if (authTabs.length) {
  const forms = document.querySelectorAll('.auth__form');
  const switchAuth = (name) => {
    authTabs.forEach((t) => {
      const on = t.dataset.tab === name;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    forms.forEach((f) => f.classList.toggle('is-hidden', f.dataset.form !== name));
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
