(() => {
  'use strict';

  const animeCdn = 'https://cdn.jsdelivr.net/npm/animejs@4.5.0/dist/bundles/anime.umd.min.js';
  const animeIntegrity = 'sha384-InMmvD3VoYcY7hGjSC80aLb2bNNE4CzpX+Eq6FVDlmB0IKgDvmfPw4UY8L/M++iG';
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  function start() {
    if (window.__foxAnimationsStarted || reducedMotion.matches) return;

    const { animate, stagger } = window.anime || {};
    if (typeof animate !== 'function' || typeof stagger !== 'function') return;
    window.__foxAnimationsStarted = true;

    const animated = new WeakSet();
    const counted = new WeakSet();
    const groupConfigs = new WeakMap();
    const ignored = 'script, style, link, dialog, [hidden], [aria-hidden="true"]';

    function elementsFrom(value) {
      if (!value) return [];
      if (value instanceof Element) return [value];
      if (typeof value === 'string') return Array.from(document.querySelectorAll(value));
      return Array.from(value);
    }

    function clean(targets, properties = ['opacity', 'transform', 'filter']) {
      targets.forEach((target) => properties.forEach((property) => target.style.removeProperty(property)));
    }

    function reveal(value, options = {}) {
      const targets = elementsFrom(value).filter((element) => {
        if (!(element instanceof Element) || element.matches(ignored)) return false;
        if (!options.replay && animated.has(element)) return false;
        animated.add(element);
        return true;
      });

      if (!targets.length) return null;

      const settings = {
        opacity: { from: 0 },
        duration: options.duration ?? 720,
        delay: stagger(options.stagger ?? 65, { start: options.delay ?? 0 }),
        ease: options.ease ?? 'out(3)',
        onComplete: () => {
          clean(targets);
          options.onComplete?.(targets);
        },
      };

      if (options.axis === 'x') settings.x = { from: options.distance ?? 28 };
      else settings.y = { from: options.distance ?? 22 };
      if (options.scale) settings.scale = { from: options.scale };
      if (options.blur) settings.filter = { from: `blur(${options.blur}px)` };

      return animate(targets, settings);
    }

    function animateNumber(element) {
      if (!(element instanceof Element) || counted.has(element) || element.children.length) return;
      const original = element.textContent.trim();
      if (!/^-?[\d,.\s]+$/.test(original)) return;

      const normalized = original.replace(/\s/g, '').replace(/,(?=\d{3}(?:\D|$))/g, '');
      const target = Number.parseFloat(normalized);
      if (!Number.isFinite(target)) return;

      counted.add(element);
      const decimalMatch = original.match(/[.,](\d+)$/);
      const decimals = decimalMatch ? decimalMatch[1].length : 0;
      const state = { value: 0 };

      animate(state, {
        value: target,
        duration: 1050,
        ease: 'out(4)',
        onUpdate: () => {
          element.textContent = state.value.toLocaleString(undefined, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
          });
        },
        onComplete: () => { element.textContent = original; },
      });
    }

    function pulse(value) {
      const targets = elementsFrom(value);
      if (!targets.length) return null;
      return animate(targets, {
        opacity: [0.55, 1],
        scale: [0.82, 1.18],
        duration: 900,
        delay: stagger(110),
        ease: 'inOut(2)',
        alternate: true,
        loop: true,
      });
    }

    window.FoxAnimations = Object.freeze({ reveal, animateNumber, pulse });

    injectEnhancementStyles();
    addScrollProgress();
    addHeroGlow();

    const heroCopy = document.querySelector(
      '.mk-hero-grid > :first-child, .mk-page-hero-grid > :first-child, .mk-blog-hero > .mk-wrap, .mk-article-head .mk-article-narrow, .ff-hero-copy'
    );
    if (heroCopy) reveal(heroCopy.children, { distance: 30, stagger: 90, duration: 820, blur: 5 });

    reveal('.mk-console, .mk-page-card', {
      axis: 'x', distance: 44, scale: 0.97, delay: 180, duration: 920, blur: 4,
    });

    reveal('.mk-nav-inner > *, .ff-nav-inner > *, .client-topbar > *, .admin-top > *', {
      distance: -14, stagger: 55, duration: 560,
    });

    reveal('.admin-side .admin-brand, .admin-side .admin-workspace, .admin-side .admin-nav-group, .admin-side .admin-side-bottom', {
      axis: 'x', distance: -18, stagger: 45, duration: 620,
    });

    const pageContent = document.querySelector(
      '.portal-standalone-content, .client-content, .admin-content, .authbox, body:not(.marketing-body) main.wrap'
    );
    if (pageContent) reveal(pageContent.children, { distance: 20, stagger: 58, duration: 680, blur: 3 });

    const groups = [
      ['.mk-trust-grid', '.mk-trust-item', { distance: 16, stagger: 55 }],
      ['.mk-category-grid', '.mk-category', { distance: 30, scale: 0.96, stagger: 90 }],
      ['.mk-product-grid', '.mk-product', { distance: 26, scale: 0.97, stagger: 75 }],
      ['.mk-feature-list', '.mk-feature', { axis: 'x', distance: 28, stagger: 70 }],
      ['.mk-funnel', ':scope > div', { axis: 'x', distance: -24, stagger: 80 }],
      ['.mk-steps', '.mk-step', { distance: 28, stagger: 85 }],
      ['.mk-faq', 'details', { distance: 18, stagger: 55 }],
      ['.mk-related', 'a', { distance: 20, scale: 0.98 }],
      ['.ob-grid', '.ob-card', { distance: 28, scale: 0.97, stagger: 75 }],
      ['.services-grid', '.service-tile', { distance: 24, scale: 0.98, stagger: 65 }],
      ['.client-stats', '.client-stat', { distance: 22, scale: 0.97, stagger: 65 }],
      ['.client-server-grid', '.client-server-card', { distance: 24, scale: 0.98, stagger: 70 }],
      ['.admin-stats', '.admin-stat-card', { distance: 22, scale: 0.97, stagger: 60 }],
      ['.admin-health-grid', '.admin-health-card', { axis: 'x', distance: 22, stagger: 55 }],
      ['.admin-table tbody', 'tr', { distance: 10, stagger: 18, duration: 480 }],
      ['.cards', '.card', { distance: 20, scale: 0.97, stagger: 60 }],
      ['.privacy-content', '.privacy-card', { axis: 'x', distance: 24, stagger: 45 }],
      ['.contact-info', '.info-box', { distance: 24, scale: 0.97, stagger: 70 }],
      ['.quick-actions', '.action-card', { distance: 24, scale: 0.97, stagger: 70 }],
      ['.ff-row', '.ff-card', { distance: 28, scale: 0.96, stagger: 70 }],
      ['.ff-feature-grid', '.ff-feature', { distance: 26, scale: 0.97, stagger: 80 }],
      ['.ff-price-grid', '.ff-price', { distance: 26, scale: 0.97, stagger: 65 }],
      ['.ff-preview-list', 'span', { axis: 'x', distance: 24, stagger: 65 }],
      ['.ff-faq', 'details', { distance: 18, stagger: 55 }],
    ];

    const standalone = [
      '.mk-section-head', '.mk-free', '.mk-story', '.mk-prose', '.mk-spec-table',
      '.mk-cta-box', '.ob-filters', '.ob-state', '.contact-form-wrapper',
      '.admin-panel', '.rail-card', '.manage-card', '.ff-section-head', '.ff-preview',
    ];

    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const config = groupConfigs.get(entry.target);
        reveal(config?.items || entry.target, config?.options || {});
        revealObserver.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -7% 0px', threshold: 0.08 });

    groups.forEach(([containerSelector, itemSelector, options]) => {
      document.querySelectorAll(containerSelector).forEach((container) => {
        groupConfigs.set(container, { items: container.querySelectorAll(itemSelector), options });
        revealObserver.observe(container);
      });
    });

    document.querySelectorAll(standalone.join(',')).forEach((element) => revealObserver.observe(element));

    const numberObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        animateNumber(entry.target);
        numberObserver.unobserve(entry.target);
      });
    }, { threshold: 0.5 });

    document.querySelectorAll('.admin-stat-card strong, .client-stat strong, .cards .n')
      .forEach((element) => numberObserver.observe(element));

    pulse('.status-dot, .health-state i, .client-server-status i, .client-live-state i');
    addButtonFeedback(animate);
    addCardIconFeedback(animate);
    addDetailsMotion(animate);
    observeDynamicContent(reveal, animateNumber);

    function injectEnhancementStyles() {
      const style = document.createElement('style');
      style.dataset.foxAnimationStyles = '';
      style.textContent = `
        .fox-scroll-progress{position:fixed;z-index:10000;top:0;left:0;width:100%;height:3px;pointer-events:none;transform:scaleX(0);transform-origin:left center;background:linear-gradient(90deg,#ff7417,#ffb15e,#56d79b);box-shadow:0 0 12px rgba(255,116,23,.5)}
        .fox-hero-glow{position:absolute;z-index:-1;width:280px;height:280px;border-radius:50%;pointer-events:none;opacity:0;filter:blur(12px);background:radial-gradient(circle,rgba(255,116,23,.17),rgba(76,135,255,.06) 45%,transparent 70%);will-change:transform,opacity}
        @media(prefers-reduced-motion:reduce){.fox-scroll-progress,.fox-hero-glow{display:none!important}}
      `;
      document.head.appendChild(style);
    }

    function addScrollProgress() {
      const bar = document.createElement('div');
      bar.className = 'fox-scroll-progress';
      bar.setAttribute('aria-hidden', 'true');
      document.body.appendChild(bar);
      let frame = 0;
      const update = () => {
        frame = 0;
        const max = document.documentElement.scrollHeight - innerHeight;
        const progress = max > 0 ? Math.min(1, Math.max(0, scrollY / max)) : 0;
        bar.style.transform = `scaleX(${progress})`;
      };
      addEventListener('scroll', () => { if (!frame) frame = requestAnimationFrame(update); }, { passive: true });
      addEventListener('resize', update, { passive: true });
      update();
    }

    function addHeroGlow() {
      const hero = document.querySelector('.mk-hero, .mk-page-hero, .mk-blog-hero, .ff-hero');
      if (!hero || !matchMedia('(pointer:fine)').matches) return;
      const glow = document.createElement('span');
      glow.className = 'fox-hero-glow';
      glow.setAttribute('aria-hidden', 'true');
      hero.appendChild(glow);
      hero.addEventListener('pointerenter', () => animate(glow, { opacity: 1, duration: 350 }));
      hero.addEventListener('pointerleave', () => animate(glow, { opacity: 0, duration: 450 }));
      hero.addEventListener('pointermove', (event) => {
        const rect = hero.getBoundingClientRect();
        glow.style.transform = `translate3d(${event.clientX - rect.left - 140}px,${event.clientY - rect.top - 140}px,0)`;
      }, { passive: true });
    }
  }

  function addButtonFeedback(animate) {
    const selector = '.mk-button, .btn, .client-btn, .power-btn, button:not(.tab), .ob-chip';
    document.querySelectorAll(selector).forEach((element) => {
      if (element.disabled) return;
      const restore = () => animate(element, {
        scale: 1, duration: 280, ease: 'out(4)',
        onComplete: () => element.style.removeProperty('transform'),
      });
      element.addEventListener('pointerdown', () => animate(element, { scale: 0.965, duration: 120, ease: 'out(2)' }));
      element.addEventListener('pointerup', restore);
      element.addEventListener('pointercancel', restore);
      element.addEventListener('pointerleave', restore);
    });
  }

  function addCardIconFeedback(animate) {
    const selector = '.mk-category, .mk-product, .mk-feature, .ff-feature, .admin-stat-card, .client-stat, .service-tile, .ob-card';
    document.querySelectorAll(selector).forEach((card) => {
      const icon = card.querySelector('.mk-category-icon, .mk-feature > i, .ff-feature > i, .admin-stat-icon, .stat-icon, .service-identity > span, .ob-card-placeholder');
      if (!icon) return;
      card.addEventListener('pointerenter', () => animate(icon, { scale: 1.1, rotate: 3, duration: 320, ease: 'out(3)' }));
      card.addEventListener('pointerleave', () => animate(icon, {
        scale: 1, rotate: 0, duration: 380, ease: 'out(3)',
        onComplete: () => icon.style.removeProperty('transform'),
      }));
    });
  }

  function addDetailsMotion(animate) {
    document.querySelectorAll('details').forEach((details) => {
      details.addEventListener('toggle', () => {
        if (!details.open) return;
        const content = Array.from(details.children).filter((child) => child.tagName !== 'SUMMARY');
        animate(content, { opacity: { from: 0 }, y: { from: -8 }, duration: 340, ease: 'out(3)' });
      });
    });
  }

  function observeDynamicContent(reveal, animateNumber) {
    const selector = '.client-provision-card, .client-queue-item, .service-tile, .ob-card, .admin-table tbody tr';
    const keyFor = (element) => {
      const identity = element.querySelector('h2, h3, b, td, .ob-card-title')?.textContent.trim() || element.textContent.trim();
      return `${element.className}:${identity.slice(0, 120)}`;
    };
    const seen = new Set(Array.from(document.querySelectorAll(selector), keyFor));
    const observer = new MutationObserver((mutations) => {
      const candidates = [];
      mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) return;
        if (node.matches(selector)) candidates.push(node);
        candidates.push(...node.querySelectorAll(selector));
      }));
      const added = candidates.filter((element) => {
        const key = keyFor(element);
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });
      if (added.length) reveal(added, { distance: 14, scale: 0.98, stagger: 35, duration: 480 });
      added.forEach((element) => element.querySelectorAll('.client-stat strong, .cards .n').forEach(animateNumber));
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  if (reducedMotion.matches) {
    window.FoxAnimations = Object.freeze({ reveal: () => null, animateNumber: () => null, pulse: () => null });
    return;
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
