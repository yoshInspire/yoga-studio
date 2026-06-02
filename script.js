// Header shadow + collapse announcement bar on scroll
const header = document.getElementById('header');
const topbar = document.querySelector('.topbar');
const onScroll = () => {
  const scrolled = window.scrollY > 4;
  header.classList.toggle('is-scrolled', scrolled);
  if (topbar) topbar.classList.toggle('is-collapsed', scrolled);
};
window.addEventListener('scroll', onScroll, { passive: true });
onScroll();

// Mobile menu
const burger = document.getElementById('burger');
const mobileMenu = document.getElementById('mobileMenu');
const toggleMenu = (open) => {
  const willOpen = open ?? !mobileMenu.classList.contains('is-open');
  mobileMenu.classList.toggle('is-open', willOpen);
  burger.classList.toggle('is-open', willOpen);
  document.body.style.overflow = willOpen ? 'hidden' : '';
};
burger.addEventListener('click', () => toggleMenu());
mobileMenu.querySelectorAll('a').forEach(a => a.addEventListener('click', () => toggleMenu(false)));

// Reveal on scroll
const reveals = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('is-visible');
      io.unobserve(entry.target);
    }
  });
}, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
reveals.forEach(el => io.observe(el));

// Subtle hero parallax
const heroBg = document.querySelector('.hero__bg');
if (heroBg && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  window.addEventListener('scroll', () => {
    const y = window.scrollY;
    if (y < window.innerHeight) heroBg.style.transform = `scale(1.08) translateY(${y * 0.18}px)`;
  }, { passive: true });
}

// Animated counters
const stats = document.querySelectorAll('[data-count]');
const countIO = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (!entry.isIntersecting) return;
    const el = entry.target;
    const target = parseInt(el.dataset.count, 10);
    const suffix = el.dataset.suffix || '';
    const dur = 1400;
    const start = performance.now();
    const tick = (now) => {
      const p = Math.min((now - start) / dur, 1);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(eased * target) + suffix;
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
    countIO.unobserve(el);
  });
}, { threshold: 0.6 });
stats.forEach(el => countIO.observe(el));
