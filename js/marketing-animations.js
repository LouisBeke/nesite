(() => {
  'use strict';

  const animeCdn = 'https://cdn.jsdelivr.net/npm/animejs@4.5.0/dist/bundles/anime.umd.min.js';
  const animeIntegrity = 'sha384-InMmvD3VoYcY7hGjSC80aLb2bNNE4CzpX+Eq6FVDlmB0IKgDvmfPw4UY8L/M++iG';

  function start() {
    if (window.__foxAnimationsStarted) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const { animate, stagger } = window.anime || {};
    if (typeof animate !== 'function' || typeof stagger !== 'function') return;
    window.__foxAnimationsStarted = true;

    const animated = new WeakSet();
    const groupItems = new WeakMap();

  function reveal(elements, options = {}) {
    const targets = Array.from(elements).filter((element) => {
      if (!(element instanceof Element) || element.matches('script, style, link, dialog, [hidden]')) return false;
      if (animated.has(element)) return false;
      animated.add(element);
      return true;
    });

    if (!targets.length) return;

    animate(targets, {
      opacity: { from: 0 },
      y: { from: options.distance ?? 22 },
      duration: options.duration ?? 700,
      delay: stagger(options.stagger ?? 70, { start: options.delay ?? 0 }),
      ease: options.ease ?? 'out(3)',
      onComplete: () => {
        targets.forEach((target) => {
          target.style.removeProperty('opacity');
          target.style.removeProperty('transform');
        });
      },
    });
  }

  const heroCopy = document.querySelector(
    '.mk-hero-grid > :first-child, .mk-page-hero-grid > :first-child, .mk-blog-hero > .mk-wrap, .mk-article-head .mk-article-narrow'
  );
  if (heroCopy) reveal(heroCopy.children, { distance: 28, stagger: 85, duration: 760 });

  reveal(document.querySelectorAll('.mk-console, .mk-page-card'), {
    distance: 34,
    delay: 180,
    duration: 850,
  });

  window.FoxAnimations = Object.freeze({ reveal });

  const groups = [
    ['.mk-trust-grid', '.mk-trust-item'],
    ['.mk-category-grid', '.mk-category'],
    ['.mk-product-grid', '.mk-product'],
    ['.mk-feature-list', '.mk-feature'],
    ['.mk-steps', '.mk-step'],
    ['.mk-faq', 'details'],
    ['.mk-related', 'a'],
    ['.ob-grid', '.ob-card'],
    ['.services-grid', '.service-tile'],
    ['.admin-table tbody', 'tr'],
    ['.cards', '.card'],
    ['.privacy-content', '.privacy-card'],
    ['.contact-info', '.info-box'],
    ['.quick-actions', '.action-card'],
  ];

  const standalone = [
    '.mk-section-head',
    '.mk-free',
    '.mk-story',
    '.mk-prose',
    '.mk-spec-table',
    '.mk-cta-box',
    '.ob-filters',
    '.ob-state',
    '.contact-form-wrapper',
  ];

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;

        reveal(groupItems.get(entry.target) || [entry.target]);
        observer.unobserve(entry.target);
      });
    },
    { rootMargin: '0px 0px -8% 0px', threshold: 0.08 }
  );

  groups.forEach(([containerSelector, itemSelector]) => {
    document.querySelectorAll(containerSelector).forEach((container) => {
      groupItems.set(container, container.querySelectorAll(itemSelector));
      observer.observe(container);
    });
  });

  document.querySelectorAll(standalone.join(',')).forEach((element) => observer.observe(element));

  const pageContent = document.querySelector(
    '.portal-standalone-content, .client-content, .admin-content, .authbox, body:not(.marketing-body) main.wrap'
  );
  if (pageContent) reveal(pageContent.children, { distance: 18, stagger: 55, duration: 620 });
  }

  if (window.anime?.animate) {
    start();
    return;
  }

  const pending = document.querySelector('script[data-fox-anime-loader]');
  if (pending) {
    pending.addEventListener('load', start, { once: true });
    return;
  }

  const loader = document.createElement('script');
  loader.src = animeCdn;
  loader.integrity = animeIntegrity;
  loader.crossOrigin = 'anonymous';
  loader.referrerPolicy = 'no-referrer';
  loader.dataset.foxAnimeLoader = '';
  loader.addEventListener('load', start, { once: true });
  document.head.appendChild(loader);
})();
