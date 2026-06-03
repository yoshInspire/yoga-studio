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

const heroBg = document.querySelector('.hero__bg');
if (heroBg && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  window.addEventListener(
    'scroll',
    () => {
      const y = window.scrollY;
      if (y < window.innerHeight) {
        heroBg.style.transform = `scale(1.08) translateY(${y * 0.18}px)`;
      }
    },
    { passive: true }
  );
}
