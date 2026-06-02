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
