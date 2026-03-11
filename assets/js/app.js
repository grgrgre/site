/* ============================================================
   SvityazHOME - Combined JavaScript
   Generated: 2026-02-14
   ============================================================ */

// ====== DEV MODE (client diagnostics toggle) ======
(function () {
  'use strict';

  const STORAGE_KEY = 'svh_dev_mode';
  const params = new URLSearchParams(window.location.search);
  const devParam = (params.get('dev') || '').trim().toLowerCase();
  const turnOn = new Set(['1', 'true', 'on', 'yes']);
  const turnOff = new Set(['0', 'false', 'off', 'no']);

  const persist = (enabled) => {
    try {
      if (enabled) {
        localStorage.setItem(STORAGE_KEY, '1');
      } else {
        localStorage.removeItem(STORAGE_KEY);
      }
    } catch (err) {
      // Ignore storage errors.
    }
  };

  if (turnOn.has(devParam)) persist(true);
  if (turnOff.has(devParam)) persist(false);

  let enabled = false;
  try {
    enabled = localStorage.getItem(STORAGE_KEY) === '1';
  } catch (err) {
    enabled = false;
  }

  window.__SVH_DEV_MODE__ = enabled;
  document.documentElement.dataset.devMode = enabled ? '1' : '0';

  const applyBodyClass = () => {
    document.body.classList.toggle('dev-mode', enabled);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyBodyClass, { once: true });
  } else {
    applyBodyClass();
  }

  const toggleDevMode = () => {
    enabled = !enabled;
    window.__SVH_DEV_MODE__ = enabled;
    document.documentElement.dataset.devMode = enabled ? '1' : '0';
    persist(enabled);
    applyBodyClass();
    window.location.reload();
  };

  document.addEventListener('keydown', (event) => {
    if (event.altKey && event.shiftKey && (event.key === 'D' || event.key === 'd')) {
      event.preventDefault();
      toggleDevMode();
    }
  });

  if (!enabled) return;

  const styleId = 'svh-dev-mode-style';
  if (!document.getElementById(styleId)) {
    const style = document.createElement('style');
    style.id = styleId;
    style.textContent = `
      .svh-dev-badge {
        position: fixed;
        right: 14px;
        bottom: 14px;
        z-index: 9999;
        border: 1px solid rgba(255,255,255,0.28);
        border-radius: 999px;
        padding: 8px 12px;
        background: rgba(11, 20, 28, 0.88);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }
      .svh-dev-badge button {
        border: 0;
        border-radius: 999px;
        background: rgba(255,255,255,0.14);
        color: #fff;
        padding: 4px 8px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 700;
      }
    `;
    document.head.appendChild(style);
  }

  const mountBadge = () => {
    if (document.querySelector('.svh-dev-badge')) return;
    const badge = document.createElement('div');
    badge.className = 'svh-dev-badge';
    badge.innerHTML = '<span>DEV MODE</span><button type=\"button\">OFF</button>';
    const offButton = badge.querySelector('button');
    if (offButton) {
      offButton.addEventListener('click', () => {
        persist(false);
        const url = new URL(window.location.href);
        url.searchParams.delete('dev');
        window.location.replace(url.toString());
      });
    }
    document.body.appendChild(badge);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountBadge, { once: true });
  } else {
    mountBadge();
  }
})();

// ====== MAIN (nav, theme, lightbox, forms, weather, rooms, carousel, admin) ======
// Shared utilities and interactions for SvityazHOME
// Each block guards itself so every page can safely load the same bundle.
(function () {
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
  // Read motion preference once to keep animations accessible.
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Sticky header + active nav */
  const header = $('.site-header');
  const navRoot = $('.nav');
  const navToggle = $('.nav-toggle');
  const navLinks = $('.nav__links');
  const navDropdowns = $$('.nav__dropdown');
  const navFocusableSelector = 'a[href], button:not([disabled]), summary, [tabindex]:not([tabindex="-1"])';
  let navLastFocused = null;
  const detectPageFromPath = () => {
    const path = window.location.pathname.toLowerCase();
    if (path === '/' || path === '/index.html') return 'home';
    if (path.includes('/about/')) return 'about';
    if (path.includes('/gallery/')) return 'gallery';
    if (path.includes('/booking/')) return 'booking';
    if (path.includes('/reviews/')) return 'reviews';
    if (path.includes('/ozero-svityaz/')) return 'lake';
    if (path.includes('/rooms/room-')) return 'room';
    if (path.includes('/rooms/')) return 'rooms';
    if (path.endsWith('/404.html') || path === '/404') return 'error';
    return 'home';
  };

  const applyPageContext = () => {
    let page = document.body.dataset.page;
    if (!page) {
      page = detectPageFromPath();
      document.body.dataset.page = page;
    }

    document.body.classList.add(`page-${page}`);

    if (page === 'room') {
      const roomMatch = window.location.pathname.match(/room-(\d+)/i);
      if (roomMatch) {
        const roomId = Number(roomMatch[1]);
        if (Number.isFinite(roomId)) {
          const hueA = (154 + roomId * 17) % 360;
          const hueB = (hueA + 36) % 360;
          document.body.dataset.roomId = String(roomId);
          document.body.style.setProperty('--room-accent-hue', String(hueA));
          document.body.style.setProperty('--room-accent-hue-2', String(hueB));
        }
      }
    }

    return page;
  };

  const currentPage = applyPageContext();

  const PERF_LITE_KEY = 'svh_perf_lite_mode';
  const setPerfLiteMode = (enabled, persist = false) => {
    const value = Boolean(enabled);
    window.__SVH_PERF_LITE__ = value;
    document.documentElement.classList.toggle('perf-lite', value);
    if (persist) {
      try {
        localStorage.setItem(PERF_LITE_KEY, value ? '1' : '0');
      } catch (err) {
        // Ignore storage errors.
      }
    }
  };

  const detectPerfLiteMode = () => {
    const params = new URLSearchParams(window.location.search);
    const forcePerfLite = params.get('perf') === 'lite' || params.get('lite') === '1';
    const forcePerfFull = params.get('perf') === 'full' || params.get('lite') === '0';
    const isLocalHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
    const prefersReduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const weakCpu = Number.isFinite(navigator.hardwareConcurrency) && navigator.hardwareConcurrency <= 4;
    const mediumCpu = Number.isFinite(navigator.hardwareConcurrency) && navigator.hardwareConcurrency <= 6;
    const lowMemory = typeof navigator.deviceMemory === 'number' && navigator.deviceMemory <= 4;
    const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    const saveData = !!(connection && connection.saveData);
    const slowNetwork = Boolean(connection && typeof connection.effectiveType === 'string' &&
      /^(slow-2g|2g|3g)$/i.test(connection.effectiveType));
    const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
    const noHover = window.matchMedia('(hover: none)').matches;
    const smallViewport = window.matchMedia('(max-width: 1024px)').matches;
    const touchConstrainedDevice = (coarsePointer || noHover || smallViewport) && (mediumCpu || lowMemory || slowNetwork || saveData);

    let storedPerfLite = null;
    try {
      const saved = localStorage.getItem(PERF_LITE_KEY);
      if (saved === '1') storedPerfLite = true;
      if (saved === '0') storedPerfLite = false;
    } catch (err) {
      storedPerfLite = null;
    }

    let perfLite = prefersReduce || saveData || slowNetwork || isLocalHost || weakCpu || lowMemory || touchConstrainedDevice;
    if (storedPerfLite !== null) perfLite = storedPerfLite;
    if (forcePerfLite) perfLite = true;
    if (forcePerfFull) perfLite = false;

    setPerfLiteMode(perfLite, forcePerfLite || forcePerfFull);
    return perfLite;
  };

  const perfLiteAtBoot = detectPerfLiteMode();

  const isLocalHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);

  // Register a service worker only on real hostnames to avoid local cache confusion in development.
  const registerServiceWorker = () => {
    if (!('serviceWorker' in navigator)) return;
    if (isLocalHost) return;
    if (window.location.protocol !== 'https:') return;

    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then((registration) => {
          registration.update().catch(() => {});
        })
        .catch(() => {});
    }, { once: true });
  };
  registerServiceWorker();

  if (!perfLiteAtBoot && 'requestAnimationFrame' in window) {
    let frameCount = 0;
    let start = 0;
    const probeForLowFps = (ts) => {
      if (!start) start = ts;
      frameCount += 1;
      if (ts - start < 1200) {
        window.requestAnimationFrame(probeForLowFps);
        return;
      }
      const fps = (frameCount * 1000) / (ts - start);
      if (fps < 50 && !window.__SVH_PERF_LITE__) {
        setPerfLiteMode(true, true);
        window.dispatchEvent(new Event('svh:perf-lite-enabled'));
      }
    };
    window.requestAnimationFrame(probeForLowFps);
  }

  const setActiveNav = () => {
    $$('[data-nav]').forEach((link) => {
      const isActive = link.dataset.nav === currentPage;
      link.classList.toggle('is-active', isActive);
      if (link.tagName === 'A') {
        if (isActive) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
      }
    });
  };

  const ensureSkipLink = () => {
    const body = document.body;
    const main = $('main');
    if (!body || !main || body.querySelector('.skip-link')) return;
    if (!main.id) main.id = 'main-content';

    const skipLink = document.createElement('a');
    skipLink.className = 'skip-link';
    skipLink.href = `#${main.id}`;
    skipLink.textContent = 'Пропустити до основного контенту';
    body.prepend(skipLink);
  };

  const hardenExternalLinks = () => {
    $$('a[target="_blank"]').forEach((link) => {
      const href = (link.getAttribute('href') || '').trim();
      if (!href) return;
      let url = null;
      try {
        url = new URL(href, window.location.href);
      } catch {
        return;
      }
      if (url.origin === window.location.origin) return;

      const relTokens = new Set((link.getAttribute('rel') || '').split(/\s+/).filter(Boolean));
      relTokens.add('noopener');
      relTokens.add('noreferrer');
      link.setAttribute('rel', Array.from(relTokens).join(' '));
    });
  };

  const initLazyImageState = () => {
    $$('img[loading="lazy"]').forEach((img) => {
      const markLoaded = () => img.classList.add('loaded');
      if (img.complete) {
        markLoaded();
        return;
      }
      img.addEventListener('load', markLoaded, { once: true });
      img.addEventListener('error', markLoaded, { once: true });
    });
  };

  const isMobileNavViewport = () => window.matchMedia('(max-width: 860px)').matches;

  const syncNavToggleLabel = (isOpen) => {
    if (!navToggle) return;
    const label = isOpen ? 'Закрити меню' : 'Відкрити меню';
    navToggle.setAttribute('aria-label', label);
    navToggle.setAttribute('title', label);
  };

  const syncDropdownState = (dropdown) => {
    if (!dropdown) return;
    const summary = dropdown.querySelector('summary');
    if (!summary) return;
    summary.setAttribute('aria-expanded', dropdown.hasAttribute('open') ? 'true' : 'false');
  };

  const syncNavAccessibility = (isOpen = navLinks?.classList.contains('is-open')) => {
    if (!navLinks || !navToggle) return;
    const mobileView = isMobileNavViewport();
    navLinks.setAttribute('aria-hidden', mobileView ? String(!isOpen) : 'false');
    if ('inert' in navLinks) {
      navLinks.inert = mobileView && !isOpen;
    }
    syncNavToggleLabel(Boolean(isOpen));
  };

  const navFocusableElements = () => {
    if (!navLinks) return [];
    return $$(navFocusableSelector, navLinks).filter((element) => element.offsetParent !== null);
  };

  const closeNavMenu = (restoreFocus = false) => {
    if (!navLinks || !navToggle) return;
    navLinks.classList.remove('is-open');
    navToggle.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('nav-open');
    navDropdowns.forEach((dropdown) => dropdown.removeAttribute('open'));
    syncNavAccessibility(false);
    if (restoreFocus && navLastFocused && typeof navLastFocused.focus === 'function') {
      navLastFocused.focus();
    }
  };

  const handleScrollHeader = () => {
    if (!header) return;
    header.classList.toggle('is-scrolled', window.scrollY > 8);
  };

  ensureSkipLink();
  setActiveNav();
  hardenExternalLinks();
  initLazyImageState();
  handleScrollHeader();
  window.addEventListener('scroll', handleScrollHeader, { passive: true });

  if (navToggle && navLinks) {
    if (!navLinks.id) navLinks.id = 'navLinksPrimary';
    if (navRoot && !navRoot.getAttribute('aria-label')) {
      navRoot.setAttribute('aria-label', 'Основна навігація');
    }
    navToggle.setAttribute('aria-controls', navLinks.id);
    navToggle.setAttribute('aria-expanded', 'false');
    syncNavAccessibility(false);

    navToggle.addEventListener('click', () => {
      const isOpen = !navLinks.classList.contains('is-open');
      if (isOpen && document.activeElement instanceof HTMLElement) {
        navLastFocused = document.activeElement;
      }
      navLinks.classList.toggle('is-open', isOpen);
      navToggle.setAttribute('aria-expanded', String(isOpen));
      document.body.classList.toggle('nav-open', isOpen);
      syncNavAccessibility(isOpen);
      if (isOpen && isMobileNavViewport()) {
        const firstFocusable = navFocusableElements()[0];
        if (firstFocusable) firstFocusable.focus();
      }
    });
    document.addEventListener('click', (e) => {
      if (!navLinks.contains(e.target) && !navToggle.contains(e.target)) {
        closeNavMenu();
      }
    });
    navLinks.addEventListener('click', (event) => {
      const targetLink = event.target.closest('a[href]');
      if (targetLink) closeNavMenu();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeNavMenu(true);
        return;
      }
      if (event.key !== 'Tab' || !navLinks.classList.contains('is-open') || !isMobileNavViewport()) {
        return;
      }
      const focusables = navFocusableElements();
      if (focusables.length < 2) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    document.addEventListener('focusin', (event) => {
      if (!isMobileNavViewport()) return;
      if (!navLinks.classList.contains('is-open') && navLinks.contains(event.target)) {
        navToggle.focus();
      }
    });
    window.addEventListener('resize', () => {
      if (window.innerWidth > 860) closeNavMenu();
      syncNavAccessibility(navLinks.classList.contains('is-open'));
    });
  }

  if (navDropdowns.length) {
    navDropdowns.forEach((dropdown) => {
      syncDropdownState(dropdown);
      dropdown.addEventListener('toggle', () => syncDropdownState(dropdown));
    });
    document.addEventListener('click', (event) => {
      navDropdowns.forEach((dropdown) => {
        if (!dropdown.contains(event.target)) {
          dropdown.removeAttribute('open');
          syncDropdownState(dropdown);
        }
      });
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      navDropdowns.forEach((dropdown) => {
        dropdown.removeAttribute('open');
        syncDropdownState(dropdown);
      });
    });
  }

  /* Back button */
  const backLinks = $$('[data-back]');
  backLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      if (window.history.length > 1) {
        event.preventDefault();
        window.history.back();
      }
    });
  });

  /* Room filtering */
  const filterCapacity = $('#filterCapacity');
  const filterType = $('#filterType');
  const resetFilters = $('#resetFilters');
  const roomsEmpty = $('#roomsEmpty');
  const roomsCount = $('#roomsCount');
  const roomsGrid = $('#rooms-grid');
  const roomsFiltersManagedExternally = window.__SVH_ROOMS_FILTERS_MANAGED__ === true || document.body?.dataset?.roomsFiltersManaged === '1';

  const applyRoomFilters = () => {
    const capacity = filterCapacity ? filterCapacity.value : 'all';
    const type = filterType ? filterType.value : 'all';

    // Support both old .room-card and new .room-card-new
    const roomCards = $$('.room-card, .room-card-new');
    let visibleCount = 0;

    roomCards.forEach((card) => {
      const cardCapacity = card.dataset.capacity;
      const cardType = card.dataset.type;

      const matchCapacity = capacity === 'all' || cardCapacity == capacity;
      const matchType = type === 'all' || cardType === type;
      const isVisible = matchCapacity && matchType;

      card.style.display = isVisible ? '' : 'none';
      if (isVisible) visibleCount++;
    });

    if (roomsEmpty) {
      roomsEmpty.style.display = visibleCount === 0 ? '' : 'none';
    }

    if (roomsGrid) {
      roomsGrid.style.display = visibleCount === 0 ? 'none' : '';
    }

    if (roomsCount) {
      roomsCount.textContent = visibleCount;
    }
  };

  if (!roomsFiltersManagedExternally && filterCapacity) {
    filterCapacity.addEventListener('change', applyRoomFilters);
  }
  if (!roomsFiltersManagedExternally && filterType) {
    filterType.addEventListener('change', applyRoomFilters);
  }
  if (!roomsFiltersManagedExternally && resetFilters) {
    resetFilters.addEventListener('click', () => {
      if (filterCapacity) filterCapacity.value = 'all';
      if (filterType) filterType.value = 'all';
      applyRoomFilters();
    });
  }

  const navCtaLink = $('.nav__cta a');
  // Clone the booking CTA into the mobile menu so it stays visible when collapsed.
  if (navLinks && !navLinks.querySelector('.nav__booking')) {
    const bookingLink = navCtaLink ? navCtaLink.cloneNode(true) : document.createElement('a');
    if (!navCtaLink) {
      bookingLink.href = '/booking/';
      bookingLink.textContent = 'Забронювати';
    }
    bookingLink.classList.add('nav__booking');
    if (!bookingLink.classList.contains('btn')) bookingLink.classList.add('btn');
    if (!bookingLink.classList.contains('btn-primary')) bookingLink.classList.add('btn-primary');
    navLinks.appendChild(bookingLink);
  }

  /* Theme toggle */
  const themeToggle = $('.theme-toggle');
  const themeKey = 'theme';
  const root = document.documentElement;
  const themeMedia = window.matchMedia('(prefers-color-scheme: dark)');
  // Avoid theme transitions when reduced motion is requested.
  const prefersReducedThemeMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  const getStoredTheme = () => {
    try {
      return localStorage.getItem(themeKey);
    } catch (err) {
      return null;
    }
  };

  const storeTheme = (value) => {
    try {
      localStorage.setItem(themeKey, value);
    } catch (err) {
      // Ignore storage errors (private mode / disabled storage).
    }
  };

  const applyTheme = (value) => {
    root.setAttribute('data-theme', value);
    if (!themeToggle) return;
    const isDark = value === 'dark';
    themeToggle.setAttribute('aria-pressed', String(isDark));
    themeToggle.setAttribute('title', isDark ? 'Theme: dark' : 'Theme: light');
  };

  const initTheme = () => {
    const stored = getStoredTheme();
    if (stored === 'dark' || stored === 'light') {
      applyTheme(stored);
      return;
    }
    applyTheme(themeMedia.matches ? 'dark' : 'light');
    themeMedia.addEventListener('change', (event) => {
      const live = getStoredTheme();
      if (live === 'dark' || live === 'light') return;
      applyTheme(event.matches ? 'dark' : 'light');
    });
  };

  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
      const next = current === 'dark' ? 'light' : 'dark';
      if (!prefersReducedThemeMotion) {
        root.classList.add('theme-transition');
        window.setTimeout(() => root.classList.remove('theme-transition'), 260);
      }
      storeTheme(next);
      applyTheme(next);
    });
  }
  initTheme();

  /* Test notice modal shown on first visit */
  const testModal = $('#testNotice');
  if (testModal) {
    const storageKey = 'svh_test_notice_seen';
    const closeButtons = $$('[data-modal-close]', testModal);
    const hasSeen = () => {
      try {
        return localStorage.getItem(storageKey) === '1';
      } catch (err) {
        return false;
      }
    };
    const rememberSeen = () => {
      try {
        localStorage.setItem(storageKey, '1');
      } catch (err) {
        // Storage might be blocked; still allow close for this session.
      }
    };
    const openModal = () => {
      testModal.classList.add('is-open');
      testModal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('is-modal-open');
      const focusTarget = testModal.querySelector('[data-modal-close]');
      if (focusTarget) focusTarget.focus();
    };
    const closeModal = () => {
      testModal.classList.remove('is-open');
      testModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('is-modal-open');
      rememberSeen();
    };

    closeButtons.forEach((btn) => btn.addEventListener('click', closeModal));
    testModal.addEventListener('click', (event) => {
      if (event.target === testModal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && testModal.classList.contains('is-open')) {
        closeModal();
      }
    });

    if (!hasSeen()) {
      window.setTimeout(openModal, 120);
    }
  }

  /* Scroll reveal using IntersectionObserver */
  const srItems = $$('[data-sr]');
  if (prefersReducedMotion) {
    srItems.forEach((el) => el.classList.add('is-visible'));
  } else if (srItems.length) {
    const srObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const el = entry.target;
            const delay = el.dataset.srDelay ? Number(el.dataset.srDelay) : 0;
            el.style.transitionDelay = `${delay}ms`;
            el.classList.add('is-visible');
            srObserver.unobserve(el);
          }
        });
      },
      { threshold: 0.18 }
    );
    srItems.forEach((el) => srObserver.observe(el));
  }

  /* Shared 9:16 lightbox (gallery + room pages + review photos) */
  let lightbox;
  let lightboxItems = [];
  let lightboxIndex = 0;
  let lightboxPrevBodyOverflow = '';
  let lightboxTouchStartX = 0;
  let lightboxTouchStartY = 0;
  let lightboxLastFocusedElement = null;
  let lightboxZoomed = false;

  const isVisible = (el) => Boolean(el && (el.offsetParent || el.getClientRects().length));

  const getTriggerSource = (el) => {
    if (!el) return '';
    if (el.tagName === 'A') {
      return el.getAttribute('href') || '';
    }
    if (el.dataset && el.dataset.src) {
      return el.dataset.src;
    }
    return el.currentSrc || el.getAttribute('src') || '';
  };

  const getTriggerCaption = (el) => {
    if (!el) return '';
    if (el.dataset && el.dataset.lightboxCaption) {
      return el.dataset.lightboxCaption;
    }
    if (el.dataset && el.dataset.caption) {
      return el.dataset.caption;
    }
    if (el.tagName === 'A') {
      const nestedImage = el.querySelector('img');
      if (nestedImage && nestedImage.alt) return nestedImage.alt;
      return el.getAttribute('aria-label') || '';
    }
    return el.alt || el.getAttribute('aria-label') || '';
  };

  const mapTriggerToItem = (el) => ({
    src: getTriggerSource(el),
    caption: getTriggerCaption(el),
    trigger: el
  });

  const uniqueItems = (items) => {
    const seen = new Set();
    return items.filter((item) => {
      const key = `${item.src}::${item.caption}`;
      if (!item.src || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  };

  const getCanonicalTrigger = (rawTrigger) => {
    if (!rawTrigger) return null;
    if (rawTrigger.matches('img') && rawTrigger.closest('a.review-image-link')) {
      return rawTrigger.closest('a.review-image-link');
    }
    return rawTrigger;
  };

  const collectLightboxItems = (trigger) => {
    const explicitGroup = trigger.dataset.lightboxGroup || trigger.getAttribute('data-lightbox-group');
    let elements = [];

    if (explicitGroup) {
      elements = Array.from(document.querySelectorAll(`[data-lightbox-group="${explicitGroup}"]`));
    } else if (trigger.closest('.room-carousel')) {
      const carousel = trigger.closest('.room-carousel');
      elements = Array.from(carousel.querySelectorAll('.room-carousel__slide img'));
    } else if (trigger.closest('.gallery-item') || trigger.closest('.gallery-masonry')) {
      elements = Array.from(document.querySelectorAll('.category-section .gallery-item img')).filter(isVisible);
    } else if (trigger.closest('.room-card') || trigger.closest('.room-card-new')) {
      const roomGrid = trigger.closest('.grid, .room-grid, .rooms-grid') || document;
      elements = Array.from(roomGrid.querySelectorAll('.room-card__media img, .room-card-new__image img')).filter(isVisible);
    } else if (trigger.closest('.review-card__images')) {
      const reviewGallery = trigger.closest('.review-card__images');
      elements = Array.from(reviewGallery.querySelectorAll('a.review-image-link'));
    } else {
      elements = [trigger];
    }

    const items = uniqueItems(elements.map(mapTriggerToItem));
    const currentSrc = getTriggerSource(trigger);
    let index = items.findIndex((item) => item.src === currentSrc);
    if (index < 0) index = 0;

    return { items, index };
  };

  const escapeAttribute = (value) => String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  const setLightboxZoom = (enabled) => {
    lightboxZoomed = Boolean(enabled);
    if (!lightbox) return;
    lightbox.classList.toggle('is-zoomed', lightboxZoomed);
    const zoomBtn = $('.lightbox__zoom', lightbox);
    const imageEl = $('.lightbox__img', lightbox);
    if (zoomBtn) {
      zoomBtn.setAttribute('aria-pressed', lightboxZoomed ? 'true' : 'false');
      zoomBtn.setAttribute('aria-label', lightboxZoomed ? 'Зменшити фото' : 'Збільшити фото');
      zoomBtn.textContent = lightboxZoomed ? '−' : '+';
    }
    if (imageEl) {
      imageEl.style.cursor = lightboxZoomed ? 'zoom-out' : 'zoom-in';
    }
  };

  const toggleLightboxZoom = () => {
    setLightboxZoom(!lightboxZoomed);
  };

  const renderLightboxThumbs = () => {
    if (!lightbox) return;
    const thumbsEl = $('.lightbox__thumbs', lightbox);
    if (!thumbsEl) return;
    if (lightboxItems.length <= 1) {
      thumbsEl.hidden = true;
      thumbsEl.innerHTML = '';
      return;
    }

    thumbsEl.hidden = false;
    thumbsEl.innerHTML = lightboxItems.map((item, index) => {
      const isActive = index === lightboxIndex;
      const src = escapeAttribute(item?.src || '');
      return `
        <button
          class="lightbox__thumb ${isActive ? 'is-active' : ''}"
          type="button"
          data-index="${index}"
          aria-label="Фото ${index + 1}"
          aria-current="${isActive ? 'true' : 'false'}">
          <img src="${src}" alt="Фото ${index + 1}" loading="lazy">
        </button>
      `;
    }).join('');
  };

  const updateLightboxView = () => {
    if (!lightbox || !lightboxItems.length) return;
    const current = lightboxItems[lightboxIndex];
    const imageEl = $('.lightbox__img', lightbox);
    const captionEl = $('.lightbox__caption', lightbox);
    const counterEl = $('.lightbox__counter', lightbox);
    const navButtons = $$('.lightbox__nav', lightbox);
    const hasMultiple = lightboxItems.length > 1;

    imageEl.src = current.src;
    imageEl.alt = current.caption || 'Фото';
    captionEl.textContent = current.caption || '';
    counterEl.textContent = `${lightboxIndex + 1} / ${lightboxItems.length}`;
    navButtons.forEach((button) => {
      button.hidden = !hasMultiple;
      button.setAttribute('aria-hidden', String(!hasMultiple));
    });
    counterEl.hidden = !hasMultiple;
    renderLightboxThumbs();
    setLightboxZoom(false);

    if (hasMultiple) {
      const prev = lightboxItems[(lightboxIndex - 1 + lightboxItems.length) % lightboxItems.length];
      const next = lightboxItems[(lightboxIndex + 1) % lightboxItems.length];
      [prev, next].forEach((item) => {
        if (!item || !item.src) return;
        const preload = new Image();
        preload.decoding = 'async';
        preload.src = item.src;
      });
    }
  };

  const goToLightboxIndex = (nextIndex) => {
    if (!lightboxItems.length) return;
    lightboxIndex = (nextIndex + lightboxItems.length) % lightboxItems.length;
    updateLightboxView();
  };

  const closeLightbox = () => {
    if (!lightbox) return;
    lightbox.classList.remove('is-open');
    lightbox.setAttribute('aria-hidden', 'true');
    lightboxItems = [];
    const thumbsEl = $('.lightbox__thumbs', lightbox);
    if (thumbsEl) {
      thumbsEl.innerHTML = '';
      thumbsEl.hidden = true;
    }
    setLightboxZoom(false);
    if (!document.body.classList.contains('is-modal-open')) {
      document.body.style.overflow = lightboxPrevBodyOverflow;
    }
    if (lightboxLastFocusedElement && typeof lightboxLastFocusedElement.focus === 'function') {
      window.setTimeout(() => {
        try {
          lightboxLastFocusedElement.focus({ preventScroll: true });
        } catch (err) {
          lightboxLastFocusedElement.focus();
        }
      }, 0);
    }
    lightboxLastFocusedElement = null;
  };

  const ensureLightbox = () => {
    if (lightbox) return lightbox;
    lightbox = document.createElement('div');
    lightbox.className = 'lightbox';
    lightbox.setAttribute('aria-hidden', 'true');
    lightbox.innerHTML = `
      <div class="lightbox__inner" role="dialog" aria-modal="true" aria-label="Перегляд фото">
        <div class="lightbox__toolbar">
          <button class="lightbox__zoom" type="button" aria-label="Збільшити фото" aria-pressed="false">+</button>
          <button class="lightbox__close" type="button" aria-label="Закрити">&times;</button>
        </div>
        <div class="lightbox__stage">
          <button class="lightbox__nav lightbox__nav--prev" type="button" aria-label="Попереднє фото">&#10094;</button>
          <img class="lightbox__img" alt="">
          <button class="lightbox__nav lightbox__nav--next" type="button" aria-label="Наступне фото">&#10095;</button>
        </div>
        <div class="lightbox__meta">
          <p class="lightbox__caption"></p>
          <span class="lightbox__counter"></span>
        </div>
        <div class="lightbox__thumbs" hidden></div>
      </div>`;
    document.body.appendChild(lightbox);

    lightbox.addEventListener('click', (event) => {
      if (event.target === lightbox || event.target.classList.contains('lightbox__close')) {
        closeLightbox();
        return;
      }
      if (event.target.classList.contains('lightbox__nav--prev')) {
        goToLightboxIndex(lightboxIndex - 1);
        return;
      }
      if (event.target.classList.contains('lightbox__nav--next')) {
        goToLightboxIndex(lightboxIndex + 1);
        return;
      }
      if (event.target.classList.contains('lightbox__zoom')) {
        toggleLightboxZoom();
        return;
      }

      const thumb = event.target.closest('.lightbox__thumb');
      if (thumb) {
        const idx = Number.parseInt(thumb.getAttribute('data-index') || '', 10);
        if (Number.isFinite(idx)) {
          goToLightboxIndex(idx);
        }
      }
    });

    const stage = $('.lightbox__stage', lightbox);
    const imageEl = $('.lightbox__img', lightbox);
    if (imageEl) {
      imageEl.addEventListener('dblclick', (event) => {
        event.preventDefault();
        toggleLightboxZoom();
      });
      imageEl.addEventListener('click', () => {
        if (window.matchMedia('(pointer: fine)').matches) {
          toggleLightboxZoom();
        }
      });
    }
    if (stage) {
      stage.addEventListener('touchstart', (event) => {
        if (!lightbox.classList.contains('is-open')) return;
        lightboxTouchStartX = event.changedTouches[0].screenX;
        lightboxTouchStartY = event.changedTouches[0].screenY;
      }, { passive: true });

      stage.addEventListener('touchend', (event) => {
        if (!lightbox.classList.contains('is-open')) return;
        if (lightboxZoomed) return;
        const endX = event.changedTouches[0].screenX;
        const endY = event.changedTouches[0].screenY;
        const dx = lightboxTouchStartX - endX;
        const dy = Math.abs(lightboxTouchStartY - endY);
        if (Math.abs(dx) > 44 && dy < 56) {
          if (dx > 0) goToLightboxIndex(lightboxIndex + 1);
          else goToLightboxIndex(lightboxIndex - 1);
        }
      }, { passive: true });
    }

    document.addEventListener('keydown', (event) => {
      if (!lightbox || !lightbox.classList.contains('is-open')) return;
      if (event.key === 'Escape') closeLightbox();
      if (event.key === 'ArrowLeft') goToLightboxIndex(lightboxIndex - 1);
      if (event.key === 'ArrowRight') goToLightboxIndex(lightboxIndex + 1);
      if (event.key === 'z' || event.key === 'Z') toggleLightboxZoom();
    });

    return lightbox;
  };

  const openLightbox = (items, startIndex = 0) => {
    if (!Array.isArray(items) || !items.length) return;
    const lb = ensureLightbox();
    lightboxItems = items;
    lightboxIndex = Math.max(0, Math.min(startIndex, lightboxItems.length - 1));
    lightboxLastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    lightboxPrevBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    setLightboxZoom(false);
    updateLightboxView();
    lb.classList.add('is-open');
    lb.setAttribute('aria-hidden', 'false');
    const closeButton = $('.lightbox__close', lb);
    if (closeButton) {
      window.requestAnimationFrame(() => closeButton.focus({ preventScroll: true }));
    }
  };

  const annotateStaticLightboxTriggers = () => {
    $$('.gallery-item img, .room-carousel__slide img, .room-card__media img, .room-card-new__image img').forEach((img) => {
      if (!img.hasAttribute('data-lightbox')) {
        img.setAttribute('data-lightbox', 'image');
      }
      if (!img.hasAttribute('tabindex')) {
        img.setAttribute('tabindex', '0');
      }
      img.setAttribute('role', 'button');
      if (!img.hasAttribute('aria-label')) {
        const caption = img.alt ? `Відкрити фото: ${img.alt}` : 'Відкрити фото';
        img.setAttribute('aria-label', caption);
      }
      if (img.dataset.lightboxBound === '1') return;
      img.dataset.lightboxBound = '1';
      img.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          img.click();
        }
      });
    });
  };

  annotateStaticLightboxTriggers();

  document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    const rawTrigger = event.target.closest('[data-lightbox], .review-image-link, .gallery-item img, .room-carousel__slide img');
    if (!rawTrigger) return;

    const trigger = getCanonicalTrigger(rawTrigger);
    if (!trigger) return;
    if (trigger.closest('[data-lightbox-ignore]')) return;

    const source = getTriggerSource(trigger);
    if (!source) return;

    event.preventDefault();
    const payload = collectLightboxItems(trigger);
    if (!payload.items.length) return;
    openLightbox(payload.items, payload.index);
  });

  /* Gallery accordion */
  const albumToggles = $$('.gallery-album__toggle');
  const albumBlocks = $$('.gallery-album');

  const updateAlbumHeight = (album) => {
    const content = $('.gallery-album__content', album);
    if (!content) return;
    content.style.maxHeight = album.classList.contains('is-open') ? `${content.scrollHeight}px` : '0px';
  };

  if (albumToggles.length) {
    albumToggles.forEach((toggle) => {
      toggle.addEventListener('click', () => {
        const album = toggle.closest('.gallery-album');
        if (!album) return;
        const isOpen = album.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(isOpen));

        const hint = $('.gallery-album__hint', album);
        if (hint) {
          const openText = hint.dataset.openText || hint.textContent;
          const closedText = hint.dataset.closedText || hint.textContent;
          hint.textContent = isOpen ? openText : closedText;
        }

        toggle.textContent = isOpen ? '-' : '+';
        updateAlbumHeight(album);
      });
    });

    albumBlocks.forEach(updateAlbumHeight);
    window.addEventListener('resize', () => albumBlocks.forEach(updateAlbumHeight));
    window.addEventListener('load', () => albumBlocks.forEach(updateAlbumHeight));
  }

  /* Progressive image loading defaults */
  const optimizeImages = () => {
    $$('img').forEach((img) => {
      const isHeroImage = Boolean(img.closest('.hero, .hero-premium, .hero-compact'));
      if (!img.hasAttribute('loading')) {
        img.loading = isHeroImage ? 'eager' : 'lazy';
      }
      if (!img.hasAttribute('decoding')) {
        img.decoding = 'async';
      }
      if (!img.hasAttribute('fetchpriority')) {
        if (isHeroImage) {
          img.fetchPriority = 'high';
        } else if (img.loading === 'lazy') {
          img.fetchPriority = 'low';
        }
      }
    });
  };
  optimizeImages();

  /* Booking form */
  const bookingForm = $('#bookingForm');
  const bookingMessage = $('#bookingMessage');
  const summaryList = $('#bookingSummary');

  const getLocalISODate = (date) => {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().split('T')[0];
  };

  const todayISO = getLocalISODate(new Date());
  $$('input[type="date"]').forEach((input) => input.setAttribute('min', todayISO));

  const field = (name) => (bookingForm ? bookingForm.querySelector(`[name="${name}"]`) : null);
  const nameInput = field('name');
  const emailInput = field('email');
  const phoneInput = field('phone');
  const guestsInput = field('guests');
  const checkinInput = field('checkin');
  const checkoutInput = field('checkout');
  const roomTypeInput = field('roomType');
  const notesInput = field('notes');

  if (checkinInput && checkoutInput) {
    if (checkinInput.value) {
      checkoutInput.min = checkinInput.value;
    }
    checkinInput.addEventListener('change', () => {
      checkoutInput.min = checkinInput.value || todayISO;
    });
  }

  // Used to validate guest count against the selected room type.
  const ROOM_CAPACITY = {
    'two-standard': 2,
    'three-lux': 3,
    'three-standard': 3,
    'three-economy': 3,
    'four-lux': 4,
    'six-standard': 6,
    'bunk-7': 7,
    'bunk-8': 8,
    'eight-lux': 8,
  };
  const ALLOWED_GUESTS = new Set(['2', '3', '4', '6', '7', '8']);

  const normalize = (value) => (value || '').toString().trim();
  const parseLocalDate = (value) => {
    if (!value) return null;
    const date = new Date(`${value}T00:00:00`);
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const setInvalid = (input) => {
    if (!input) return;
    input.classList.add('is-invalid');
    input.setAttribute('aria-invalid', 'true');
  };

  const clearInvalid = (input) => {
    if (!input) return;
    input.classList.remove('is-invalid');
    input.removeAttribute('aria-invalid');
  };

  const clearAllInvalid = () => {
    if (!bookingForm) return;
    bookingForm.querySelectorAll('.input.is-invalid').forEach((input) => clearInvalid(input));
  };

  const showErrors = (errors) => {
    if (!bookingMessage) return;
    bookingMessage.innerHTML = `<ul>${errors.map((item) => `<li>${item}</li>`).join('')}</ul>`;
    bookingMessage.classList.add('error');
  };

  const showStatus = (textValue) => {
    if (!bookingMessage) return;
    bookingMessage.textContent = textValue;
    bookingMessage.classList.remove('error');
  };

  const updateSummary = () => {
    if (!summaryList || !bookingForm) return;
    const data = new FormData(bookingForm);
    const items = [
      ["Ім'я", data.get('name')],
      ['Email', data.get('email')],
      ['Телефон', data.get('phone')],
      ['Заїзд', data.get('checkin')],
      ['Виїзд', data.get('checkout')],
      ['Гості', data.get('guests')],
      ['Тип номера', data.get('roomType')],
      ['Додатково', data.get('extras')],
      ['Коментар', data.get('notes')],
    ];
    summaryList.innerHTML = items
      .map(([label, value]) => `<li><strong>${label}:</strong> ${value || '—'}</li>`)
      .join('');
  };

  if (bookingForm) {
    bookingForm.addEventListener('input', (event) => {
      if (event.target && event.target.classList.contains('input')) {
        clearInvalid(event.target);
      }
      if (bookingMessage && bookingMessage.classList.contains('error')) {
        bookingMessage.textContent = '';
        bookingMessage.classList.remove('error');
      }
      updateSummary();
    });

    bookingForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (bookingMessage) {
        bookingMessage.textContent = '';
        bookingMessage.className = 'message';
      }

      const data = new FormData(bookingForm);
      if (data.get('_gotcha')) return; // honeypot

      clearAllInvalid();

      const errors = [];
      const nameValue = normalize(nameInput && nameInput.value);
      const emailValue = normalize(emailInput && emailInput.value);
      const phoneValue = normalize(phoneInput && phoneInput.value);
      const guestsValue = normalize(guestsInput && guestsInput.value);
      const roomTypeValue = normalize(roomTypeInput && roomTypeInput.value);
      const notesValue = normalize(notesInput && notesInput.value);

      if (!nameValue) {
        errors.push("Вкажіть ім'я.");
        setInvalid(nameInput);
      } else if (nameValue.length < 2 || nameValue.length > 60 || /\d/.test(nameValue)) {
        errors.push("Ім'я має містити 2–60 символів і не містити цифр.");
        setInvalid(nameInput);
      }

      if (!emailValue) {
        errors.push('Вкажіть email.');
        setInvalid(emailInput);
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
        errors.push('Формат email некоректний.');
        setInvalid(emailInput);
      }

      if (!phoneValue) {
        errors.push('Вкажіть телефон.');
        setInvalid(phoneInput);
      } else {
        const digits = phoneValue.replace(/\D/g, '');
        if (digits.length < 9 || digits.length > 15) {
          errors.push('Телефон має містити 9–15 цифр.');
          setInvalid(phoneInput);
        }
      }

      const guestsNumber = parseInt(guestsValue, 10);
      if (!guestsValue) {
        errors.push('Оберіть кількість гостей.');
        setInvalid(guestsInput);
      } else if (!ALLOWED_GUESTS.has(guestsValue)) {
        errors.push('Невірна кількість гостей.');
        setInvalid(guestsInput);
      } else if (!Number.isFinite(guestsNumber)) {
        errors.push('Невірна кількість гостей.');
        setInvalid(guestsInput);
      }

      if (!roomTypeValue) {
        errors.push('Оберіть тип номера.');
        setInvalid(roomTypeInput);
      } else {
        const maxGuests = ROOM_CAPACITY[roomTypeValue];
        if (Number.isFinite(guestsNumber) && maxGuests && guestsNumber > maxGuests) {
          errors.push('Кількість гостей перевищує місткість номера.');
          setInvalid(guestsInput);
          setInvalid(roomTypeInput);
        }
      }

      const checkinDate = parseLocalDate(normalize(checkinInput && checkinInput.value));
      const checkoutDate = parseLocalDate(normalize(checkoutInput && checkoutInput.value));
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      if (!checkinDate) {
        errors.push('Вкажіть дату заїзду.');
        setInvalid(checkinInput);
      } else if (checkinDate < today) {
        errors.push('Дата заїзду не може бути в минулому.');
        setInvalid(checkinInput);
      }

      if (!checkoutDate) {
        errors.push('Вкажіть дату виїзду.');
        setInvalid(checkoutInput);
      } else if (checkinDate && checkoutDate <= checkinDate) {
        errors.push('Дата виїзду має бути після дати заїзду.');
        setInvalid(checkoutInput);
      }

      if (notesValue.length > 500) {
        errors.push('Коментар занадто довгий (макс. 500 символів).');
        setInvalid(notesInput);
      }

      if (errors.length) {
        showErrors(errors);
        return;
      }

      if (nameInput) nameInput.value = nameValue;
      if (emailInput) emailInput.value = emailValue;
      if (phoneInput) phoneInput.value = phoneValue;
      if (notesInput) notesInput.value = notesValue;

      showStatus('Надсилаємо запит...');

      try {
        const payload = new FormData(bookingForm);
        const response = await fetch(bookingForm.action, {
          method: 'POST',
          body: payload,
          headers: { Accept: 'application/json' },
        });

        if (response.ok) {
          showStatus("Запит надіслано. Ми зв'яжемося з вами найближчим часом.");
          bookingForm.reset();
          if (checkoutInput) checkoutInput.min = todayISO;
          updateSummary();
        } else {
          throw new Error('Formspree error');
        }
      } catch (err) {
        showErrors(['Не вдалося надіслати запит. Спробуйте пізніше.']);
      }
    });
    updateSummary();
  }

  /* Weather widget */
  const weatherCards = $$('[data-weather]');
  const weatherIcons = {
    clear: `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <circle cx="24" cy="24" r="7"></circle>
      <path d="M24 6v6"></path>
      <path d="M24 36v6"></path>
      <path d="M6 24h6"></path>
      <path d="M36 24h6"></path>
      <path d="M11 11l4 4"></path>
      <path d="M33 33l4 4"></path>
      <path d="M11 37l4-4"></path>
      <path d="M33 15l4-4"></path>
    </svg>`,
    cloudy: `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path d="M16 33h17a7 7 0 0 0 0-14 10 10 0 0 0-19.4 2.7A6.5 6.5 0 0 0 16 33z"></path>
    </svg>`,
    rain: `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path d="M16 30h17a7 7 0 0 0 0-14 10 10 0 0 0-19.4 2.7A6.5 6.5 0 0 0 16 30z"></path>
      <path d="M18 35v5"></path>
      <path d="M26 35v5"></path>
      <path d="M34 35v5"></path>
    </svg>`,
    snow: `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path d="M16 30h17a7 7 0 0 0 0-14 10 10 0 0 0-19.4 2.7A6.5 6.5 0 0 0 16 30z"></path>
      <circle cx="19" cy="37" r="1.6" fill="currentColor" stroke="none"></circle>
      <circle cx="27" cy="39" r="1.6" fill="currentColor" stroke="none"></circle>
      <circle cx="35" cy="37" r="1.6" fill="currentColor" stroke="none"></circle>
    </svg>`,
    fog: `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path d="M12 20h24"></path>
      <path d="M9 26h30"></path>
      <path d="M14 32h20"></path>
    </svg>`,
    thunder: `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path d="M16 30h17a7 7 0 0 0 0-14 10 10 0 0 0-19.4 2.7A6.5 6.5 0 0 0 16 30z"></path>
      <path d="M25 31l-5 9h6l-4 8"></path>
    </svg>`,
  };

  const weatherLookup = {
    0: { label: 'Ясно', icon: 'clear' },
    1: { label: 'Переважно ясно', icon: 'clear' },
    2: { label: 'Мінлива хмарність', icon: 'cloudy' },
    3: { label: 'Хмарно', icon: 'cloudy' },
    45: { label: 'Туман', icon: 'fog' },
    48: { label: 'Паморозь', icon: 'fog' },
    51: { label: 'Мряка', icon: 'rain' },
    53: { label: 'Мряка', icon: 'rain' },
    55: { label: 'Сильна мряка', icon: 'rain' },
    56: { label: 'Крижана мряка', icon: 'rain' },
    57: { label: 'Крижана мряка', icon: 'rain' },
    61: { label: 'Дощ', icon: 'rain' },
    63: { label: 'Дощ', icon: 'rain' },
    65: { label: 'Сильний дощ', icon: 'rain' },
    66: { label: 'Крижаний дощ', icon: 'rain' },
    67: { label: 'Крижаний дощ', icon: 'rain' },
    71: { label: 'Сніг', icon: 'snow' },
    73: { label: 'Сніг', icon: 'snow' },
    75: { label: 'Сильний сніг', icon: 'snow' },
    77: { label: 'Сніжна крупа', icon: 'snow' },
    80: { label: 'Зливи', icon: 'rain' },
    81: { label: 'Зливи', icon: 'rain' },
    82: { label: 'Сильні зливи', icon: 'rain' },
    85: { label: 'Снігопад', icon: 'snow' },
    86: { label: 'Снігопад', icon: 'snow' },
    95: { label: 'Гроза', icon: 'thunder' },
    96: { label: 'Гроза з градом', icon: 'thunder' },
    99: { label: 'Гроза з градом', icon: 'thunder' },
  };

  const formatNumber = (value) => (Number.isFinite(value) ? Math.round(value) : null);
  const formatWithUnit = (value, unit) => {
    const rounded = formatNumber(value);
    return rounded === null ? '--' : `${rounded}${unit}`;
  };

  const formatTime = (value) => {
    if (!value) return '--:--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--:--';
    return date.toLocaleTimeString('uk-UA', { hour: '2-digit', minute: '2-digit' });
  };

  const loadWeather = async (card) => {
    const lat = Number.parseFloat(card.dataset.weatherLat);
    const lon = Number.parseFloat(card.dataset.weatherLon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

    const tempEl = card.querySelector('[data-weather-temp]');
    const summaryEl = card.querySelector('[data-weather-summary]');
    const feelsEl = card.querySelector('[data-weather-feels]');
    const windEl = card.querySelector('[data-weather-wind]');
    const humidityEl = card.querySelector('[data-weather-humidity]');
    const rangeEl = card.querySelector('[data-weather-range]');
    const updatedEl = card.querySelector('[data-weather-updated]');
    const iconEl = card.querySelector('[data-weather-icon]');

    const setText = (el, value) => {
      if (el) el.textContent = value;
    };

    const setIcon = (key) => {
      if (iconEl) iconEl.innerHTML = weatherIcons[key] || '';
    };

    try {
      const params = new URLSearchParams({
        latitude: lat,
        longitude: lon,
        current: 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m',
        daily: 'temperature_2m_max,temperature_2m_min',
        timezone: 'auto',
        wind_speed_unit: 'ms',
      });
      const response = await fetch(`https://api.open-meteo.com/v1/forecast?${params.toString()}`, {
        cache: 'no-store',
      });

      if (!response.ok) throw new Error('Weather response not ok');
      const data = await response.json();
      const current = data.current;
      if (!current) throw new Error('Weather data missing');

      const meta = weatherLookup[current.weather_code] || { label: 'Мінлива хмарність', icon: 'cloudy' };
      const max = formatNumber(data.daily && data.daily.temperature_2m_max ? data.daily.temperature_2m_max[0] : null);
      const min = formatNumber(data.daily && data.daily.temperature_2m_min ? data.daily.temperature_2m_min[0] : null);

      setText(tempEl, formatNumber(current.temperature_2m) ?? '--');
      setText(summaryEl, meta.label);
      setText(feelsEl, formatWithUnit(current.apparent_temperature, '°C'));
      setText(windEl, formatWithUnit(current.wind_speed_10m, ' м/с'));
      setText(humidityEl, formatWithUnit(current.relative_humidity_2m, '%'));
      setText(rangeEl, Number.isFinite(max) && Number.isFinite(min) ? `${max}°/${min}°` : '--°/--°');
      setText(updatedEl, `Оновлено: ${formatTime(current.time)}`);
      setIcon(meta.icon);
    } catch (err) {
      card.classList.add('is-error');
      setText(summaryEl, 'Немає даних про погоду');
      setText(updatedEl, 'Спробуйте пізніше');
      setIcon('cloudy');
    }
  };

  if (weatherCards.length) {
    weatherCards.forEach((card) => {
      loadWeather(card);
    });
  }

  /* Room data bindings for prices/text/cover images */
  // Room metadata used to populate card/detail content across pages.
  const ROOMS_DATA_URL = '/api/rooms.php?action=list';
  const roomPathRegex = /room-(\d+)/i;

  const fetchRoomsData = async () => {
    try {
      const response = await fetch(ROOMS_DATA_URL, { cache: 'no-store' });
      if (!response.ok) return null;
      const data = await response.json();
      if (data.success && data.rooms) {
        // Convert rooms array to object keyed by id for compatibility
        const roomsMap = {};
        data.rooms.forEach(r => { roomsMap[r.id] = r; });
        return { rooms: roomsMap };
      }
      return null;
    } catch (err) {
      return null;
    }
  };

  const getRoomIdFromText = (text) => {
    if (!text) return null;
    if (!/номер/i.test(text)) return null;
    const match = text.match(/(\d{1,3})/);
    return match ? match[1] : null;
  };

  const getRoomIdFromCard = (card) => {
    const link = card.querySelector('a[href*="room-"]');
    if (link) {
      const match = link.getAttribute('href').match(roomPathRegex);
      if (match) return match[1];
    }
    const heading = card.querySelector('h3');
    return getRoomIdFromText(heading ? heading.textContent : '');
  };

  const getGuestsWordCompact = (count) => {
    if (count === 1) return 'гість';
    if (count >= 2 && count <= 4) return 'гості';
    return 'гостей';
  };

  const ensureRoomMeta = (card) => {
    let meta = card.querySelector('.room-meta');
    if (meta) return meta;
    meta = document.createElement('div');
    meta.className = 'room-meta';
    const actions = card.querySelector('.hero__actions');
    if (actions) {
      card.insertBefore(meta, actions);
    } else {
      card.appendChild(meta);
    }
    return meta;
  };

  const setPriceBadge = (container, price) => {
    if (!price) return;
    let badge = container.querySelector('.room-price');
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'pill room-price';
      container.appendChild(badge);
    }
    badge.textContent = price;
  };

  const updateRoomCard = (card, room) => {
    if (!room) return;
    const roomId = Number.parseInt(room.id, 10);
    const roomType = String(room.type || 'standard').toLowerCase();
    const roomCapacity = Number.parseInt(room.capacity || room.guests, 10);

    if (Number.isFinite(roomId) && roomId > 0) {
      card.dataset.roomId = String(roomId);
    }
    if (Number.isFinite(roomCapacity) && roomCapacity > 0) {
      card.dataset.capacity = String(roomCapacity);
    }
    if (roomType) {
      card.dataset.type = roomType;
    }

    if (card.classList.contains('room-card-new')) {
      const link = card.querySelector('.room-card-new__link');
      const title = card.querySelector('.room-card-new__title');
      const summary = card.querySelector('.room-card-new__desc');
      const image = card.querySelector('.room-card-new__image img');
      const capacity = card.querySelector('.room-card-new__capacity span');
      const typeBadge = card.querySelector('.room-card-new__badges .room-card-new__badge');

      if (link && Number.isFinite(roomId) && roomId > 0) {
        link.setAttribute('href', `/rooms/room-${roomId}/`);
      }
      if (room.title && title) title.textContent = room.title;
      if (room.summary && summary) summary.textContent = room.summary;
      if (room.cover && image) {
        image.src = room.cover;
        image.alt = room.title || `Номер ${roomId || ''}`.trim();
      }
      if (Number.isFinite(roomCapacity) && roomCapacity > 0 && capacity) {
        capacity.textContent = String(roomCapacity);
      }
      if (typeBadge) {
        typeBadge.textContent = formatType(roomType);
      }
      return;
    }

    const heading = card.querySelector('h3');
    const summary = card.querySelector('p');
    const image = card.querySelector('.room-card__media img');
    const actionLink = card.querySelector('.hero__actions a[href*="room-"]');
    if (actionLink && Number.isFinite(roomId) && roomId > 0) {
      actionLink.setAttribute('href', `/rooms/room-${roomId}/`);
    }
    if (room.title && heading) heading.textContent = room.title;
    if (room.summary && summary) summary.textContent = room.summary;
    if (room.cover && image) {
      image.src = room.cover;
      image.alt = room.title || `Номер ${roomId || ''}`.trim();
    }

    const meta = ensureRoomMeta(card);
    const pills = meta.querySelectorAll('.pill');
    if (Number.isFinite(roomCapacity) && roomCapacity > 0) {
      if (pills[0]) pills[0].textContent = `${roomCapacity} ${getGuestsWordCompact(roomCapacity)}`;
    }
    if (pills[1]) pills[1].textContent = formatType(roomType);
    setPriceBadge(meta, room.price || (room.pricePerNight ? `${room.pricePerNight} грн` : ''));
  };

  const updateRoomDetail = (roomId, room) => {
    if (!roomId || !room) return;
    const sectionHead = document.querySelector('.section-head');
    if (sectionHead) {
      const title = sectionHead.querySelector('h1, h2');
      if (room.title && title) title.textContent = room.title;
      if (room.price && title) {
        let badge = sectionHead.querySelector('.room-price');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'pill room-price';
          title.insertAdjacentElement('afterend', badge);
        }
        badge.textContent = room.price;
      }
      if (room.summary) {
        const paragraphs = Array.from(sectionHead.querySelectorAll('p'));
        const summary = paragraphs.find((p) => !p.classList.contains('kicker'));
        if (summary) summary.textContent = room.summary;
      }
    }
    const desc = document.querySelector('.room-desc p');
    if (room.description && desc) desc.textContent = room.description;
    const preview = document.querySelector('.preview-grid img');
    if (room.cover && preview) preview.src = room.cover;
  };

  const initRoomBindings = async () => {
    const hasRoomCards = document.querySelector('.room-card, .room-card-new');
    const roomIdFromPath = window.location.pathname.match(roomPathRegex);
    if (!hasRoomCards && !roomIdFromPath) return;
    const data = await fetchRoomsData();
    if (!data || !data.rooms) return;

    if (hasRoomCards) {
      document.querySelectorAll('.room-card, .room-card-new').forEach((card) => {
        const roomId = getRoomIdFromCard(card);
        if (!roomId) return;
        updateRoomCard(card, data.rooms[roomId]);
      });
    }

    if (roomIdFromPath) {
      const roomId = roomIdFromPath[1];
      updateRoomDetail(roomId, data.rooms[roomId]);
    }
  };

  initRoomBindings();

  // ====== SITE CONTENT (editable JSON blocks for public pages) ======
  (function () {
    const page = document.body?.dataset?.page || '';
    const CONTENT_URL = '/api/content.php';

    const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));

    const setText = (selector, value) => {
      if (value === undefined || value === null || value === '') return;
      document.querySelectorAll(selector).forEach((node) => {
        node.textContent = String(value);
      });
    };

    const setLink = (selector, href, label) => {
      document.querySelectorAll(selector).forEach((node) => {
        if (href) node.setAttribute('href', href);
        if (label && (node.hasAttribute('data-replace-label') || /\d|@/.test((node.textContent || '').trim()))) {
          node.textContent = label;
        }
      });
    };

    const setMeta = (selector, value, attribute = 'content') => {
      if (!value) return;
      const node = document.querySelector(selector);
      if (node) {
        node.setAttribute(attribute, value);
      }
    };

    const applySeo = (seo) => {
      if (!seo || typeof seo !== 'object') return;
      if (seo.title) {
        document.title = seo.title;
        setMeta('meta[property="og:title"]', seo.title);
        setMeta('meta[name="twitter:title"]', seo.title);
      }
      if (seo.description) {
        setMeta('meta[name="description"]', seo.description);
        setMeta('meta[property="og:description"]', seo.description);
        setMeta('meta[name="twitter:description"]', seo.description);
      }
    };

    const applyContacts = (contacts) => {
      if (!contacts || typeof contacts !== 'object') return;

      const phoneRaw = String(contacts.phone || '').trim();
      const phoneHref = phoneRaw ? `tel:${phoneRaw.replace(/\s+/g, '')}` : '';
      const phoneLabel = String(contacts.phone_label || phoneRaw).trim();
      const email = String(contacts.email || '').trim();
      const address = String(contacts.address || '').trim();
      const instagramUrl = String(contacts.instagram_url || '').trim();
      const instagramLabel = String(contacts.instagram_label || '@svityazhome').trim();
      const tiktokUrl = String(contacts.tiktok_url || '').trim();
      const tiktokLabel = String(contacts.tiktok_label || '@svityazhome').trim();
      const note = String(contacts.booking_note || '').trim();
      const mapUrl = String(contacts.map_url || '').trim();

      if (phoneHref) {
        document.querySelectorAll('a[href^="tel:"], [data-site-phone-link]').forEach((node) => {
          node.setAttribute('href', phoneHref);
          if (node.hasAttribute('data-site-phone-link') || (node.children.length === 0 && /\d/.test((node.textContent || '').trim()))) {
            node.textContent = phoneLabel;
          }
        });
      }

      if (email) {
        document.querySelectorAll('a[href^="mailto:"], [data-site-email-link]').forEach((node) => {
          node.setAttribute('href', `mailto:${email}`);
          if (node.hasAttribute('data-site-email-link') || (node.children.length === 0 && /@/.test((node.textContent || '').trim()))) {
            node.textContent = email;
          }
        });
      }

      if (address) {
        setText('[data-site-address]', address);
      }

      if (note) {
        setText('[data-site-booking-note]', note);
      }

      if (instagramUrl) {
        document.querySelectorAll('a[href*="instagram.com"], [data-site-instagram-link]').forEach((node) => {
          node.setAttribute('href', instagramUrl);
          if (node.hasAttribute('data-site-instagram-link')) {
            node.textContent = instagramLabel;
          }
        });
      }

      if (tiktokUrl) {
        document.querySelectorAll('a[href*="tiktok.com"], [data-site-tiktok-link]').forEach((node) => {
          node.setAttribute('href', tiktokUrl);
          if (node.hasAttribute('data-site-tiktok-link')) {
            node.textContent = tiktokLabel;
          }
        });
      }

      if (mapUrl) {
        document.querySelectorAll('[data-site-map-link]').forEach((node) => {
          node.setAttribute('href', mapUrl);
        });
      }
    };

    const renderHome = (home) => {
      if (!home || typeof home !== 'object') return;

      const hero = home.hero || {};
      setText('[data-home-hero-eyebrow]', hero.eyebrow);
      setText('[data-home-hero-title]', hero.title);
      setText('[data-home-hero-accent]', hero.accent);
      setText('[data-home-hero-subtitle]', hero.subtitle);
      setText('[data-home-cta-primary-text]', hero.primary_cta_text);
      setText('[data-home-cta-secondary-text]', hero.secondary_cta_text);
      setLink('[data-home-cta-primary-link]', hero.primary_cta_url, null);
      setLink('[data-home-cta-secondary-link]', hero.secondary_cta_url, null);

      const highlights = Array.isArray(hero.highlights) ? hero.highlights : [];
      const highlightsNode = document.querySelector('[data-home-highlights]');
      if (highlightsNode && highlights.length > 0) {
        highlightsNode.innerHTML = highlights.map((item) => `<li>${escapeHtml(item)}</li>`).join('');
      }

      const intro = home.intro || {};
      setText('[data-home-intro-label]', intro.label);
      setText('[data-home-intro-title]', intro.title);
      setText('[data-home-intro-text]', intro.text);

      const benefitsNode = document.querySelector('[data-home-benefits]');
      const benefits = Array.isArray(home.benefits) ? home.benefits : [];
      if (benefitsNode && benefits.length > 0) {
        benefitsNode.innerHTML = benefits.map((item) => `
          <article class="benefit-card">
            <h3>${escapeHtml(item.title || '')}</h3>
            <p>${escapeHtml(item.text || '')}</p>
          </article>
        `).join('');
      }

      const story = home.story || {};
      setText('[data-home-story-label]', story.label);
      setText('[data-home-story-title]', story.title);
      setText('[data-home-story-text]', story.text);
      const storyImage = document.querySelector('[data-home-story-image]');
      if (storyImage && story.image) {
        storyImage.setAttribute('src', story.image);
        storyImage.setAttribute('alt', story.image_alt || story.title || 'SvityazHOME');
      }

      const faqNode = document.querySelector('[data-home-faq]');
      const faqItems = Array.isArray(home.faq) ? home.faq : [];
      if (faqNode && faqItems.length > 0) {
        faqNode.innerHTML = faqItems.map((item, index) => `
          <details class="faq-item"${index === 0 ? ' open' : ''}>
            <summary class="faq-item__question">${escapeHtml(item.question || '')}</summary>
            <div class="faq-item__answer"><p>${escapeHtml(item.answer || '')}</p></div>
          </details>
        `).join('');
      }

      const cta = home.cta || {};
      setText('[data-home-final-cta-title]', cta.title);
      setText('[data-home-final-cta-text]', cta.text);
      setText('[data-home-final-primary-text]', cta.primary_text);
      setText('[data-home-final-secondary-text]', cta.secondary_text);
      setLink('[data-home-final-primary-link]', cta.primary_url, null);
      setLink('[data-home-final-secondary-link]', cta.secondary_url, null);
    };

    const renderGallery = (gallery) => {
      if (!gallery || typeof gallery !== 'object') return;
      const filtersNode = document.querySelector('[data-gallery-filters]');
      const gridNode = document.querySelector('[data-gallery-grid]');
      if (!filtersNode || !gridNode) return;

      setText('[data-gallery-title]', gallery.title);
      setText('[data-gallery-subtitle]', gallery.subtitle);

      const items = Array.isArray(gallery.items) ? gallery.items.filter((item) => item && item.src) : [];
      const categories = ['all', ...new Set(items.map((item) => String(item.category || 'Інше').trim()).filter(Boolean))];

      filtersNode.innerHTML = categories.map((category, index) => {
        const label = category === 'all' ? 'Усі фото' : category;
        return `
          <button class="gallery-filter-chip${index === 0 ? ' is-active' : ''}" type="button" data-filter="${escapeHtml(category)}">
            ${escapeHtml(label)}
          </button>
        `;
      }).join('');

      gridNode.innerHTML = items.map((item) => {
        const category = String(item.category || 'Інше').trim();
        const wideClass = item.featured ? ' gallery-item--wide' : '';
        return `
          <figure class="gallery-item${wideClass}" data-category="${escapeHtml(category)}">
            <img
              src="${escapeHtml(item.src)}"
              alt="${escapeHtml(item.alt || 'Фото SvityazHOME')}"
              loading="lazy"
              data-lightbox="image"
              tabindex="0"
              role="button"
              aria-label="Відкрити фото: ${escapeHtml(item.alt || 'Фото SvityazHOME')}">
            <figcaption class="gallery-caption">
              <span>${escapeHtml(category)}</span>
            </figcaption>
          </figure>
        `;
      }).join('');

      const filterButtons = Array.from(filtersNode.querySelectorAll('[data-filter]'));
      const filterItems = Array.from(gridNode.querySelectorAll('.gallery-item'));
      const applyFilter = (value) => {
        filterButtons.forEach((button) => {
          button.classList.toggle('is-active', button.getAttribute('data-filter') === value);
        });
        filterItems.forEach((item) => {
          const matches = value === 'all' || item.getAttribute('data-category') === value;
          item.hidden = !matches;
        });
      };

      filterButtons.forEach((button) => {
        button.addEventListener('click', () => applyFilter(button.getAttribute('data-filter') || 'all'));
      });
      applyFilter('all');
    };

    const loadContent = async () => {
      try {
        const response = await fetch(CONTENT_URL, { cache: 'no-store' });
        if (!response.ok) return;

        const payload = await response.json();
        const content = payload?.content;
        if (!content || typeof content !== 'object') return;

        applyContacts(content.contacts || {});
        applySeo(content.seo?.[page] || {});

        if (page === 'home') {
          renderHome(content.home || {});
        }
        if (page === 'gallery') {
          renderGallery(content.gallery || {});
        }
      } catch (error) {
        console.warn('Site content load failed:', error);
      }
    };

    loadContent();
  })();

  /* ========================================
     Room Carousel - Use shared global lightbox
     ======================================== */
  const initRoomCarousel = () => {
    const carousels = document.querySelectorAll('.room-carousel');

    carousels.forEach((carousel, carouselIndex) => {
      carousel.classList.remove('fullscreen');

      const groupId = `room-carousel-${carouselIndex + 1}`;
      const slides = carousel.querySelectorAll('.room-carousel__slide');

      slides.forEach((slide, slideIndex) => {
        const image = slide.querySelector('img');
        if (!image) return;
        image.setAttribute('data-lightbox', 'image');
        image.setAttribute('data-lightbox-group', groupId);
        image.setAttribute('data-lightbox-caption', image.alt || `Фото ${slideIndex + 1}`);
      });

      const legacyControls = carousel.querySelectorAll('.room-carousel__btn, .room-carousel__counter, .room-carousel__close');
      legacyControls.forEach((control) => {
        control.setAttribute('hidden', 'hidden');
        control.setAttribute('aria-hidden', 'true');
      });
    });
  };

  initRoomCarousel();

  // ===============================================================
  // ADMIN INLINE EDITING SYSTEM
  // ===============================================================

  const AdminEditor = {
    isAdmin: false,
    isEditMode: false,
    changes: new Map(),

    readStoredChanges() {
      try {
        const raw = localStorage.getItem('svh_content_changes');
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
      } catch (error) {
        try {
          localStorage.removeItem('svh_content_changes');
        } catch (storageError) {}
        return {};
      }
    },

    init() {
      // Перевіряємо чи є авторизація адміна
      const authData = localStorage.getItem('svh_admin_auth');
      if (authData) {
        try {
          const { timestamp } = JSON.parse(authData);
          const expiry = 24 * 60 * 60 * 1000;
          if (Date.now() - timestamp < expiry) {
            this.isAdmin = true;
            this.createToolbar();
            this.markEditableElements();
          }
        } catch (e) {}
      }

      // Ctrl+E для увімкнення режиму редагування (тільки для адмінів)
      document.addEventListener('keydown', (e) => {
        if (this.isAdmin && e.ctrlKey && e.key === 'e') {
          e.preventDefault();
          this.toggleEditMode();
        }
      });
    },

    createToolbar() {
      const toolbar = document.createElement('div');
      toolbar.className = 'admin-toolbar';
      toolbar.innerHTML = `
        <button class="admin-toolbar__btn admin-toolbar__btn--ghost" id="adminToggleEdit" title="Ctrl+E">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          <span>Редагувати</span>
        </button>
        <button class="admin-toolbar__btn" id="adminSave" style="display:none;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
            <polyline points="17 21 17 13 7 13 7 21"/>
            <polyline points="7 3 7 8 15 8"/>
          </svg>
          <span>Зберегти</span>
        </button>
        <button class="admin-toolbar__btn admin-toolbar__btn--danger" id="adminCancel" style="display:none;">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"/>
            <line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
          <span>Скасувати</span>
        </button>
      `;
      document.body.appendChild(toolbar);

      // Badge
      const badge = document.createElement('div');
      badge.className = 'admin-badge';
      badge.id = 'adminBadge';
      badge.textContent = '\u270F\uFE0F Режим редагування';
      document.body.appendChild(badge);

      // Toast container
      const toast = document.createElement('div');
      toast.className = 'admin-toast';
      toast.id = 'adminToast';
      document.body.appendChild(toast);

      // Events
      document.getElementById('adminToggleEdit').addEventListener('click', () => this.toggleEditMode());
      document.getElementById('adminSave').addEventListener('click', () => this.saveChanges());
      document.getElementById('adminCancel').addEventListener('click', () => this.cancelChanges());

      // Show toolbar
      setTimeout(() => toolbar.classList.add('visible'), 500);
    },

    markEditableElements() {
      // Позначаємо елементи, які можна редагувати
      const editableSelectors = [
        '.hero-premium__title',
        '.hero-premium__tagline',
        '.section-title',
        '.section-subtitle',
        '.card-feature h3',
        '.card-feature p',
        '.room-card-new__title',
        '.room-detail__title',
        '.room-detail__price',
        '.room-detail__description p',
        '.about-hero__title',
        '.about-hero__subtitle',
        'h1', 'h2', 'h3',
        '.faq-answer p'
      ];

      editableSelectors.forEach(selector => {
        $$(selector).forEach((el, idx) => {
          if (!el.closest('.admin-toolbar') && !el.closest('.site-header') && !el.closest('.site-footer')) {
            el.dataset.editable = `${selector}-${idx}`;
            el.dataset.originalContent = el.innerHTML;
          }
        });
      });
    },

    toggleEditMode() {
      this.isEditMode = !this.isEditMode;
      document.body.classList.toggle('admin-edit-mode', this.isEditMode);

      const toggleBtn = document.getElementById('adminToggleEdit');
      const saveBtn = document.getElementById('adminSave');
      const cancelBtn = document.getElementById('adminCancel');
      const badge = document.getElementById('adminBadge');

      if (this.isEditMode) {
        toggleBtn.style.display = 'none';
        saveBtn.style.display = 'flex';
        cancelBtn.style.display = 'flex';
        badge.classList.add('visible');
        this.enableEditing();
        this.showToast('Натисніть на текст для редагування', 'info');
      } else {
        toggleBtn.style.display = 'flex';
        saveBtn.style.display = 'none';
        cancelBtn.style.display = 'none';
        badge.classList.remove('visible');
        this.disableEditing();
      }
    },

    enableEditing() {
      $$('[data-editable]').forEach(el => {
        el.contentEditable = 'true';
        el.addEventListener('input', this.handleInput.bind(this));
        el.addEventListener('paste', this.handlePaste.bind(this));
      });
    },

    disableEditing() {
      $$('[data-editable]').forEach(el => {
        el.contentEditable = 'false';
        el.removeEventListener('input', this.handleInput);
      });
    },

    handleInput(e) {
      const el = e.target;
      const key = el.dataset.editable;
      const newContent = el.innerHTML;
      const originalContent = el.dataset.originalContent;

      if (newContent !== originalContent) {
        this.changes.set(key, {
          element: el,
          original: originalContent,
          new: newContent
        });
      } else {
        this.changes.delete(key);
      }

      // Оновлюємо кнопку збереження
      const saveBtn = document.getElementById('adminSave');
      if (this.changes.size > 0) {
        saveBtn.innerHTML = `
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
            <polyline points="17 21 17 13 7 13 7 21"/>
            <polyline points="7 3 7 8 15 8"/>
          </svg>
          <span>Зберегти (${this.changes.size})</span>
        `;
      }
    },

    handlePaste(e) {
      e.preventDefault();
      const text = e.clipboardData.getData('text/plain');
      const sel = window.getSelection();
      if (sel.rangeCount) {
        const range = sel.getRangeAt(0);
        range.deleteContents();
        range.insertNode(document.createTextNode(text));
        range.collapse(false);
      }
    },

    saveChanges() {
      if (this.changes.size === 0) {
        this.showToast('Немає змін для збереження', 'info');
        return;
      }

      // Зберігаємо зміни в localStorage для демонстрації
      // В реальному проекті тут буде API запит
      const savedChanges = {};
      const pageId = window.location.pathname;

      this.changes.forEach((change, key) => {
        savedChanges[key] = change.new;
        change.element.dataset.originalContent = change.new;
      });

      const allSaved = this.readStoredChanges();
      allSaved[pageId] = { ...allSaved[pageId], ...savedChanges };
      try {
        localStorage.setItem('svh_content_changes', JSON.stringify(allSaved));
      } catch (error) {}

      this.changes.clear();
      this.showToast(`Збережено ${Object.keys(savedChanges).length} змін`, 'success');
      this.toggleEditMode();
    },

    cancelChanges() {
      // Відновлюємо оригінальний контент
      this.changes.forEach((change, key) => {
        change.element.innerHTML = change.original;
      });
      this.changes.clear();
      this.showToast('Зміни скасовано', 'info');
      this.toggleEditMode();
    },

    loadSavedChanges() {
      const allSaved = this.readStoredChanges();
      const pageId = window.location.pathname;
      const pageChanges = allSaved[pageId];

      if (pageChanges) {
        Object.entries(pageChanges).forEach(([key, content]) => {
          const el = document.querySelector(`[data-editable="${key}"]`);
          if (el) {
            el.innerHTML = content;
            el.dataset.originalContent = content;
          }
        });
      }
    },

    showToast(message, type = 'info') {
      const toast = document.getElementById('adminToast');
      toast.textContent = message;
      toast.className = `admin-toast ${type} visible`;

      setTimeout(() => {
        toast.classList.remove('visible');
      }, 3000);
    }
  };

  // Ініціалізація після завантаження DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      AdminEditor.init();
      AdminEditor.loadSavedChanges();
    });
  } else {
    AdminEditor.init();
    AdminEditor.loadSavedChanges();
  }

})();



// ====== ANIMATIONS (scroll, lazy images, counters, parallax) ======
/**
 * SvityazHOME - Advanced Animations Library
 * Scroll-based animations using Intersection Observer
 * @version 1.0.0
 */

(function() {
  'use strict';

  const isPerfLite = () => document.documentElement.classList.contains('perf-lite') || window.__SVH_PERF_LITE__ === true;
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const animatedSelector = `
      .animate-on-scroll,
      .card-feature,
      .room-card-new,
      .gallery-image,
      .review-card,
      .text-reveal,
      .img-reveal,
      .progress-bar,
      [data-animate]
    `;

  // Configuration
  const CONFIG = {
    threshold: 0.1,           // When 10% of element is visible
    rootMargin: '0px 0px -50px 0px', // Trigger 50px before element enters viewport
    staggerDelay: 50,         // ms between staggered items
    defaultDuration: 600,     // ms
    defaultEasing: 'cubic-bezier(0.16, 1, 0.3, 1)'
  };

  // State
  let observer = null;
  let isInitialized = false;

  function applyPerfLiteVisibility() {
    document.querySelectorAll(animatedSelector).forEach((el) => {
      el.classList.add('visible', 'is-visible');
      el.style.animation = 'none';
      el.style.transition = 'none';
      el.style.transitionDelay = '0ms';
      el.style.transform = 'none';
      el.style.willChange = 'auto';
    });
    if (observer) {
      observer.disconnect();
      observer = null;
    }
  }

  /**
   * Initialize all animations
   */
  function init() {
    if (isInitialized) return;
    isInitialized = true;

    // Add page-loaded class after DOM is ready
    requestAnimationFrame(() => {
      if (document.body) document.body.classList.add('page-loaded');
    });

    // Initialize lazy image loading
    initLazyImages();

    if (isPerfLite() || prefersReducedMotion) {
      applyPerfLiteVisibility();
      return;
    }

    // Initialize scroll animations
    initScrollAnimations();

    // Initialize counter animations
    initCounters();

    // Initialize parallax effects
    initParallax();

    // Initialize hover effects
    initHoverEffects();

    // Initialize smooth anchor scrolling
    initSmoothAnchors();

    // Animations initialized
  }

  /**
   * Scroll-based animations with Intersection Observer
   */
  function initScrollAnimations() {
    const animatedElements = document.querySelectorAll(animatedSelector);
    if (animatedElements.length === 0) return;

    // Create observer
    observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');

          // Optionally unobserve after animation
          if (!entry.target.dataset.animateRepeat) {
            observer.unobserve(entry.target);
          }
        } else if (entry.target.dataset.animateRepeat) {
          entry.target.classList.remove('visible');
        }
      });
    }, {
      threshold: CONFIG.threshold,
      rootMargin: CONFIG.rootMargin
    });

    // Observe elements with animation classes
    animatedElements.forEach((el, index) => {
      // Set stagger delay for grid items
      if (el.classList.contains('room-card-new') ||
          el.classList.contains('gallery-image') ||
          el.classList.contains('review-card')) {
        const delay = (index % 9) * CONFIG.staggerDelay;
        el.style.transitionDelay = `${delay}ms`;
      }

      // If element is already in viewport, make it visible immediately
      const rect = el.getBoundingClientRect();
      if (rect.top < window.innerHeight && rect.bottom > 0) {
        el.classList.add('visible');
      } else {
        observer.observe(el);
      }
    });

    // Handle data-animate attribute
    document.querySelectorAll('[data-animate]').forEach((el) => {
      const animation = el.dataset.animate;
      el.classList.add('animate-on-scroll', animation);
    });
  }

  /**
   * Lazy image loading with fade effect
   */
  function initLazyImages() {
    const lazyImages = document.querySelectorAll('img[loading="lazy"]');

    lazyImages.forEach((img) => {
      if (img.complete) {
        img.classList.add('loaded');
      } else {
        img.addEventListener('load', () => {
          img.classList.add('loaded');
        }, { once: true });
      }
    });
  }

  /**
   * Counter animations (count up numbers)
   */
  function initCounters() {
    const counters = document.querySelectorAll('[data-counter]');
    if (counters.length === 0) return;

    const counterObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          animateCounter(entry.target);
          counterObserver.unobserve(entry.target);
        }
      });
    }, { threshold: 0.5 });

    counters.forEach((counter) => {
      counterObserver.observe(counter);
    });
  }

  /**
   * Animate a single counter
   */
  function animateCounter(element) {
    const target = parseInt(element.dataset.counter, 10);
    if (!Number.isFinite(target)) return;
    if (isPerfLite()) {
      element.textContent = target.toLocaleString('uk-UA');
      return;
    }

    const duration = parseInt(element.dataset.counterDuration, 10) || 2000;
    const start = 0;
    const startTime = performance.now();

    function updateCounter(currentTime) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);

      // Easing function (ease-out-cubic)
      const easedProgress = 1 - Math.pow(1 - progress, 3);
      const current = Math.floor(start + (target - start) * easedProgress);

      element.textContent = current.toLocaleString('uk-UA');

      if (progress < 1) {
        requestAnimationFrame(updateCounter);
      } else {
        element.textContent = target.toLocaleString('uk-UA');
      }
    }

    requestAnimationFrame(updateCounter);
  }

  /**
   * Parallax scrolling effects
   */
  function initParallax() {
    const parallaxElements = document.querySelectorAll('[data-parallax]');

    if (parallaxElements.length === 0) return;
    const hasFinePointer = window.matchMedia('(pointer: fine)').matches && window.matchMedia('(hover: hover)').matches;
    if (!hasFinePointer) return;

    let ticking = false;

    function updateParallax() {
      const scrollY = window.scrollY;

      parallaxElements.forEach((el) => {
        const speed = parseFloat(el.dataset.parallax) || 0.5;
        const rect = el.getBoundingClientRect();
        const centerY = rect.top + rect.height / 2;
        const viewportCenter = window.innerHeight / 2;
        const offset = (centerY - viewportCenter) * speed;

        el.style.transform = `translateY(${offset}px)`;
      });

      ticking = false;
    }

    window.addEventListener('scroll', () => {
      if (!ticking) {
        requestAnimationFrame(updateParallax);
        ticking = true;
      }
    }, { passive: true });
  }

  /**
   * Enhanced hover effects
   */
  function initHoverEffects() {
    // Add ripple effect to buttons
    document.querySelectorAll('.btn, .button, [data-ripple]').forEach((btn) => {
      btn.classList.add('btn-ripple');
    });

    const hasFinePointer = window.matchMedia('(pointer: fine)').matches && window.matchMedia('(hover: hover)').matches;
    if (!hasFinePointer) return;

    // Magnetic effect for CTA buttons (optional)
    document.querySelectorAll('[data-magnetic]').forEach((el) => {
      el.addEventListener('mousemove', (e) => {
        const rect = el.getBoundingClientRect();
        const x = e.clientX - rect.left - rect.width / 2;
        const y = e.clientY - rect.top - rect.height / 2;

        el.style.transform = `translate(${x * 0.1}px, ${y * 0.1}px)`;
      });

      el.addEventListener('mouseleave', () => {
        el.style.transform = '';
      });
    });

    // Tilt effect for cards (optional)
    document.querySelectorAll('[data-tilt]').forEach((el) => {
      const maxTilt = parseInt(el.dataset.tilt, 10) || 5;

      el.addEventListener('mousemove', (e) => {
        const rect = el.getBoundingClientRect();
        const x = (e.clientX - rect.left) / rect.width;
        const y = (e.clientY - rect.top) / rect.height;

        const tiltX = (y - 0.5) * maxTilt * 2;
        const tiltY = (0.5 - x) * maxTilt * 2;

        el.style.transform = `perspective(1000px) rotateX(${tiltX}deg) rotateY(${tiltY}deg)`;
      });

      el.addEventListener('mouseleave', () => {
        el.style.transform = '';
      });
    });
  }

  /**
   * Smooth anchor scrolling
   */
  function initSmoothAnchors() {
    document.querySelectorAll('a[href^="#"]:not([href="#"])').forEach((anchor) => {
      anchor.addEventListener('click', (e) => {
        const targetId = anchor.getAttribute('href');
        const targetElement = document.querySelector(targetId);

        if (targetElement) {
          e.preventDefault();

          const headerOffset = 100;
          const elementPosition = targetElement.getBoundingClientRect().top;
          const offsetPosition = elementPosition + window.scrollY - headerOffset;

          window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
          });
        }
      });
    });
  }

  /**
   * Create a typing animation
   * @param {HTMLElement} element - Target element
   * @param {string} text - Text to type
   * @param {number} speed - Typing speed in ms
   */
  function typeWriter(element, text, speed = 50) {
    let index = 0;
    element.textContent = '';

    function type() {
      if (index < text.length) {
        element.textContent += text.charAt(index);
        index++;
        setTimeout(type, speed);
      }
    }

    type();
  }

  /**
   * Reveal text character by character
   * @param {HTMLElement} element - Target element
   */
  function revealText(element) {
    const text = element.textContent;
    element.innerHTML = text.split('').map(char =>
      `<span style="opacity:0;transition:opacity 0.1s">${char === ' ' ? '&nbsp;' : char}</span>`
    ).join('');

    element.querySelectorAll('span').forEach((span, i) => {
      setTimeout(() => {
        span.style.opacity = '1';
      }, i * 30);
    });
  }

  /**
   * Add scroll progress indicator
   */
  function initScrollProgress() {
    const progressBar = document.createElement('div');
    progressBar.className = 'scroll-progress';
    progressBar.innerHTML = '<div class="scroll-progress__bar"></div>';
    document.body.appendChild(progressBar);

    const bar = progressBar.querySelector('.scroll-progress__bar');

    window.addEventListener('scroll', () => {
      const scrollHeight = document.documentElement.scrollHeight - window.innerHeight;
      const progress = (window.scrollY / scrollHeight) * 100;
      bar.style.width = `${progress}%`;
    }, { passive: true });
  }

  /**
   * Utility: Debounce function
   */
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  /**
   * Utility: Throttle function
   */
  function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
      if (!inThrottle) {
        func.apply(this, args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    };
  }

  // Export for global use
  window.SvityazAnimations = {
    init,
    typeWriter,
    revealText,
    animateCounter,
    initScrollProgress
  };

  function scheduleInit() {
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(() => init(), { timeout: 700 });
      return;
    }
    window.setTimeout(init, 0);
  }

  // Auto-initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scheduleInit, { once: true });
  } else {
    scheduleInit();
  }

  window.addEventListener('svh:perf-lite-enabled', applyPerfLiteVisibility);

})();



// ====== ROOM DATA (individual room page loader) ======
/**
 * Room Data Loader
 * Динамічно завантажує дані номера з JSON файлу
 */
(function() {
  'use strict';

  // Отримуємо ID номера з URL
  function getRoomIdFromUrl() {
    const path = window.location.pathname;
    const match = path.match(/room-(\d+)/);
    return match ? parseInt(match[1], 10) : null;
  }

  // Форматування типу номера
  function formatType(type) {
    const types = {
      'lux': 'люкс',
      'standard': 'стандарт',
      'bunk': 'двоярусний',
      'economy': 'економ',
      'future': 'у підготовці'
    };
    return types[type] || type;
  }

  function fallbackRoomData(roomId) {
    const capacityFallback = {
      1: 3, 2: 3, 3: 4, 4: 2, 5: 4,
      6: 4, 7: 4, 8: 4, 9: 6, 10: 6,
      11: 4, 12: 6, 13: 8, 14: 2, 15: 2,
      16: 2, 17: 3, 18: 3, 19: 6, 20: 6
    };
    const typeFallback = { 1: 'lux', 9: 'bunk', 10: 'bunk', 11: 'lux', 12: 'bunk', 13: 'lux', 18: 'economy', 19: 'future', 20: 'future' };
    const capacity = capacityFallback[roomId] || 2;
    const type = typeFallback[roomId] || 'standard';
    const price = Math.max(900, 1500 + capacity * 280 + (type === 'lux' ? 450 : 0) - (type === 'economy' ? 250 : 0) - (type === 'future' ? 100 : 0));
    const cover = `/storage/uploads/rooms/room-${roomId}/cover.webp`;
    return {
      id: roomId,
      title: `Номер ${roomId}`,
      summary: `Затишний номер №${roomId} для відпочинку біля озера Світязь.`,
      description: `Номер ${roomId} підходить для ${capacity} гостей. Для уточнення деталей бронювання зверніться до адміністратора.`,
      type,
      capacity,
      pricePerNight: price,
      amenities: ['Wi-Fi', 'Приватна ванна', 'Телевізор'],
      rules: ['Паління в номері заборонено', 'Шум після 22:00 заборонено'],
      images: [cover],
      cover
    };
  }

  // Завантаження та відображення даних номера
  async function loadRoomData() {
    const roomId = getRoomIdFromUrl();
    if (!roomId) return;

    try {
      // Try API first
      let room = null;
      let allRooms = [];
      try {
        const apiRes = await fetch(`/api/rooms.php?action=room&id=${roomId}`);
        if (apiRes.ok) {
          const apiData = await apiRes.json();
          if (apiData.success && apiData.room) room = apiData.room;
        }
      } catch (e) { /* fallback */ }

      try {
        const listRes = await fetch('/api/rooms.php?action=list', { cache: 'no-store' });
        if (listRes.ok) {
          const listData = await listRes.json();
          if (listData.success && Array.isArray(listData.rooms)) {
            allRooms = listData.rooms;
            if (!room) {
              room = allRooms.find((item) => Number.parseInt(item.id, 10) === roomId) || null;
            }
          }
        }
      } catch (e) { /* fallback */ }

      if (!room) room = fallbackRoomData(roomId);

      if (room) updatePageWithRoomData(room, allRooms);
    } catch (error) {
      console.error('Error loading room data:', error);
    }
  }

  // Оновлення сторінки даними з JSON
  function updatePageWithRoomData(room, allRooms = []) {
    // Оновлюємо заголовок сторінки
    document.title = `${room.title || 'Номер ' + room.id} — SvityazHOME`;

    // Оновлюємо meta description
    const metaDesc = document.querySelector('meta[name="description"]');
    if (metaDesc && room.summary) {
      metaDesc.setAttribute('content', room.summary);
    }

    // Оновлюємо заголовок H1
    const h1 = document.querySelector('.room-detail__header h1');
    if (h1) {
      h1.textContent = room.title || `Номер ${room.id}`;
    }

    // Оновлюємо мета-інформацію (гості, тип, ціна)
    const roomMeta = document.querySelector('.room-detail__header .room-meta');
    if (roomMeta) {
      const capacity = Number.parseInt(room.capacity || room.guests, 10) || 0;
      const pricePerNight = Number.parseInt(room.pricePerNight, 10) || 0;
      roomMeta.innerHTML = `
        <span class="pill" data-capacity>${capacity} ${getGuestsWord(capacity)}</span>
        <span class="pill" data-type>${escapeHtml(formatType(room.type || 'standard'))}</span>
        <span class="price-pill" data-price>${pricePerNight} грн / ніч</span>
      `;
    }

    // Оновлюємо опис
    const descriptionEl = document.querySelector('.room-detail .content > p');
    if (descriptionEl && room.description) {
      descriptionEl.textContent = room.description;
      descriptionEl.innerHTML = descriptionEl.innerHTML.replace(/\n/g, '<br>');
    }

    // Оновлюємо зручності
    const amenitiesList = document.querySelector('.list-check');
    if (amenitiesList && room.amenities && room.amenities.length > 0) {
      amenitiesList.innerHTML = room.amenities
        .map(amenity => `<li>${escapeHtml(amenity)}</li>`)
        .join('');
    }

    // Оновлюємо правила
    const rulesList = document.querySelector('.list-bullet');
    if (rulesList && room.rules && room.rules.length > 0) {
      rulesList.innerHTML = room.rules
        .map(rule => `<li>${escapeHtml(rule)}</li>`)
        .join('');
    }

    // Оновлюємо карусель зображень
    updateCarousel(room);

    // Оновлюємо блок "Інші номери"
    if (Array.isArray(allRooms) && allRooms.length > 0) {
      updateRelatedRooms(room, allRooms);
    }
  }

  // Оновлення каруселі зображень
  function updateCarousel(room) {
    const carouselContainer = document.querySelector('.room-carousel__container');
    if (!carouselContainer) return;

    if (!room.images || room.images.length === 0) {
      return;
    }

    carouselContainer.innerHTML = room.images
      .map(img => `
        <div class="room-carousel__slide">
          <img src="${escapeHtml(roomImagePath(img))}" alt="${escapeHtml(room.title || ('Номер ' + room.id))}" loading="lazy">
        </div>
      `)
      .join('');

    carouselContainer.querySelectorAll('.room-carousel__slide img').forEach((image, index) => {
      image.setAttribute('data-lightbox', 'image');
      image.setAttribute('data-lightbox-group', `room-carousel-${room.id || 'detail'}`);
      image.setAttribute('data-lightbox-caption', `${room.title || ('Номер ' + room.id)} — фото ${index + 1}`);
    });
  }

  function roomImagePath(value) {
    const path = String(value || '').trim();
    if (!path) return '/assets/images/placeholders/no-image.svg';
    return path;
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
  }

  function updateRelatedRooms(currentRoom, allRooms) {
    const grid = document.querySelector('.section--alt .grid.grid-3');
    if (!grid) return;

    const currentId = Number.parseInt(currentRoom.id, 10);
    const currentType = String(currentRoom.type || '').toLowerCase();
    const currentCapacity = Number.parseInt(currentRoom.capacity || currentRoom.guests, 10) || 0;

    const related = allRooms
      .filter((room) => Number.parseInt(room.id, 10) !== currentId)
      .sort((a, b) => {
        const aType = String(a.type || '').toLowerCase();
        const bType = String(b.type || '').toLowerCase();
        const aCap = Number.parseInt(a.capacity || a.guests, 10) || 0;
        const bCap = Number.parseInt(b.capacity || b.guests, 10) || 0;
        const aPrice = Number.parseInt(a.pricePerNight, 10) || 0;
        const bPrice = Number.parseInt(b.pricePerNight, 10) || 0;

        const aScore = (aType === currentType ? -100 : 0) + Math.abs(aCap - currentCapacity) * 5 + Math.round(aPrice / 500);
        const bScore = (bType === currentType ? -100 : 0) + Math.abs(bCap - currentCapacity) * 5 + Math.round(bPrice / 500);
        return aScore - bScore;
      })
      .slice(0, 3);

    if (related.length === 0) return;

    grid.innerHTML = related.map((room) => {
      const roomId = Number.parseInt(room.id, 10);
      const roomType = String(room.type || 'standard').toLowerCase();
      const roomCapacity = Number.parseInt(room.capacity || room.guests, 10) || 2;
      const roomTitle = room.title || `Номер ${roomId}`;
      const roomSummary = room.summary || `Затишний номер ${roomId}.`;
      const roomCover = roomImagePath(room.cover);
      return `
        <article class="card room-card" data-type="${escapeHtml(roomType)}" data-capacity="${roomCapacity}">
          <div class="room-card__media">
            <img src="${escapeHtml(roomCover)}" alt="${escapeHtml(roomTitle)}" loading="lazy">
          </div>
          <h3>${escapeHtml(roomTitle)}</h3>
          <p>${escapeHtml(roomSummary)}</p>
          <div class="room-meta">
            <span class="pill">${roomCapacity} ${getGuestsWord(roomCapacity)}</span>
            <span class="pill">${escapeHtml(formatType(roomType))}</span>
          </div>
          <div class="hero__actions">
            <a class="btn btn-primary" href="/rooms/room-${roomId}/">Деталі</a>
          </div>
        </article>
      `;
    }).join('');
  }

  // Відмінювання слова "гість"
  function getGuestsWord(count) {
    if (count === 1) return 'гість';
    if (count >= 2 && count <= 4) return 'гості';
    return 'гостей';
  }

  // Запускаємо завантаження при готовності DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadRoomData);
  } else {
    loadRoomData();
  }
})();



// ====== LAKE GUIDE (ozero-svityaz official info) ======
(function() {
  'use strict';

  const page = document.body?.dataset?.page || '';
  if (page !== 'lake') return;

  const titleEl = document.getElementById('lakeGuideTitle');
  const introEl = document.getElementById('lakeGuideIntro');
  const metaEl = document.getElementById('lakeGuideMeta');
  const factsEl = document.getElementById('lakeGuideFacts');
  const routesEl = document.getElementById('lakeGuideRoutes');
  const sourcesEl = document.getElementById('lakeGuideSources');

  if (!factsEl || !routesEl) return;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
  }

  function formatDate(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const dt = new Date(raw);
    if (Number.isNaN(dt.getTime())) return raw;
    return dt.toLocaleDateString('uk-UA', {
      day: '2-digit',
      month: 'long',
      year: 'numeric'
    });
  }

  function renderGuide(guide) {
    const safeGuide = guide && typeof guide === 'object' ? guide : {};

    const sources = Array.isArray(safeGuide.sources) ? safeGuide.sources : [];
    const sourceById = new Map(
      sources
        .filter((item) => item && typeof item === 'object')
        .map((item) => [String(item.id || ''), item])
    );

    const facts = Array.isArray(safeGuide.facts) ? safeGuide.facts : [];
    const routes = Array.isArray(safeGuide.routes) ? safeGuide.routes : [];

    if (titleEl && safeGuide.title) titleEl.textContent = String(safeGuide.title);
    if (introEl && safeGuide.intro) introEl.textContent = String(safeGuide.intro);

    if (metaEl) {
      const updated = formatDate(safeGuide.updatedAt);
      metaEl.innerHTML = updated
        ? `<span class="lake-guide__updated">Оновлено: ${escapeHtml(updated)}</span>`
        : '';
    }

    if (facts.length > 0) {
      factsEl.innerHTML = facts.map((fact) => {
        const source = sourceById.get(String(fact.sourceId || '')) || null;
        const sourceHtml = (source && source.url)
          ? `<a class="lake-guide__source-link" href="${escapeHtml(source.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(source.label || 'Джерело')}</a>`
          : '';
        return `
          <article class="card card-feature animate-on-scroll fade-up">
            <h3>${escapeHtml(fact.title)}</h3>
            <p class="lake-guide__fact-value">${escapeHtml(fact.value)}</p>
            <p>${escapeHtml(fact.description || '')}</p>
            ${sourceHtml}
          </article>
        `;
      }).join('');
    }

    if (routes.length > 0) {
      routesEl.innerHTML = routes.map((route) => {
        const source = sourceById.get(String(route.sourceId || '')) || null;
        const sourceHtml = (source && source.url)
          ? `<a class="lake-guide__source-link" href="${escapeHtml(source.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(source.label || 'Джерело')}</a>`
          : '';
        return `
          <article class="card card-feature animate-on-scroll fade-up">
            <h3>${escapeHtml(route.title)}</h3>
            <p>${escapeHtml(route.details || '')}</p>
            ${sourceHtml}
          </article>
        `;
      }).join('');
    }

    if (sourcesEl) {
      if (sources.length > 0) {
        sourcesEl.innerHTML = `
          <p class="lake-guide__sources-title">Джерела:</p>
          <div class="lake-guide__sources-list">
            ${sources.map((source) => `
              <a class="lake-guide__source-chip" href="${escapeHtml(source.url || '')}" target="_blank" rel="noopener noreferrer">
                ${escapeHtml(source.label || 'Джерело')}
              </a>
            `).join('')}
          </div>
        `;
      } else {
        sourcesEl.innerHTML = '';
      }
    }

    if (window.SvityazAnimations && typeof window.SvityazAnimations.init === 'function') {
      window.SvityazAnimations.init();
    }
  }

  async function loadGuide() {
    try {
      const response = await fetch('/api/lake.php?action=guide', { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      if (!data || !data.success || !data.guide) return;
      renderGuide(data.guide);
    } catch (error) {
      console.warn('Lake guide load failed:', error);
    }
  }

  loadGuide();
})();



// ====== ROOM LOADER (room listing page filters) ======
document.addEventListener('DOMContentLoaded', () => {
  // 1. Отримуємо ID кімнати з URL (наприклад, room.html?id=1)
  const params = new URLSearchParams(window.location.search);
  const roomId = params.get('id');

  if (!roomId) {
    return;
  }

  // 2. Завантажуємо дані номеру через API
  fetch(`/api/rooms.php?action=room&id=${roomId}`)
    .then(response => {
      if (!response.ok) throw new Error('Не вдалося завантажити дані номеру');
      return response.json();
    })
    .then(data => {
      // 3. Отримуємо номер
      const room = data.success ? data.room : null;

      if (room) {
        // 4. Заповнюємо сторінку даними
        setText('room-title', room.title);
        setText('room-price', room.price);
        setText('room-summary', room.summary);
        setText('room-description', room.description);

        const coverImg = document.getElementById('room-cover');
        if (coverImg) {
          coverImg.src = room.cover;
          coverImg.alt = room.title;
        }

        // Змінюємо заголовок вкладки браузера
        document.title = room.title;
      } else {
        document.querySelector('main').innerHTML = '<h1>Номер не знайдено</h1><p>Перевірте правильність посилання.</p>';
      }
    })
    .catch(error => console.error('Помилка:', error));
});

// Допоміжна функція для безпечної вставки тексту
function setText(id, text) {
  const element = document.getElementById(id);
  if (element) element.textContent = text;
}



// ====== REVIEWS (reviews component) ======
/**
 * Reviews System
 * Завантаження та відображення відгуків (localStorage + JSON)
 */
(function() {
  'use strict';

  const PREVIEW_LENGTH = 120;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
  }

  function sanitizeImageUrl(url) {
    const value = String(url || '').trim();
    if (!value) return '';
    if (value.startsWith('/assets/images/') || value.startsWith('/storage/uploads/')) return value;
    if (value.startsWith('https://svityazhome.com.ua/')) return value;
    return '';
  }

  // Форматування дати
  function formatDate(dateStr) {
    const months = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня',
                    'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
    const date = new Date(dateStr);
    if (Number.isNaN(date.getTime())) return '';
    return `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
  }

  // Генерація зірочок
  function generateStars(ratingRaw) {
    const rating = Math.max(1, Math.min(5, Number.parseInt(ratingRaw, 10) || 5));
    return '★'.repeat(rating) + '☆'.repeat(5 - rating);
  }

  // Отримання джерела
  function getSourceBadge(source) {
    if (source === 'google') {
      return '<span class="review-source-badge review-source-badge--google">Google Maps</span>';
    }
    return '<span class="review-source-badge">Сайт</span>';
  }

  // Створення HTML карточки відгуку
  function createReviewCard(review) {
    const text = String(review.text || '');
    const isLong = text.length > PREVIEW_LENGTH;
    const previewText = isLong ? text.substring(0, PREVIEW_LENGTH) + '...' : text;
    const images = Array.isArray(review.images) ? review.images.map(sanitizeImageUrl).filter(Boolean) : [];
    const hasImages = images.length > 0;

    const card = document.createElement('article');
    card.className = 'card testimonial-card';
    card.innerHTML = `
      <div class="testimonial-card__header">
        <div class="testimonial-card__stars">${generateStars(review.rating)}</div>
        ${getSourceBadge(review.source)}
      </div>
      <div class="testimonial-card__content">
        <p class="testimonial-card__text">
          <span class="text-preview">"${escapeHtml(previewText)}"</span>
          ${isLong ? `<span class="text-full" style="display: none;">"${escapeHtml(text)}"</span>` : ''}
        </p>
        ${isLong ? '<button class="review-expand-btn">Читати більше</button>' : ''}
        ${hasImages ? `
          <div class="testimonial-card__images">
            ${images.map((img, i) => `
              <a href="${escapeHtml(img)}" class="testimonial-image" target="_blank" rel="noopener noreferrer">
                <img src="${escapeHtml(img)}" alt="Фото ${i + 1}" loading="lazy">
              </a>
            `).join('')}
          </div>
        ` : ''}
      </div>
      <div class="testimonial-card__author">
        <strong>${escapeHtml(review.name || 'Гість')}</strong>
        <span>${formatDate(review.date || review.created_at)}</span>
      </div>
    `;

    // Обробник кнопки "Читати більше"
    if (isLong) {
      const expandBtn = card.querySelector('.review-expand-btn');
      const preview = card.querySelector('.text-preview');
      const full = card.querySelector('.text-full');

      expandBtn.addEventListener('click', function() {
        if (full.style.display === 'none') {
          preview.style.display = 'none';
          full.style.display = 'inline';
          expandBtn.textContent = 'Згорнути';
        } else {
          preview.style.display = 'inline';
          full.style.display = 'none';
          expandBtn.textContent = 'Читати більше';
        }
      });
    }

    return card;
  }

  const REVIEWS_API = '/api/reviews.php';
  const REVIEWS_LS_KEY = 'svh_reviews_data';
  let reviewsCsrfToken = '';

  function normalizeReview(review) {
    if (!review || typeof review !== 'object') return null;

    const rating = Math.max(1, Math.min(5, Number.parseInt(review.rating, 10) || 5));
    const text = String(review.text || '').trim();
    if (!text) return null;

    return {
      ...review,
      name: String(review.name || 'Гість').trim() || 'Гість',
      text,
      rating,
      source: review.source === 'google' ? 'google' : 'site',
      date: review.date || review.created_at || ''
    };
  }

  function normalizeReviewsList(input) {
    return (Array.isArray(input) ? input : [])
      .map(normalizeReview)
      .filter(Boolean);
  }

  function readLocalReviewsFallback() {
    try {
      const raw = localStorage.getItem(REVIEWS_LS_KEY);
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      return normalizeReviewsList(parsed?.approved);
    } catch {
      return [];
    }
  }

  async function loadReviewsCsrfToken() {
    const resp = await fetch(`${REVIEWS_API}?action=csrf`, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const data = await resp.json();
    if (!data.success || !data.csrf_token) throw new Error('CSRF init failed');
    reviewsCsrfToken = data.csrf_token;
  }

  // Завантаження відгуків з API (з fallback на localStorage)
  async function fetchReviews() {
    // Спочатку пробуємо API.
    try {
      const resp = await fetch(REVIEWS_API);
      if (resp.ok) {
        const data = await resp.json();
        if (data && data.success !== false) {
          const apiReviews = normalizeReviewsList(data.reviews);
          if (apiReviews.length > 0) return apiReviews;
        }
      }
    } catch(e) {
      console.warn('Reviews API fetch failed, using local fallback:', e);
    }

    // Fallback: локальні дані з адмінки (коли API тимчасово недоступний).
    const localReviews = readLocalReviewsFallback();
    if (localReviews.length > 0) return localReviews;

    return [];
  }

  function renderReviewSkeletons(container, count = 3) {
    if (!container) return;
    container.innerHTML = Array.from({ length: count }, () => `
      <article class="review-card review-card--skeleton" aria-hidden="true">
        <div class="review-card__header">
          <div class="review-card__skeleton-copy">
            <span class="loading-skeleton loading-skeleton--medium"></span>
            <span class="loading-skeleton loading-skeleton--short"></span>
          </div>
          <span class="loading-skeleton loading-skeleton--tiny"></span>
        </div>
        <div class="loading-skeleton loading-skeleton--line"></div>
        <div class="loading-skeleton loading-skeleton--line"></div>
        <div class="loading-skeleton loading-skeleton--medium"></div>
      </article>
    `).join('');
  }

  // Завантаження відгуків
  async function loadReviews() {
    const container = document.getElementById('reviews-container');
    if (!container) return;

    container.setAttribute('aria-busy', 'true');
    renderReviewSkeletons(container);

    let reviews = normalizeReviewsList(await fetchReviews());

    // Fallback якщо пусто
    if (reviews.length === 0) {
      reviews = normalizeReviewsList([
        { name: 'Олена К.', rating: 5, text: 'Чудове місце для відпочинку! Дуже чисто, затишно, господарі привітні. Озеро поруч, діти були в захваті.', date: '2025-08-15', source: 'google' },
        { name: 'Андрій М.', rating: 5, text: 'Відпочивали сім\'єю у серпні. Номер просторий, є все необхідне. Територія доглянута, є альтанки для BBQ.', date: '2025-08-20', source: 'google' },
        { name: 'Марина В.', rating: 5, text: 'Прекрасний відпочинок на Світязі! Садиба дуже комфортна, все продумано до дрібниць.', date: '2025-07-10', source: 'google' }
      ]);
    }

    container.innerHTML = '';
    reviews.forEach(review => {
      container.appendChild(createReviewCard(review));
    });
    container.setAttribute('aria-busy', 'false');
  }

  // Ініціалізація форми відгуку
  function initReviewForm() {
    const showFormBtn = document.getElementById('show-review-form');
    const form = document.getElementById('review-form');
    const cancelBtn = document.getElementById('cancel-review');
    const successMsg = document.getElementById('review-success');
    const starRating = document.getElementById('star-rating');
    const ratingInput = document.getElementById('review-rating');
    const statusNode = document.getElementById('home-review-status');

    if (!showFormBtn || !form) return;

    const setStatus = (message, tone = '') => {
      if (!statusNode) return;
      statusNode.textContent = message || '';
      statusNode.classList.remove('is-error', 'is-success');
      if (tone === 'error') statusNode.classList.add('is-error');
      if (tone === 'success') statusNode.classList.add('is-success');
    };

    // Показати форму
    showFormBtn.addEventListener('click', function() {
      form.style.display = 'block';
      showFormBtn.style.display = 'none';
      successMsg.style.display = 'none';
      setStatus('');
    });

    // Скасувати
    cancelBtn.addEventListener('click', function() {
      form.style.display = 'none';
      showFormBtn.style.display = 'inline-block';
      form.reset();
      updateStars(5);
      setStatus('');
    });

    // Зірочки
    if (starRating) {
      const stars = starRating.querySelectorAll('.star');

      function updateStars(rating) {
        stars.forEach((star, index) => {
          star.textContent = index < rating ? '★' : '☆';
          star.classList.toggle('active', index < rating);
        });
        ratingInput.value = rating;
      }

      stars.forEach(star => {
        star.addEventListener('click', function() {
          const rating = parseInt(this.dataset.rating);
          updateStars(rating);
        });

        star.addEventListener('mouseenter', function() {
          const rating = parseInt(this.dataset.rating);
          stars.forEach((s, index) => {
            s.textContent = index < rating ? '★' : '☆';
          });
        });

        star.addEventListener('mouseleave', function() {
          const currentRating = parseInt(ratingInput.value);
          updateStars(currentRating);
        });
      });

      // Початкова оцінка
      updateStars(5);
    }

    // Відправка форми — надсилаємо на сервер
    form.addEventListener('submit', async function(e) {
      e.preventDefault();

      const name = document.getElementById('review-name').value.trim();
      const text = document.getElementById('review-text').value.trim();
      const rating = parseInt(ratingInput.value);

      if (name.length < 2) { setStatus("Введіть ім'я", 'error'); return; }
      if (text.length < 10) { setStatus('Відгук занадто короткий', 'error'); return; }
      setStatus('Надсилаємо відгук...');

      // Надсилаємо на сервер API
      try {
        if (!reviewsCsrfToken) {
          await loadReviewsCsrfToken();
        }
        const resp = await fetch('/api/reviews.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            action: 'submit',
            name,
            text,
            rating,
            topic: 'general',
            csrf_token: reviewsCsrfToken,
            website: ''
          })
        });
        const result = await resp.json();
        if (!result.success) {
          setStatus(result.error || 'Помилка при відправці', 'error');
          return;
        }
      } catch(err) {
        setStatus('Помилка з\'єднання з сервером. Спробуйте пізніше.', 'error');
        return;
      }

      form.style.display = 'none';
      successMsg.style.display = 'block';
      form.reset();
      updateStars(5);
      setStatus('Відгук успішно відправлено', 'success');
    });
  }

  // Ініціалізація
  async function init() {
    const isHomeReviewsWidget = document.body?.dataset?.page === 'home' && !!document.getElementById('reviews-container');
    if (!isHomeReviewsWidget) return;

    try {
      await loadReviewsCsrfToken();
    } catch (err) {
      console.warn('Reviews widget CSRF init failed:', err);
    }
    loadReviews();
    initReviewForm();
  }

  // Запускаємо при готовності DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();



// ====== REVIEWS PAGE (reviews page specific) ======
/**
 * Reviews Page JavaScript
 * Handles secure loading, sorting, pagination, and forms.
 */
(function() {
  'use strict';

  const REVIEWS_API = '/api/reviews.php';
  const ROOMS_API = '/api/rooms.php';
  const PREVIEW_LENGTH = 150;
  const DEFAULT_PAGE_SIZE = 24;
  const MAX_PAGE_SIZE = 60;
  const PAGE_SIZE_OPTIONS = [6, 12, 24, 36, 48];
  const CACHE_TTL_MS = 5 * 60 * 1000;
  const CACHE_KEY = 'svh_reviews_cache_v3';
  const SORT_OPTIONS = new Set(['latest', 'oldest', 'rating_desc', 'rating_asc']);

  const TOPIC_LABELS = {
    rooms: 'Номери',
    territory: 'Територія',
    service: 'Обслуговування',
    location: 'Локація',
    general: 'Загальні враження'
  };

  const SITE_POLICY = window.SVH_SITE_POLICY || Object.freeze({
    checkIn: '14:00',
    checkOut: '11:00',
    prepayment: '30%'
  });
  window.SVH_SITE_POLICY = SITE_POLICY;
  const isReviewsPage = document.body?.dataset?.page === 'reviews' || !!document.getElementById('reviews-list');

  let currentTopic = 'all';
  let currentSort = 'latest';
  let currentPage = 1;
  let currentPerPage = DEFAULT_PAGE_SIZE;
  let csrfToken = '';
  let reviewRooms = [];
  let latestPagination = { page: 1, total_pages: 1, total: 0, per_page: DEFAULT_PAGE_SIZE };
  let reviewsRequestController = null;

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
  }

  function sanitizeImageUrl(url) {
    const value = String(url || '').trim();
    if (!value) return '';
    if (value.startsWith('/assets/images/') || value.startsWith('/storage/uploads/')) return value;
    if (value.startsWith('https://svityazhome.com.ua/')) return value;
    return '';
  }

  function setFormStatus(elementId, textValue, tone = '') {
    const node = document.getElementById(elementId);
    if (!node) return;
    node.textContent = textValue || '';
    node.classList.remove('is-error', 'is-success');
    if (tone === 'error') node.classList.add('is-error');
    if (tone === 'success') node.classList.add('is-success');
  }

  function applyPolicyText() {
    const checkinText = `Заселення з ${SITE_POLICY.checkIn}, виселення до ${SITE_POLICY.checkOut}. За домовленістю можливе раннє заселення або пізнє виселення.`;
    const prepaymentText = `Приймаємо готівку, картки (Visa/Mastercard), а також переказ на картку. При бронюванні потрібна передоплата ${SITE_POLICY.prepayment}.`;

    document.querySelectorAll('[data-policy-checkin-text]').forEach((node) => {
      node.textContent = checkinText;
    });
    document.querySelectorAll('[data-policy-prepayment-text]').forEach((node) => {
      if (node.closest('.faq-item')) {
        node.textContent = prepaymentText;
        return;
      }
      node.textContent = `Для підтвердження бронювання необхідно внести передоплату ${SITE_POLICY.prepayment}. Решту суми оплачуєте при заселенні.`;
    });
  }

  function normalizeReviewRoomId(value) {
    const id = Number.parseInt(value, 10);
    if (!Number.isFinite(id) || id < 1 || id > 200) return null;
    return id;
  }

  function fallbackReviewRoomIds() {
    return Array.from({ length: 20 }, (_, index) => index + 1);
  }

  function buildReviewRoomIds(payload) {
    const fromApi = Array.isArray(payload?.rooms)
      ? payload.rooms
        .map((room) => normalizeReviewRoomId(room?.id))
        .filter(Boolean)
      : [];
    const ids = fromApi.length ? fromApi : fallbackReviewRoomIds();
    return [...new Set(ids)].sort((a, b) => a - b);
  }

  function renderReviewRoomOptions() {
    const roomSelect = document.getElementById('review-room-id');
    if (!roomSelect) return;

    const ids = reviewRooms.length ? reviewRooms : fallbackReviewRoomIds();
    const previous = normalizeReviewRoomId(roomSelect.value);
    roomSelect.innerHTML = [
      '<option value="">Оберіть номер...</option>',
      ...ids.map((id) => `<option value="${id}">Номер ${id}</option>`)
    ].join('');

    if (previous && ids.includes(previous)) {
      roomSelect.value = String(previous);
    } else {
      roomSelect.value = '';
    }
  }

  function updateReviewRoomFieldState() {
    const topicSelect = document.getElementById('review-topic');
    const roomGroup = document.getElementById('review-room-group');
    const roomSelect = document.getElementById('review-room-id');
    if (!topicSelect || !roomGroup || !roomSelect) return;

    const requiresRoom = topicSelect.value === 'rooms';
    roomGroup.style.display = requiresRoom ? '' : 'none';
    roomSelect.disabled = !requiresRoom;
    roomSelect.required = requiresRoom;
    if (requiresRoom) {
      if (!roomSelect.value && roomSelect.options.length > 1) {
        roomSelect.value = roomSelect.options[1].value;
      }
    } else {
      roomSelect.value = '';
    }
  }

  function initReviewRoomField() {
    const topicSelect = document.getElementById('review-topic');
    if (!topicSelect) return;
    topicSelect.addEventListener('change', updateReviewRoomFieldState);
    renderReviewRoomOptions();
    updateReviewRoomFieldState();
  }

  async function loadReviewRooms() {
    try {
      const payload = await fetchJsonWithRetry(`${ROOMS_API}?action=list`, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      }, 1);
      reviewRooms = buildReviewRoomIds(payload);
    } catch (error) {
      reviewRooms = fallbackReviewRoomIds();
      console.warn('Review rooms load failed, fallback used:', error);
    }

    renderReviewRoomOptions();
    updateReviewRoomFieldState();
  }

  function cacheRead() {
    try {
      const raw = sessionStorage.getItem(CACHE_KEY);
      return raw ? JSON.parse(raw) : {};
    } catch {
      return {};
    }
  }

  function cacheWrite(data) {
    try {
      sessionStorage.setItem(CACHE_KEY, JSON.stringify(data));
    } catch {
      // ignore quota/storage errors
    }
  }

  function cacheKey(topic, sort, page, perPage) {
    return `${topic}|${sort}|${page}|${perPage}`;
  }

  function cacheGet(topic, sort, page, perPage) {
    const key = cacheKey(topic, sort, page, perPage);
    const data = cacheRead();
    const item = data[key];
    if (!item || !item.ts || !item.payload) return null;
    if ((Date.now() - item.ts) > CACHE_TTL_MS) return null;
    return item.payload;
  }

  function cacheSet(topic, sort, page, perPage, payload) {
    const key = cacheKey(topic, sort, page, perPage);
    const data = cacheRead();
    data[key] = { ts: Date.now(), payload };
    cacheWrite(data);
  }

  function normalizeTopic(topicRaw) {
    const topic = String(topicRaw || '').trim().toLowerCase();
    if (topic === 'all') return 'all';
    return TOPIC_LABELS[topic] ? topic : 'all';
  }

  function normalizeSort(sortRaw) {
    const sort = String(sortRaw || '').trim().toLowerCase();
    return SORT_OPTIONS.has(sort) ? sort : 'latest';
  }

  function normalizePage(pageRaw) {
    const value = Number.parseInt(pageRaw, 10);
    return Number.isFinite(value) && value > 0 ? value : 1;
  }

  function normalizePerPage(perPageRaw) {
    const value = Number.parseInt(perPageRaw, 10);
    if (!Number.isFinite(value)) return DEFAULT_PAGE_SIZE;
    return Math.max(1, Math.min(MAX_PAGE_SIZE, value));
  }

  function normalizePerPageOption(perPageRaw) {
    const value = normalizePerPage(perPageRaw);
    return PAGE_SIZE_OPTIONS.includes(value) ? value : DEFAULT_PAGE_SIZE;
  }

  function readReviewsStateFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const topic = normalizeTopic(params.get('topic') || params.get('reviews_topic') || '');
    const sort = normalizeSort(params.get('sort') || '');
    const page = normalizePage(params.get('page') || '1');
    const perPage = normalizePerPageOption(params.get('per_page') || '');

    return { topic, sort, page, perPage };
  }

  function syncReviewsStateToUrl() {
    if (!history.replaceState) return;

    const url = new URL(window.location.href);

    if (currentTopic === 'all') url.searchParams.delete('topic');
    else url.searchParams.set('topic', currentTopic);

    if (currentSort === 'latest') url.searchParams.delete('sort');
    else url.searchParams.set('sort', currentSort);

    if (currentPage <= 1) url.searchParams.delete('page');
    else url.searchParams.set('page', String(currentPage));

    if (currentPerPage === DEFAULT_PAGE_SIZE) url.searchParams.delete('per_page');
    else url.searchParams.set('per_page', String(currentPerPage));

    history.replaceState(null, '', url.toString());
  }

  function setActiveTopicButton(topic) {
    const filters = document.getElementById('topic-filters');
    if (!filters) return;

    filters.querySelectorAll('.filter-btn').forEach((node) => {
      const isActive = (node.dataset.topic || 'all') === topic;
      node.classList.toggle('active', isActive);
      node.classList.toggle('filter-btn--active', isActive);
      node.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  function renderLoadingState() {
    const container = document.getElementById('reviews-list');
    if (!container) return;

    const skeletonCount = normalizePerPage(currentPerPage);
    const items = Array.from({ length: skeletonCount }, () => `
      <article class="review-card review-card--skeleton visible" aria-hidden="true">
        <div class="review-card__header">
          <div class="loading-skeleton loading-skeleton--line loading-skeleton--short"></div>
          <div class="loading-skeleton loading-skeleton--line loading-skeleton--tiny"></div>
        </div>
        <div class="review-card__content">
          <div class="loading-skeleton loading-skeleton--line"></div>
          <div class="loading-skeleton loading-skeleton--line"></div>
          <div class="loading-skeleton loading-skeleton--line loading-skeleton--medium"></div>
        </div>
      </article>
    `).join('');

    container.innerHTML = items;

    const metaNode = document.getElementById('reviews-meta');
    if (metaNode) metaNode.textContent = 'Завантаження відгуків...';
  }

  function readLocalApprovedReviews(topic = 'all') {
    try {
      const raw = localStorage.getItem('svh_reviews_data');
      if (!raw) return [];
      const parsed = JSON.parse(raw);
      const approved = Array.isArray(parsed?.approved) ? parsed.approved : [];

      const normalized = approved
        .map((item) => {
          if (!item || typeof item !== 'object') return null;
          const text = String(item.text || '').trim();
          if (!text) return null;
          const rating = Math.max(1, Math.min(5, Number.parseInt(item.rating, 10) || 5));
          const topicValue = TOPIC_LABELS[item.topic] ? item.topic : 'general';
          const roomId = normalizeReviewRoomId(item.room_id);
          return {
            ...item,
            text,
            rating,
            topic: topicValue,
            room_id: roomId,
            created_at: item.created_at || item.date || null,
            date: item.date || item.created_at || null
          };
        })
        .filter(Boolean);

      if (topic === 'all') return normalized;
      return normalized.filter((item) => (item.topic || 'general') === topic);
    } catch {
      return [];
    }
  }

  function sortReviewsByMode(reviews, sort) {
    return [...reviews].sort((a, b) => {
      const dateA = Date.parse(a.created_at || a.date || '') || 0;
      const dateB = Date.parse(b.created_at || b.date || '') || 0;
      const ratingA = Number.parseInt(a.rating, 10) || 5;
      const ratingB = Number.parseInt(b.rating, 10) || 5;

      switch (sort) {
        case 'oldest':
          return dateA - dateB;
        case 'rating_asc':
          return ratingA === ratingB ? dateB - dateA : ratingA - ratingB;
        case 'rating_desc':
          return ratingA === ratingB ? dateB - dateA : ratingB - ratingA;
        case 'latest':
        default:
          return dateB - dateA;
      }
    });
  }

  function paginateLocalReviews(reviews, page, perPage) {
    const safePerPage = normalizePerPage(perPage);
    const total = reviews.length;
    const totalPages = Math.max(1, Math.ceil(total / safePerPage));
    const safePage = Math.max(1, Math.min(Number.parseInt(page, 10) || 1, totalPages));
    const offset = (safePage - 1) * safePerPage;

    return {
      items: reviews.slice(offset, offset + safePerPage),
      pagination: {
        page: safePage,
        per_page: safePerPage,
        total,
        total_pages: totalPages
      }
    };
  }

  async function fetchJsonWithRetry(url, options = {}, retries = 2) {
    let lastError;

    for (let attempt = 0; attempt <= retries; attempt += 1) {
      try {
        const response = await fetch(url, options);
        let body = null;
        try {
          body = await response.json();
        } catch {
          body = null;
        }

        if (!response.ok || !body) {
          const message = (body && body.error) || `HTTP ${response.status}`;
          throw new Error(message);
        }
        if (body.success === false) {
          throw new Error(body.error || 'Request failed');
        }
        return body;
      } catch (error) {
        if (error && error.name === 'AbortError') {
          throw error;
        }
        lastError = error;
        if (attempt < retries) {
          const delay = 250 * (2 ** attempt);
          await new Promise((resolve) => setTimeout(resolve, delay));
        }
      }
    }

    throw lastError || new Error('Network error');
  }

  async function refreshCsrfToken() {
    const payload = await fetchJsonWithRetry(`${REVIEWS_API}?action=csrf`, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    }, 1);
    csrfToken = payload.csrf_token || '';

    const reviewToken = document.getElementById('review-csrf-token');
    const questionToken = document.getElementById('question-csrf-token');
    if (reviewToken) reviewToken.value = csrfToken;
    if (questionToken) questionToken.value = csrfToken;
  }

  function formatDate(dateStr) {
    const months = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня',
      'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
    const date = new Date(dateStr);
    if (Number.isNaN(date.getTime())) return '';
    return `${date.getDate()} ${months[date.getMonth()]} ${date.getFullYear()}`;
  }

  function generateStars(ratingRaw) {
    const rating = Math.max(1, Math.min(5, Number.parseInt(ratingRaw, 10) || 5));
    return '★'.repeat(rating) + '☆'.repeat(5 - rating);
  }

  function getSourceBadge(source) {
    if (source === 'google') {
      return '<span class="source-badge source-badge--google">Google Maps</span>';
    }
    return '<span class="source-badge">Сайт</span>';
  }

  function getTopicBadge(topicRaw) {
    const topic = TOPIC_LABELS[topicRaw] ? topicRaw : 'general';
    const label = TOPIC_LABELS[topic];
    return `<span class="topic-badge topic-badge--${topic}">${escapeHtml(label)}</span>`;
  }

  function getRoomBadge(roomIdRaw) {
    const roomId = normalizeReviewRoomId(roomIdRaw);
    if (!roomId) return '';
    return `<span class="source-badge">Номер ${roomId}</span>`;
  }

  function createReviewCard(review) {
    const text = String(review.text || '');
    const isLong = text.length > PREVIEW_LENGTH;
    const previewText = isLong ? `${text.substring(0, PREVIEW_LENGTH)}...` : text;
    const images = Array.isArray(review.images) ? review.images.map(sanitizeImageUrl).filter(Boolean) : [];

    const card = document.createElement('article');
    card.className = 'review-card visible';
    card.innerHTML = `
      <div class="review-card__header">
        <div class="review-card__rating">
          <span class="review-card__stars">${generateStars(review.rating)}</span>
        </div>
        <div class="review-card__badges">
          ${getTopicBadge(review.topic || 'general')}
          ${getRoomBadge(review.room_id)}
          ${getSourceBadge(review.source)}
        </div>
      </div>
      <div class="review-card__content">
        <p class="review-card__text">
          <span class="text-preview">"${escapeHtml(previewText)}"</span>
          ${isLong ? `<span class="text-full" style="display:none">"${escapeHtml(text)}"</span>` : ''}
        </p>
        ${isLong ? '<button class="review-expand-btn" type="button">Читати більше</button>' : ''}
        ${images.length > 0 ? `
          <div class="review-card__images">
            ${images.map((img, index) => `
              <a href="${escapeHtml(img)}" class="review-image-link" data-index="${index}" target="_blank" rel="noopener noreferrer">
                <img src="${escapeHtml(img)}" alt="Фото ${index + 1}" loading="lazy">
              </a>
            `).join('')}
          </div>
        ` : ''}
      </div>
      <div class="review-card__footer">
        <div class="review-card__author">
          <strong>${escapeHtml(review.name || 'Гість')}</strong>
        </div>
        <div class="review-card__date">${escapeHtml(formatDate(review.date || review.created_at))}</div>
      </div>
    `;

    if (isLong) {
      const expandBtn = card.querySelector('.review-expand-btn');
      const preview = card.querySelector('.text-preview');
      const full = card.querySelector('.text-full');

      if (expandBtn && preview && full) {
        expandBtn.addEventListener('click', () => {
          const expanded = full.style.display !== 'none';
          preview.style.display = expanded ? 'inline' : 'none';
          full.style.display = expanded ? 'none' : 'inline';
          expandBtn.textContent = expanded ? 'Читати більше' : 'Згорнути';
        });
      }
    }

    return card;
  }

  function renderReviews(reviews) {
    const container = document.getElementById('reviews-list');
    if (!container) return;

    if (!Array.isArray(reviews) || reviews.length === 0) {
      container.innerHTML = '<div class="empty-state"><p>Немає відгуків у цій категорії</p></div>';
      return;
    }

    container.innerHTML = '';
    reviews.forEach((review) => container.appendChild(createReviewCard(review)));
  }

  function updateSummary(totalRaw, averageRaw) {
    const total = Number.parseInt(totalRaw, 10) || 0;
    const average = typeof averageRaw === 'number' ? averageRaw : null;

    const summary = document.getElementById('reviews-summary');
    const ratingBlock = summary ? summary.querySelector('.reviews-summary__rating') : null;
    const countEl = document.getElementById('total-reviews');
    const scoreEl = document.getElementById('summary-score');
    const starsEl = document.getElementById('summary-stars');

    if (countEl) countEl.textContent = String(total);

    if (!ratingBlock) return;

    if (total === 0 || average === null) {
      ratingBlock.style.display = 'none';
      return;
    }

    ratingBlock.style.display = '';
    const rounded = Math.max(1, Math.min(5, average));
    if (scoreEl) scoreEl.textContent = rounded.toFixed(1);
    if (starsEl) starsEl.textContent = generateStars(Math.round(rounded));
  }

  function renderPagination(pagination) {
    const node = document.getElementById('reviews-pagination');
    if (!node) return;

    const page = Number.parseInt(pagination?.page, 10) || 1;
    const totalPages = Number.parseInt(pagination?.total_pages, 10) || 1;
    if (totalPages <= 1) {
      node.innerHTML = '';
      return;
    }

    const sequence = [];
    const anchorPages = new Set([1, totalPages, page - 1, page, page + 1]);
    if (page <= 3) {
      anchorPages.add(2);
      anchorPages.add(3);
      anchorPages.add(4);
    }
    if (page >= totalPages - 2) {
      anchorPages.add(totalPages - 1);
      anchorPages.add(totalPages - 2);
      anchorPages.add(totalPages - 3);
    }
    const sortedPages = [...anchorPages]
      .filter((value) => Number.isFinite(value) && value >= 1 && value <= totalPages)
      .sort((a, b) => a - b);

    let prev = 0;
    sortedPages.forEach((value) => {
      if (prev && value - prev > 1) {
        sequence.push('dots');
      }
      sequence.push(value);
      prev = value;
    });

    const buttons = [];
    buttons.push(`<button type="button" class="btn btn-sm btn-ghost" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>&lt;</button>`);

    sequence.forEach((item) => {
      if (item === 'dots') {
        buttons.push('<span class="reviews-pagination__dots" aria-hidden="true">…</span>');
        return;
      }
      const active = item === page ? 'btn-primary' : 'btn-ghost';
      buttons.push(`<button type="button" class="btn btn-sm ${active}" data-page="${item}">${item}</button>`);
    });

    buttons.push(`<button type="button" class="btn btn-sm btn-ghost" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>&gt;</button>`);
    node.innerHTML = buttons.join('');
  }

  function updateReviewsMeta(pagination) {
    const metaNode = document.getElementById('reviews-meta');
    if (!metaNode) return;

    const total = Number.parseInt(pagination?.total, 10) || 0;
    if (total <= 0) {
      metaNode.textContent = 'Поки що немає опублікованих відгуків.';
      return;
    }

    const page = normalizePage(pagination?.page || currentPage);
    const perPage = normalizePerPage(pagination?.per_page || currentPerPage);
    const start = ((page - 1) * perPage) + 1;
    const end = Math.min(total, start + perPage - 1);
    metaNode.textContent = `Показано ${start}-${end} з ${total} відгуків`;
  }

  function clearError() {
    const errorNode = document.getElementById('reviews-error');
    if (!errorNode) return;
    errorNode.textContent = '';
    errorNode.style.display = 'none';
  }

  function showError(message) {
    const errorNode = document.getElementById('reviews-error');
    if (!errorNode) return;

    errorNode.innerHTML = `
      <p>${escapeHtml(message)}</p>
      <button type="button" class="btn btn-outline btn-sm" id="reviews-retry-btn">Спробувати ще раз</button>
    `;
    errorNode.style.display = '';

    const retryBtn = document.getElementById('reviews-retry-btn');
    if (retryBtn) {
      retryBtn.addEventListener('click', () => {
        loadReviews();
      }, { once: true });
    }
  }

  async function fetchReviewsPage(topic, sort, page, perPage, signal = null) {
    const safePerPage = normalizePerPage(perPage);
    const cached = cacheGet(topic, sort, page, safePerPage);
    if (cached) return cached;

    const params = new URLSearchParams({
      topic,
      sort,
      page: String(page),
      per_page: String(safePerPage)
    });

    const data = await fetchJsonWithRetry(`${REVIEWS_API}?${params.toString()}`, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      signal
    }, 2);

    cacheSet(topic, sort, page, safePerPage, data);
    return data;
  }

  function extractReviewsPayloadItems(payload) {
    const candidates = [
      payload?.reviews,
      payload?.items,
      payload?.approved,
      payload?.data?.reviews,
      payload?.data?.items,
      payload?.data?.approved
    ];

    for (const candidate of candidates) {
      if (Array.isArray(candidate)) return candidate;
    }
    return [];
  }

  function normalizeRemoteReviews(itemsRaw) {
    return itemsRaw
      .map((item) => {
        if (!item || typeof item !== 'object') return null;
        const text = String(item.text || '').trim();
        if (!text) return null;

        const rating = Math.max(1, Math.min(5, Number.parseInt(item.rating, 10) || 5));
        const topicValue = TOPIC_LABELS[item.topic] ? item.topic : 'general';
        const roomId = normalizeReviewRoomId(item.room_id);
        return {
          ...item,
          text,
          rating,
          topic: topicValue,
          room_id: roomId,
          created_at: item.created_at || item.date || null,
          date: item.date || item.created_at || null
        };
      })
      .filter(Boolean);
  }

  function extractTotalReviews(payload, fallback = 0) {
    const totalCandidates = [
      payload?.total,
      payload?.pagination?.total,
      payload?.stats?.approved_reviews
    ];

    for (const candidate of totalCandidates) {
      const value = Number.parseInt(candidate, 10);
      if (Number.isFinite(value) && value >= 0) return value;
    }

    return Math.max(0, Number.parseInt(fallback, 10) || 0);
  }

  function extractAverageRating(payload, reviews) {
    const avgCandidates = [
      payload?.average_rating,
      payload?.average,
      payload?.stats?.avg_rating
    ];

    for (const candidate of avgCandidates) {
      const value = Number(candidate);
      if (Number.isFinite(value) && value >= 1 && value <= 5) {
        return Math.round(value * 10) / 10;
      }
    }

    if (!Array.isArray(reviews) || reviews.length === 0) return null;
    const sum = reviews.reduce((acc, item) => acc + (Number.parseInt(item.rating, 10) || 5), 0);
    return Math.round((sum / reviews.length) * 10) / 10;
  }

  async function loadReviews() {
    if (reviewsRequestController) {
      reviewsRequestController.abort();
    }
    reviewsRequestController = new AbortController();

    renderLoadingState();
    clearError();

    try {
      const data = await fetchReviewsPage(
        currentTopic,
        currentSort,
        currentPage,
        currentPerPage,
        reviewsRequestController.signal
      );

      const remoteReviews = normalizeRemoteReviews(extractReviewsPayloadItems(data));
      const summaryTotal = extractTotalReviews(data, remoteReviews.length);
      const summaryAverage = extractAverageRating(data, remoteReviews);

      latestPagination = data.pagination || {
        page: currentPage,
        total_pages: Math.max(1, Math.ceil(summaryTotal / normalizePerPage(currentPerPage))),
        total: summaryTotal,
        per_page: currentPerPage
      };
      currentPage = normalizePage(latestPagination.page || currentPage);
      currentPerPage = normalizePerPageOption(latestPagination.per_page || currentPerPage);

      renderReviews(remoteReviews);
      updateSummary(summaryTotal, summaryAverage);
      renderPagination(latestPagination);
      updateReviewsMeta(latestPagination);
      syncPerPageSelect();
      syncReviewsStateToUrl();
    } catch (error) {
      if (error && error.name === 'AbortError') {
        return;
      }

      const localReviews = sortReviewsByMode(readLocalApprovedReviews(currentTopic), currentSort);
      if (localReviews.length > 0) {
        const localPage = paginateLocalReviews(localReviews, currentPage, currentPerPage);
        const average = localReviews.reduce((sum, item) => sum + (Number.parseInt(item.rating, 10) || 5), 0) / localReviews.length;

        latestPagination = localPage.pagination;
        currentPage = normalizePage(localPage.pagination?.page || currentPage);
        currentPerPage = normalizePerPageOption(localPage.pagination?.per_page || currentPerPage);
        renderReviews(localPage.items);
        updateSummary(localReviews.length, average);
        renderPagination(latestPagination);
        updateReviewsMeta(latestPagination);
        syncPerPageSelect();
        syncReviewsStateToUrl();
        showError("Сервер відгуків тимчасово недоступний. Показано локальні дані цього браузера.");
        return;
      }

      renderReviews([]);
      updateSummary(0, null);
      renderPagination({ page: 1, total_pages: 1, total: 0, per_page: currentPerPage });
      updateReviewsMeta({ page: 1, total_pages: 1, total: 0, per_page: currentPerPage });
      syncPerPageSelect();
      syncReviewsStateToUrl();
      showError("Не вдалося завантажити відгуки. Перевірте з'єднання або спробуйте пізніше.");
      console.error('Reviews load failed:', error);
    }
  }

  function initFilters() {
    const filters = document.getElementById('topic-filters');
    if (!filters) return;

    setActiveTopicButton(currentTopic);

    filters.addEventListener('click', (event) => {
      const button = event.target.closest('.filter-btn');
      if (!button) return;

      const nextTopic = normalizeTopic(button.dataset.topic || 'all');
      if (nextTopic === currentTopic) return;
      currentTopic = nextTopic;
      currentPage = 1;
      setActiveTopicButton(currentTopic);
      loadReviews();
    });
  }

  function initSort() {
    const sortSelect = document.getElementById('reviews-sort');
    if (!sortSelect) return;

    sortSelect.value = normalizeSort(currentSort);
    sortSelect.addEventListener('change', () => {
      currentSort = normalizeSort(sortSelect.value || 'latest');
      sortSelect.value = currentSort;
      currentPage = 1;
      loadReviews();
    });
  }

  function syncPerPageSelect() {
    const perPageSelect = document.getElementById('reviews-per-page');
    if (!perPageSelect) return;
    perPageSelect.value = String(normalizePerPageOption(currentPerPage));
  }

  function initPerPage() {
    const perPageSelect = document.getElementById('reviews-per-page');
    if (!perPageSelect) return;

    syncPerPageSelect();
    perPageSelect.addEventListener('change', () => {
      currentPerPage = normalizePerPageOption(perPageSelect.value || String(DEFAULT_PAGE_SIZE));
      perPageSelect.value = String(currentPerPage);
      currentPage = 1;
      loadReviews();
    });
  }

  function initPagination() {
    const node = document.getElementById('reviews-pagination');
    if (!node) return;

    node.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-page]');
      if (!button || button.disabled) return;

      const nextPage = Number.parseInt(button.dataset.page, 10);
      if (!Number.isFinite(nextPage) || nextPage < 1 || nextPage === currentPage) return;

      currentPage = normalizePage(nextPage);
      loadReviews();
      window.scrollTo({ top: node.getBoundingClientRect().top + window.scrollY - 120, behavior: 'smooth' });
    });
  }

  function initStarRating() {
    const starRating = document.getElementById('star-rating');
    const ratingInput = document.getElementById('review-rating');
    if (!starRating || !ratingInput) return;

    const stars = Array.from(starRating.querySelectorAll('.star'));
    if (!stars.length) return;

    const clampRating = (ratingRaw) => Math.max(1, Math.min(5, Number.parseInt(ratingRaw, 10) || 5));
    const focusStar = (ratingRaw) => {
      const rating = clampRating(ratingRaw);
      const target = stars[rating - 1];
      if (target && typeof target.focus === 'function') target.focus();
    };

    function updateStars(ratingRaw) {
      const rating = clampRating(ratingRaw);
      stars.forEach((star, index) => {
        const isFilled = index < rating;
        const isChecked = index + 1 === rating;
        star.setAttribute('role', 'radio');
        star.setAttribute('aria-checked', isChecked ? 'true' : 'false');
        star.setAttribute('tabindex', isChecked ? '0' : '-1');
        star.textContent = index < rating ? '★' : '☆';
        star.classList.toggle('active', isFilled);
      });
      ratingInput.value = String(rating);
    }

    stars.forEach((star) => {
      star.addEventListener('click', function() {
        updateStars(this.dataset.rating);
      });

      star.addEventListener('keydown', (event) => {
        const currentRating = clampRating(ratingInput.value || star.dataset.rating);
        if (event.key === ' ' || event.key === 'Enter') {
          event.preventDefault();
          updateStars(star.dataset.rating);
          return;
        }
        if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
          event.preventDefault();
          const next = Math.min(5, currentRating + 1);
          updateStars(next);
          focusStar(next);
          return;
        }
        if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
          event.preventDefault();
          const prev = Math.max(1, currentRating - 1);
          updateStars(prev);
          focusStar(prev);
          return;
        }
        if (event.key === 'Home') {
          event.preventDefault();
          updateStars(1);
          focusStar(1);
          return;
        }
        if (event.key === 'End') {
          event.preventDefault();
          updateStars(5);
          focusStar(5);
        }
      });

      star.addEventListener('mouseenter', function() {
        const rating = clampRating(this.dataset.rating);
        stars.forEach((node, index) => {
          node.textContent = index < rating ? '★' : '☆';
        });
      });

      star.addEventListener('mouseleave', () => updateStars(ratingInput.value));
    });

    updateStars(5);
  }

  function initImageUpload() {
    const imageInput = document.getElementById('review-images');
    const previewContainer = document.getElementById('image-preview');
    if (!imageInput || !previewContainer) return;

    imageInput.addEventListener('change', function() {
      previewContainer.innerHTML = '';
      const files = Array.from(this.files || []).slice(0, 3);

      files.forEach((file, index) => {
        if (!file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = (event) => {
          const div = document.createElement('div');
          div.className = 'image-preview-item';
          div.innerHTML = `
            <img src="${escapeHtml(event.target?.result || '')}" alt="Preview ${index + 1}">
            <button type="button" class="remove-image" data-index="${index}">×</button>
          `;
          previewContainer.appendChild(div);
        };
        reader.readAsDataURL(file);
      });

      previewContainer.style.display = files.length > 0 ? 'flex' : 'none';
    });

    previewContainer.addEventListener('click', (event) => {
      if (!event.target.classList.contains('remove-image')) return;
      const index = Number.parseInt(event.target.dataset.index, 10);
      const dt = new DataTransfer();
      const files = Array.from(imageInput.files || []);
      files.forEach((file, i) => {
        if (i !== index) dt.items.add(file);
      });
      imageInput.files = dt.files;
      imageInput.dispatchEvent(new Event('change'));
    });
  }

  async function submitReview(form) {
    const formData = new FormData(form);
    formData.set('action', 'submit');
    if (csrfToken) formData.set('csrf_token', csrfToken);

    const topic = String(formData.get('topic') || '').trim();
    const roomId = normalizeReviewRoomId(formData.get('room_id'));
    if (topic === 'rooms') {
      if (!roomId) {
        throw new Error('Оберіть номер для відгуку про номер');
      }
      formData.set('room_id', String(roomId));
    } else {
      formData.delete('room_id');
    }

    const response = await fetchJsonWithRetry(REVIEWS_API, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }, 0);

    return response;
  }

  async function submitQuestion(form) {
    const formData = new FormData(form);
    formData.set('action', 'question');
    if (csrfToken) formData.set('csrf_token', csrfToken);

    const response = await fetchJsonWithRetry(REVIEWS_API, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }, 0);

    return response;
  }

  function initReviewForm() {
    const form = document.getElementById('review-form');
    if (!form) return;

    initImageUpload();
    initReviewRoomField();

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setFormStatus('review-form-status', '');

      const name = (document.getElementById('review-name')?.value || '').trim();
      const topic = (document.getElementById('review-topic')?.value || '').trim();
      const text = (document.getElementById('review-text')?.value || '').trim();
      const roomId = normalizeReviewRoomId(document.getElementById('review-room-id')?.value || '');
      const files = Array.from(document.getElementById('review-images')?.files || []);

      if (name.length < 2) {
        setFormStatus('review-form-status', "Введіть ім'я (мінімум 2 символи)", 'error');
        return;
      }
      if (!topic) {
        setFormStatus('review-form-status', 'Оберіть тему відгуку', 'error');
        return;
      }
      if (topic === 'rooms' && !roomId) {
        setFormStatus('review-form-status', 'Оберіть номер, про який пишете відгук', 'error');
        return;
      }
      if (text.length < 10) {
        setFormStatus('review-form-status', 'Відгук занадто короткий (мінімум 10 символів)', 'error');
        return;
      }
      if (files.length > 3) {
        setFormStatus('review-form-status', 'Можна додати не більше 3 фото', 'error');
        return;
      }
      if (!csrfToken) {
        setFormStatus('review-form-status', 'Сесія форми не ініціалізована. Оновіть сторінку.', 'error');
        return;
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      setFormStatus('review-form-status', 'Надсилаємо відгук...');

      try {
        const result = await submitReview(form);
        form.style.display = 'none';
        const success = document.getElementById('review-success');
        if (success) success.style.display = 'block';
        setFormStatus('review-form-status', result.message || 'Відгук відправлено на модерацію.', 'success');
      } catch (error) {
        setFormStatus('review-form-status', error.message || "Не вдалося надіслати відгук. Спробуйте ще раз.", 'error');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  function initQuestionForm() {
    const form = document.getElementById('question-form');
    if (!form) return;

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setFormStatus('question-form-status', '');

      const name = (document.getElementById('question-name')?.value || '').trim();
      const contact = (document.getElementById('question-contact')?.value || '').trim();
      const text = (document.getElementById('question-text')?.value || '').trim();

      if (name.length < 2) {
        setFormStatus('question-form-status', "Введіть ім'я", 'error');
        return;
      }
      if (contact.length < 3) {
        setFormStatus('question-form-status', 'Вкажіть email або телефон', 'error');
        return;
      }
      if (text.length < 10) {
        setFormStatus('question-form-status', 'Запитання занадто коротке', 'error');
        return;
      }
      if (!csrfToken) {
        setFormStatus('question-form-status', 'Сесія форми не ініціалізована. Оновіть сторінку.', 'error');
        return;
      }

      const submitBtn = form.querySelector('button[type="submit"]');
      if (submitBtn) submitBtn.disabled = true;
      setFormStatus('question-form-status', 'Надсилаємо запитання...');

      try {
        const result = await submitQuestion(form);
        form.style.display = 'none';
        const success = document.getElementById('question-success');
        if (success) success.style.display = 'block';
        setFormStatus('question-form-status', result.message || "Запитання надіслано. Ми зв'яжемося з вами найближчим часом.", 'success');
      } catch (error) {
        setFormStatus('question-form-status', error.message || "Не вдалося надіслати запитання. Спробуйте ще раз.", 'error');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  window.resetReviewForm = function() {
    const form = document.getElementById('review-form');
    const success = document.getElementById('review-success');
    if (!form || !success) return;

    form.reset();
    form.style.display = 'block';
    success.style.display = 'none';
    setFormStatus('review-form-status', '');
    updateReviewRoomFieldState();

    const preview = document.getElementById('image-preview');
    if (preview) {
      preview.innerHTML = '';
      preview.style.display = 'none';
    }

    const starRating = document.getElementById('star-rating');
    const ratingInput = document.getElementById('review-rating');
    if (starRating && ratingInput) {
      starRating.querySelectorAll('.star').forEach((star, index) => {
        star.textContent = index < 5 ? '★' : '☆';
        star.classList.toggle('active', index < 5);
      });
      ratingInput.value = '5';
    }
  };

  window.resetQuestionForm = function() {
    const form = document.getElementById('question-form');
    const success = document.getElementById('question-success');
    if (!form || !success) return;

    form.reset();
    form.style.display = 'block';
    success.style.display = 'none';
    setFormStatus('question-form-status', '');
  };

  async function init() {
    if (!isReviewsPage) return;

    const initialState = readReviewsStateFromUrl();
    currentTopic = initialState.topic;
    currentSort = initialState.sort;
    currentPage = initialState.page;
    currentPerPage = initialState.perPage;

    applyPolicyText();
    initFilters();
    initSort();
    initPerPage();
    initPagination();
    initStarRating();
    initReviewForm();
    initQuestionForm();

    try {
      await Promise.all([refreshCsrfToken(), loadReviewRooms()]);
    } catch (error) {
      console.error('CSRF init failed:', error);
      setFormStatus('review-form-status', 'Не вдалося ініціалізувати захист форми. Оновіть сторінку.', 'error');
      setFormStatus('question-form-status', 'Не вдалося ініціалізувати захист форми. Оновіть сторінку.', 'error');
    }

    loadReviews();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();


// ====== GALLERY ======
// Gallery filters remain inline in gallery/index.html; lightbox is global above.


// ====== ANALYTICS (local analytics) ======
// SvityazHOME Analytics - Система аналітики
// Збирає статистику відвідувань та зберігає в localStorage
// Для повноцінної аналітики рекомендується Google Analytics

(function() {
  'use strict';

  const ANALYTICS_KEY = 'svh_analytics';
  const VISITORS_KEY = 'svh_visitors';
  const SESSION_KEY = 'svh_session';
  const SESSION_DURATION = 30 * 60 * 1000; // 30 хвилин

  // Отримати поточну статистику
  function getAnalytics() {
    try {
      const data = localStorage.getItem(ANALYTICS_KEY);
      return data ? JSON.parse(data) : getDefaultAnalytics();
    } catch {
      return getDefaultAnalytics();
    }
  }

  // Дефолтна структура аналітики
  function getDefaultAnalytics() {
    return {
      totalVisits: 0,
      uniqueVisitors: 0,
      pageViews: {},
      dailyStats: {},
      referrers: {},
      devices: { mobile: 0, desktop: 0, tablet: 0 },
      lastUpdated: Date.now()
    };
  }

  // Зберегти аналітику
  function saveAnalytics(data) {
    try {
      data.lastUpdated = Date.now();
      localStorage.setItem(ANALYTICS_KEY, JSON.stringify(data));
    } catch (e) {
      console.warn('Analytics save failed:', e);
    }
  }

  // Перевірка чи це нова сесія
  function isNewSession() {
    const session = localStorage.getItem(SESSION_KEY);
    if (!session) return true;

    try {
      const data = JSON.parse(session);
      return Date.now() - data.start > SESSION_DURATION;
    } catch {
      return true;
    }
  }

  // Перевірка чи це унікальний відвідувач (на основі дня)
  function isNewVisitorToday() {
    const today = new Date().toISOString().split('T')[0];
    const visitors = JSON.parse(localStorage.getItem(VISITORS_KEY) || '{}');

    if (visitors.lastVisit !== today) {
      visitors.lastVisit = today;
      localStorage.setItem(VISITORS_KEY, JSON.stringify(visitors));
      return true;
    }
    return false;
  }

  // Визначення типу пристрою
  function getDeviceType() {
    const ua = navigator.userAgent.toLowerCase();
    if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
    if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';
    return 'desktop';
  }

  // Отримати назву сторінки
  function getPageName() {
    const path = window.location.pathname;
    if (path === '/' || path === '/index.html') return 'Головна';
    if (path.includes('/about')) return 'Про нас';
    if (path.includes('/gallery')) return 'Галерея';
    if (path.includes('/booking')) return 'Бронювання';
    if (path.includes('/ozero-svityaz')) return 'Озеро Світязь';
    if (path.includes('/rooms/room-')) {
      const match = path.match(/room-(\d+)/);
      return match ? `Номер ${match[1]}` : 'Номер';
    }
    if (path.includes('/rooms')) return 'Усі номери';
    return path;
  }

  // Записати відвідування
  function trackPageView() {
    const analytics = getAnalytics();
    const today = new Date().toISOString().split('T')[0];
    const pageName = getPageName();
    const device = getDeviceType();

    // Збільшити перегляди сторінки
    analytics.pageViews[pageName] = (analytics.pageViews[pageName] || 0) + 1;

    // Статистика за день
    if (!analytics.dailyStats[today]) {
      analytics.dailyStats[today] = { visits: 0, pageViews: 0, uniqueVisitors: 0 };
    }
    analytics.dailyStats[today].pageViews++;

    // Нова сесія
    if (isNewSession()) {
      analytics.totalVisits++;
      analytics.dailyStats[today].visits++;
      analytics.devices[device]++;

      // Оновити сесію
      localStorage.setItem(SESSION_KEY, JSON.stringify({
        start: Date.now(),
        pages: [pageName]
      }));

      // Зберегти referrer
      if (document.referrer && !document.referrer.includes(window.location.hostname)) {
        const referrer = new URL(document.referrer).hostname;
        analytics.referrers[referrer] = (analytics.referrers[referrer] || 0) + 1;
      }
    } else {
      // Оновити поточну сесію
      try {
        const session = JSON.parse(localStorage.getItem(SESSION_KEY));
        if (!session.pages.includes(pageName)) {
          session.pages.push(pageName);
          localStorage.setItem(SESSION_KEY, JSON.stringify(session));
        }
      } catch {}
    }

    // Унікальний відвідувач сьогодні
    if (isNewVisitorToday()) {
      analytics.uniqueVisitors++;
      analytics.dailyStats[today].uniqueVisitors++;
    }

    saveAnalytics(analytics);
  }

  // Симуляція онлайн відвідувачів (на основі активних сесій)
  function getActiveVisitors() {
    // Для статичного сайту це приблизна оцінка
    // В реальності потрібен бекенд або Firebase Realtime
    const session = localStorage.getItem(SESSION_KEY);
    if (!session) return 0;

    try {
      const data = JSON.parse(session);
      const isActive = Date.now() - data.start < 5 * 60 * 1000; // 5 хв
      return isActive ? 1 : 0;
    } catch {
      return 0;
    }
  }

  // Експорт для адмінки
  window.SVH_Analytics = {
    getData: getAnalytics,
    getActiveVisitors: getActiveVisitors,

    // Топ сторінок
    getTopPages: function(limit = 10) {
      const analytics = getAnalytics();
      return Object.entries(analytics.pageViews)
        .sort((a, b) => b[1] - a[1])
        .slice(0, limit)
        .map(([page, views]) => ({ page, views }));
    },

    // Статистика за останні N днів
    getRecentStats: function(days = 7) {
      const analytics = getAnalytics();
      const result = [];
      const today = new Date();

      for (let i = days - 1; i >= 0; i--) {
        const date = new Date(today);
        date.setDate(date.getDate() - i);
        const key = date.toISOString().split('T')[0];
        const dayName = date.toLocaleDateString('uk-UA', { weekday: 'short', day: 'numeric' });

        result.push({
          date: key,
          label: dayName,
          visits: analytics.dailyStats[key]?.visits || 0,
          pageViews: analytics.dailyStats[key]?.pageViews || 0,
          uniqueVisitors: analytics.dailyStats[key]?.uniqueVisitors || 0
        });
      }

      return result;
    },

    // Загальна статистика
    getSummary: function() {
      const analytics = getAnalytics();
      const today = new Date().toISOString().split('T')[0];
      const todayStats = analytics.dailyStats[today] || { visits: 0, pageViews: 0, uniqueVisitors: 0 };

      return {
        totalVisits: analytics.totalVisits,
        totalPageViews: Object.values(analytics.pageViews).reduce((a, b) => a + b, 0),
        uniqueVisitors: analytics.uniqueVisitors,
        todayVisits: todayStats.visits,
        todayPageViews: todayStats.pageViews,
        todayUnique: todayStats.uniqueVisitors,
        activeNow: getActiveVisitors(),
        devices: analytics.devices,
        topReferrers: Object.entries(analytics.referrers)
          .sort((a, b) => b[1] - a[1])
          .slice(0, 5)
      };
    },

    // Скинути статистику
    reset: function() {
      localStorage.removeItem(ANALYTICS_KEY);
      localStorage.removeItem(VISITORS_KEY);
      localStorage.removeItem(SESSION_KEY);
      return true;
    }
  };

  // Відстежити перегляд при завантаженні
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackPageView);
  } else {
    trackPageView();
  }

  // Відстежити час на сторінці
  let startTime = Date.now();
  window.addEventListener('beforeunload', function() {
    const timeSpent = Math.round((Date.now() - startTime) / 1000);
    // Можна зберігати середній час на сторінці
  });

})();



// ====== EARLY ACCESS (site UI password gate) ======
/* ========================================
   EARLY ACCESS - PASSWORD PROTECTION
   ======================================== */

(function() {
  'use strict';

  const ACCESS_API = '/api/access.php';
  const STORAGE_KEY = 'early_access_unlocked';
  const FALLBACK_STORAGE_EXPIRY = 24 * 60 * 60 * 1000; // 24 hours

  function parseJSONSafe(raw) {
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  function getLocalUnlockState() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return { unlocked: false };
    const parsed = parseJSONSafe(raw);
    if (!parsed || typeof parsed !== 'object') {
      localStorage.removeItem(STORAGE_KEY);
      return { unlocked: false };
    }
    const expiresAt = Number(parsed.expiresAt || 0);
    if (!Number.isFinite(expiresAt) || expiresAt <= Date.now()) {
      localStorage.removeItem(STORAGE_KEY);
      return { unlocked: false };
    }
    return { unlocked: true, expiresAt };
  }

  function setLocalUnlockState(expiresInSeconds) {
    const ttlMs = Math.max(60, Number(expiresInSeconds || 0)) * 1000;
    const expiresAt = Date.now() + (Number.isFinite(ttlMs) ? ttlMs : FALLBACK_STORAGE_EXPIRY);
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ expiresAt }));
  }

  function clearLocalUnlockState() {
    localStorage.removeItem(STORAGE_KEY);
  }

  async function requestAccessApi(action, payload = {}) {
    const response = await fetch(ACCESS_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action, ...payload })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.success) {
      throw new Error(data.error || 'Помилка перевірки доступу');
    }
    return data;
  }

  function createMaintenanceModal() {
    return `
      <div class="maintenance-overlay" id="maintenanceOverlay">
        <div class="maintenance-card">
          <div class="maintenance-lock-icon">\uD83D\uDD12</div>

          <div class="maintenance-status-badge">
            \u26A0\uFE0F РАННІЙ ДОСТУП
          </div>

          <h1 class="maintenance-title">Вхід за паролем</h1>

          <p class="maintenance-subtitle">
            Для цієї версії сайту відкрито попередній доступ
          </p>

          <p class="maintenance-description">
            Введіть пароль раннього доступу.
            Також можна увійти паролем адмінки (якщо це дозволено в конфігурації).
          </p>
          <p style="margin: 0 0 16px; color: var(--muted); font-size: 0.9rem;">
            Підказка: <strong>svityaz</strong>
          </p>

          <form class="maintenance-form" id="maintenanceForm">
            <div class="maintenance-input-group">
              <input
                type="password"
                id="passwordInput"
                placeholder="Введіть пароль"
                autocomplete="current-password"
              >
            </div>
            <button type="submit" class="maintenance-button" id="submitBtn">
              Увійти на сайт
            </button>
            <div class="maintenance-error" id="errorMessage"></div>
            <div class="maintenance-success" id="successMessage"></div>
          </form>
        </div>
      </div>
    `;
  }

  function unlockOverlay(overlay) {
    overlay.style.animation = 'fadeOut 0.6s ease-out forwards';
    document.body.classList.remove('maintenance-mode');
    document.body.classList.add('maintenance-unlocked');
    setTimeout(() => {
      overlay.remove();
      if (window.wowEffectsRestart) {
        window.wowEffectsRestart();
      }
    }, 600);
  }

  function showOverlay() {
    const container = document.createElement('div');
    container.innerHTML = createMaintenanceModal();
    document.body.classList.add('maintenance-mode');
    document.body.prepend(container.firstElementChild);
    setupMaintenanceListeners();
  }

  function setupMaintenanceListeners() {
    const form = document.getElementById('maintenanceForm');
    const passwordInput = document.getElementById('passwordInput');
    const submitBtn = document.getElementById('submitBtn');
    const errorMessage = document.getElementById('errorMessage');
    const successMessage = document.getElementById('successMessage');
    const overlay = document.getElementById('maintenanceOverlay');

    if (!form || !passwordInput || !submitBtn || !errorMessage || !successMessage || !overlay) {
      return;
    }

    setTimeout(() => passwordInput.focus(), 300);

    const clearMessages = () => {
      errorMessage.classList.remove('show');
      successMessage.classList.remove('show');
      errorMessage.textContent = '';
      successMessage.textContent = '';
    };

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const password = passwordInput.value;
      clearMessages();

      if (!password.trim()) {
        errorMessage.textContent = 'Введіть пароль.';
        errorMessage.classList.add('show');
        passwordInput.focus();
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Перевірка...';

      try {
        const data = await requestAccessApi('unlock', { password });
        setLocalUnlockState(data.expires_in);

        successMessage.textContent = 'Пароль правильний! Доступ відкрито.';
        successMessage.classList.add('show');

        setTimeout(() => unlockOverlay(overlay), 700);
      } catch (error) {
        clearLocalUnlockState();
        errorMessage.textContent = error.message || 'Пароль невірний. Спробуйте ще раз!';
        errorMessage.classList.add('show');
        passwordInput.value = '';
        passwordInput.focus();
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Увійти на сайт';
      }
    });

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) {
        const card = document.querySelector('.maintenance-card');
        if (!card) return;
        card.style.animation = 'shakeError 0.5s ease-out';
        setTimeout(() => {
          card.style.animation = '';
        }, 500);
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        e.preventDefault();
      }
    });
  }

  async function initEarlyAccessGate() {
    try {
      const status = await requestAccessApi('status');
      if (!status.enabled) {
        clearLocalUnlockState();
        return;
      }

      const localState = getLocalUnlockState();
      if (status.unlocked) {
        if (!localState.unlocked) {
          setLocalUnlockState(status.expires_in);
        }
        document.body.classList.add('maintenance-unlocked');
        return;
      }

      clearLocalUnlockState();
      showOverlay();
    } catch (error) {
      console.warn('Early access check failed:', error);
      showOverlay();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      void initEarlyAccessGate();
    });
  } else {
    void initEarlyAccessGate();
  }

  window.maintenanceUnlock = async (password) => {
    if (!password) return false;
    try {
      const data = await requestAccessApi('unlock', { password });
      setLocalUnlockState(data.expires_in);
      location.reload();
      return true;
    } catch {
      return false;
    }
  };
})();



// ====== AI CHAT (chat widget) ======
/**
 * SvityazHOME AI Assistant
 * Helps users choose the perfect room using OpenAI API
 */

(function() {
  'use strict';

  // Configuration
  const CONFIG = {
    // Використовуємо PHP proxy для безпеки (API ключ на сервері)
    useProxy: true,
    proxyEndpoint: '/api/chat.php',
    // Прямий доступ до OpenAI (тільки для тестування локально!)
    apiKey: '',
    apiEndpoint: 'https://api.openai.com/v1/chat/completions',
    model: 'gpt-4o-mini',
    maxTokens: 1500,
    temperature: 0.85,
    historyDepth: 16
  };
  const IS_LOCALHOST = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname);
  let proxyAvailable = true;
  let offlineNoticeShown = false;

  function canUseProxy() {
    return CONFIG.useProxy && proxyAvailable;
  }

  function canUseDirectApi() {
    return !canUseProxy() && Boolean(CONFIG.apiKey);
  }

  function extractApiErrorMessage(errorPayload, fallback = 'API request failed') {
    if (typeof errorPayload === 'string' && errorPayload.trim()) {
      return errorPayload.trim();
    }

    if (!errorPayload || typeof errorPayload !== 'object') {
      return fallback;
    }

    if (typeof errorPayload.error === 'string' && errorPayload.error.trim()) {
      return errorPayload.error.trim();
    }

    if (
      errorPayload.error &&
      typeof errorPayload.error === 'object' &&
      typeof errorPayload.error.message === 'string' &&
      errorPayload.error.message.trim()
    ) {
      return errorPayload.error.message.trim();
    }

    if (typeof errorPayload.message === 'string' && errorPayload.message.trim()) {
      return errorPayload.message.trim();
    }

    return fallback;
  }

  function shouldFallbackToOffline(errorMessageRaw = '', statusCode = 0) {
    const message = String(errorMessageRaw || '').toLowerCase();
    if (statusCode >= 500 || statusCode === 429) {
      return true;
    }
    return (
      message.includes('api key is not configured') ||
      message.includes('failed to fetch') ||
      message.includes('connection error') ||
      message.includes('api request failed') ||
      message.includes('incorrect api key') ||
      message.includes('invalid api key') ||
      message.includes('insufficient_quota') ||
      message.includes('rate limit') ||
      message.includes('unauthorized') ||
      message.includes('forbidden') ||
      message.includes('http 5') ||
      message.includes('curl extension is missing') ||
      message.includes('network')
    );
  }

  function shouldDisableProxy(errorMessageRaw = '', statusCode = 0) {
    const message = String(errorMessageRaw || '').toLowerCase();
    return (
      statusCode === 401 ||
      statusCode === 403 ||
      message.includes('api key is not configured') ||
      message.includes('incorrect api key') ||
      message.includes('invalid api key') ||
      message.includes('curl extension is missing')
    );
  }

  function getWelcomeMessage() {
    if (IS_LOCALHOST && !canUseProxy() && !CONFIG.apiKey) {
      return 'Привіт! 👋 Я помічник SvityazHOME. Працюю в локальному режимі і допоможу підібрати номер без звернення до OpenAI. Скільки гостей планує приїхати?';
    }
    return 'Привіт! 👋 Я AI-помічник SvityazHOME. Допоможу обрати ідеальний номер для вашого відпочинку біля Світязя. Скільки гостей планує приїхати?';
  }

  // Room data loaded from JSON files
  let ROOMS_DATA = [];
  let SYSTEM_PROMPT = '';
  let roomsLoadPromise = null;
  let bootstrapPromise = null;
  let isBootstrapped = false;
  const ROOM_CAPACITY_FALLBACK = {
    1: 3, 2: 3, 3: 4, 4: 2, 5: 4,
    6: 4, 7: 4, 8: 4, 9: 6, 10: 6,
    11: 4, 12: 6, 13: 8, 14: 2, 15: 2,
    16: 2, 17: 3, 18: 3, 19: 6, 20: 6
  };
  const ROOM_TYPE_FALLBACK = {
    1: 'lux',
    9: 'bunk',
    10: 'bunk',
    11: 'lux',
    12: 'bunk',
    13: 'lux',
    18: 'economy',
    19: 'future',
    20: 'future'
  };

  function fallbackRoomPrice(id, capacity, type) {
    const baseByCapacity = {
      2: 1700,
      3: 2000,
      4: 2300,
      6: 2900,
      8: 3600
    };

    let price = Number(baseByCapacity[capacity] || (1500 + capacity * 280));
    if (type === 'lux') price += 450;
    if (type === 'economy') price -= 250;
    if (type === 'future') price -= 100;
    return Math.max(900, Math.round(price));
  }

  function normalizeRoomForChat(room, fallbackId) {
    const idCandidate = Number(room?.id || fallbackId);
    const id = Number.isFinite(idCandidate) && idCandidate >= 1 && idCandidate <= 20 ? idCandidate : fallbackId;
    const guestsRaw = Number(room?.capacity || room?.guests || ROOM_CAPACITY_FALLBACK[id] || 2);
    const guests = Number.isFinite(guestsRaw) ? Math.max(1, Math.min(20, Math.round(guestsRaw))) : (ROOM_CAPACITY_FALLBACK[id] || 2);
    const type = String(room?.type || ROOM_TYPE_FALLBACK[id] || 'standard').toLowerCase().slice(0, 20);
    const priceRaw = Number(room?.pricePerNight || room?.price || 0);
    const price = Number.isFinite(priceRaw) && priceRaw > 0 ? Math.round(priceRaw) : fallbackRoomPrice(id, guests, type);
    const title = String(room?.title || `Номер ${id}`).trim() || `Номер ${id}`;
    const summary = String(room?.summary || room?.description || '').trim();
    const description = summary || `Номер ${id} для ${guests} гостей.`;
    const amenities = Array.isArray(room?.amenities) ? room.amenities.filter(Boolean).map(v => String(v).trim()).slice(0, 12) : [];
    const rules = Array.isArray(room?.rules) ? room.rules.filter(Boolean).map(v => String(v).trim()).slice(0, 12) : [];
    const image = String(room?.cover || room?.image || `/storage/uploads/rooms/room-${id}/cover.webp`).trim();

    return {
      id,
      title,
      guests,
      type,
      price,
      description,
      amenities,
      rules,
      image,
      url: `/rooms/room-${id}/`
    };
  }

  function getLocalFallbackRooms() {
    const fallback = [];
    for (let id = 1; id <= 20; id += 1) {
      const guests = ROOM_CAPACITY_FALLBACK[id] || 2;
      const type = ROOM_TYPE_FALLBACK[id] || 'standard';
      const price = fallbackRoomPrice(id, guests, type);
      fallback.push(normalizeRoomForChat({
        id,
        title: `Номер ${id}`,
        summary: `Затишний номер №${id} для відпочинку біля озера Світязь.`,
        capacity: guests,
        type,
        pricePerNight: price,
        amenities: ['Wi-Fi', 'Приватна ванна', 'Телевізор'],
        rules: [
          'Паління в номері заборонено',
          'Шум після 22:00 заборонено'
        ],
        cover: `/storage/uploads/rooms/room-${id}/cover.webp`
      }, id));
    }
    return fallback;
  }

  // Load room data from API with resilient local fallback
  async function loadRoomsData() {
    ROOMS_DATA = [];

    // Try API first.
    try {
      const apiRes = await fetch('/api/rooms.php?action=list');
      if (apiRes.ok) {
        const apiData = await apiRes.json();
        if (apiData.success && Array.isArray(apiData.rooms) && apiData.rooms.length > 0) {
          ROOMS_DATA = apiData.rooms.map((room, index) => normalizeRoomForChat(room, index + 1));
          buildSystemPrompt();
          return;
        }
      }
    } catch (e) {
      console.warn('AI chat API preload failed, using local fallback:', e);
    }

    ROOMS_DATA = getLocalFallbackRooms();
    buildSystemPrompt();
  }

  // Build system prompt with room data
  function buildSystemPrompt() {
    const roomsList = ROOMS_DATA.map(r => {
      const amenitiesText = r.amenities.length > 0 ? ` Зручності: ${r.amenities.join(', ')}.` : '';
      const rulesText = r.rules && r.rules.length > 0 ? ` Правила: ${r.rules.join('; ')}.` : '';
      return `- Номер ${r.id} (${r.title}): ${r.guests} гостей, тип "${r.type}", ${r.price} грн/ніч. ${r.description}${amenitiesText}${rulesText}`;
    }).join('\n') || '- Дані номерів тимчасово недоступні.';

    // Збираємо загальні правила з першого номера
    const fallbackPolicy = window.SVH_SITE_POLICY || { checkIn: '14:00', checkOut: '11:00' };
    const commonRules = ROOMS_DATA.length > 0 && ROOMS_DATA[0].rules
      ? ROOMS_DATA[0].rules.filter(r => r.includes('Заселення') || r.includes('Паління') || r.includes('Шум')).join(', ')
      : `Заселення з ${fallbackPolicy.checkIn}, виселення до ${fallbackPolicy.checkOut}`;

    SYSTEM_PROMPT = `Ти — AI-помічник садиби SvityazHOME біля озера Світязь. Твоя задача — бути корисним співрозмовником для гостей: допомагати з підбором номерів, плануванням відпочинку та практичними питаннями.

Інформація про садибу:
- Розташування: село Світязь, вул. Лісова 55, 5-10 хвилин пішки до озера
- Зручності території: зелена територія, альтанки, мангал/BBQ, безкоштовна парковка, Wi-Fi
- Загальні правила: ${commonRules}
- Телефон для бронювання: +380 93 857 85 40

Доступні номери:
${roomsList}

Правила відповіді:
1. Відповідай українською мовою за замовчуванням (або мовою користувача), дружньо і по суті
2. Якщо рекомендуєш конкретний номер, додавай у кінці відповіді тег [ROOM:X] (можна кілька тегів)
3. Уточнюй потреби: кількість гостей, бюджет, побажання, дати
4. Якщо питають про ціни/правила/умови — давай точну інформацію з даних вище
5. Можеш відповідати і на ширші питання (про відпочинок, логістику, що взяти із собою, що подивитися поруч), але бажано пов'язуй відповідь з поїздкою у SvityazHOME
6. Якщо не впевнений у факті — прямо скажи про це і запропонуй перевірити телефоном: +380 93 857 85 40`;
  }

  function shouldEnableChat() {
    const page = document.body?.dataset?.page || '';
    if (page === 'error') return false;
    return true;
  }

  async function ensureRoomsDataLoaded() {
    if (roomsLoadPromise) return roomsLoadPromise;
    roomsLoadPromise = loadRoomsData().catch((error) => {
      console.warn('AI chat room preload failed:', error);
      ROOMS_DATA = getLocalFallbackRooms();
      buildSystemPrompt();
    });
    return roomsLoadPromise;
  }

  async function ensureChatBootstrapped() {
    if (isBootstrapped) return;
    if (bootstrapPromise) return bootstrapPromise;

    bootstrapPromise = (async () => {
      showTyping();
      try {
        await ensureRoomsDataLoaded();
        addMessage('assistant', getWelcomeMessage());
        isBootstrapped = true;
      } finally {
        hideTyping();
      }
    })();

    try {
      await bootstrapPromise;
    } finally {
      bootstrapPromise = null;
    }
  }

  // Chat state
  let isOpen = false;
  let isLoading = false;
  let messages = [];
  let chatContainer = null;

  // Initialize chat
  function init() {
    if (!shouldEnableChat()) return;
    createChatWidget();
    addEventListeners();

    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(() => {
        ensureRoomsDataLoaded();
      }, { timeout: 1800 });
    } else {
      window.setTimeout(() => {
        ensureRoomsDataLoaded();
      }, 1200);
    }
  }

  // Create chat widget HTML
  function createChatWidget() {
    const html = `
      <button class="ai-chat-toggle" aria-label="Відкрити чат з AI-помічником">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
        </svg>
      </button>

      <div class="ai-chat" role="dialog" aria-label="AI Помічник">
        <div class="ai-chat__header">
          <div class="ai-chat__title">
            <div class="ai-chat__title-icon">\uD83E\uDD16</div>
            <span>SvityazHOME AI</span>
          </div>
          <button class="ai-chat__close" aria-label="Закрити чат">&times;</button>
        </div>

        <div class="ai-chat__messages" id="aiChatMessages"></div>

        <div class="ai-chat__quick-actions">
          <button class="ai-chat__quick-btn" data-message="Шукаю номер для двох">Для пари</button>
          <button class="ai-chat__quick-btn" data-message="Потрібен номер для сім'ї з дітьми, 4 людини">Для сім'ї</button>
          <button class="ai-chat__quick-btn" data-message="Шукаю великий номер для компанії 6-8 людей">Для компанії</button>
          <button class="ai-chat__quick-btn" data-message="Який найдешевший номер?">Бюджетно</button>
        </div>

        <div class="ai-chat__input-area">
          <input type="text" class="ai-chat__input" id="aiChatInput" placeholder="Напишіть повідомлення..." autocomplete="off">
          <button class="ai-chat__send" id="aiChatSend" aria-label="Надіслати">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
            </svg>
          </button>
        </div>
      </div>
    `;

    const wrapper = document.createElement('div');
    wrapper.id = 'svityaz-ai-chat';
    wrapper.innerHTML = html;
    document.body.appendChild(wrapper);

    chatContainer = wrapper;
  }

  // Add event listeners
  function addEventListeners() {
    const toggle = chatContainer.querySelector('.ai-chat-toggle');
    const closeBtn = chatContainer.querySelector('.ai-chat__close');
    const input = chatContainer.querySelector('#aiChatInput');
    const sendBtn = chatContainer.querySelector('#aiChatSend');
    const quickBtns = chatContainer.querySelectorAll('.ai-chat__quick-btn');

    toggle.addEventListener('click', () => toggleChat(true));
    closeBtn.addEventListener('click', () => toggleChat(false));

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keypress', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });

    quickBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const message = btn.dataset.message;
        if (message) {
          input.value = message;
          sendMessage();
        }
      });
    });

    // Close on escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen) {
        toggleChat(false);
      }
    });
  }

  // Toggle chat visibility
  async function toggleChat(open) {
    isOpen = open;
    const chat = chatContainer.querySelector('.ai-chat');
    const toggle = chatContainer.querySelector('.ai-chat-toggle');

    if (open) {
      chat.classList.add('open');
      toggle.classList.add('hidden');
      if (!isBootstrapped) {
        await ensureChatBootstrapped();
      }
      chatContainer.querySelector('#aiChatInput').focus();
    } else {
      chat.classList.remove('open');
      toggle.classList.remove('hidden');
    }
  }

  // Add message to chat
  function addMessage(role, content, roomData = null) {
    const messagesEl = chatContainer.querySelector('#aiChatMessages');
    const messageEl = document.createElement('div');
    messageEl.className = `ai-chat__message ai-chat__message--${role}`;

    // Parse room recommendations from content
    const roomMatches = content.match(/\[ROOM:(\d+)\]/g);
    let displayContent = content.replace(/\[ROOM:\d+\]/g, '').trim();

    messageEl.innerHTML = `<div class="ai-chat__message-text">${formatMessage(displayContent)}</div>`;

    // Add room cards if mentioned
    if (roomMatches && role === 'assistant') {
      roomMatches.forEach(match => {
        const roomId = parseInt(match.match(/\d+/)[0]);
        const room = ROOMS_DATA.find(r => r.id === roomId);
        if (room) {
          messageEl.appendChild(createRoomCard(room));
        }
      });
    }

    messagesEl.appendChild(messageEl);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    messages.push({ role, content });
  }

  // Format message text
  function formatMessage(text) {
    const escaped = String(text || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
    return escaped
      .replace(/\n/g, '<br>')
      .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.*?)\*/g, '<em>$1</em>');
  }

  // Create room card element
  function createRoomCard(room) {
    const card = document.createElement('div');
    card.className = 'ai-chat__room-card';
    card.innerHTML = `
      <img src="${room.image}" alt="${room.title}" class="ai-chat__room-image" onerror="this.src='/assets/images/placeholders/no-image.svg'">
      <div class="ai-chat__room-info">
        <h4 class="ai-chat__room-title">${room.title}</h4>
        <div class="ai-chat__room-meta">
          <span class="ai-chat__room-pill">${room.guests} гостей</span>
          <span class="ai-chat__room-pill">${room.price} грн/ніч</span>
        </div>
        <a href="${room.url}" class="ai-chat__room-btn">Переглянути номер ></a>
      </div>
    `;
    return card;
  }

  // Show typing indicator
  function showTyping() {
    const messagesEl = chatContainer.querySelector('#aiChatMessages');
    const typing = document.createElement('div');
    typing.className = 'ai-chat__typing';
    typing.id = 'aiTypingIndicator';
    typing.innerHTML = `
      <div class="ai-chat__typing-dot"></div>
      <div class="ai-chat__typing-dot"></div>
      <div class="ai-chat__typing-dot"></div>
    `;
    messagesEl.appendChild(typing);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  // Hide typing indicator
  function hideTyping() {
    const typing = chatContainer.querySelector('#aiTypingIndicator');
    if (typing) typing.remove();
  }

  // Send message
  async function sendMessage() {
    const input = chatContainer.querySelector('#aiChatInput');
    const sendBtn = chatContainer.querySelector('#aiChatSend');
    const message = input.value.trim();

    if (!message || isLoading) return;
    if (!isBootstrapped) {
      await ensureChatBootstrapped();
    }

    // Add user message
    addMessage('user', message);
    input.value = '';

    const useProxy = canUseProxy();
    const useDirectApi = canUseDirectApi();
    if (!useProxy && !useDirectApi) {
      // Fallback: simple rule-based recommendations
      const reply = getOfflineRecommendation(message);
      addMessage('assistant', reply);
      return;
    }

    isLoading = true;
    sendBtn.disabled = true;
    showTyping();

    try {
      const historyMessages = messages
        .slice(-CONFIG.historyDepth)
        .map((m) => ({ role: m.role, content: m.content }));

      const requestBody = {
        model: CONFIG.model,
        messages: useProxy
          ? historyMessages
          : [{ role: 'system', content: SYSTEM_PROMPT }, ...historyMessages],
        max_tokens: CONFIG.maxTokens,
        temperature: CONFIG.temperature
      };

      let response;

      if (useProxy) {
        // Використовуємо PHP proxy (безпечно для хостингу)
        response = await fetch(CONFIG.proxyEndpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(requestBody)
        });
      } else {
        // Прямий запит до OpenAI (тільки для локального тестування)
        response = await fetch(CONFIG.apiEndpoint, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${CONFIG.apiKey}`
          },
          body: JSON.stringify(requestBody)
        });
      }

      if (!response.ok) {
        const errorData = await response.json().catch(() => null);
        const requestError = extractApiErrorMessage(errorData, `API request failed (${response.status})`);
        if (useProxy && shouldDisableProxy(requestError, response.status)) {
          proxyAvailable = false;
        }
        const responseError = new Error(requestError);
        responseError.status = response.status;
        throw responseError;
      }

      const data = await response.json();
      const reply = data.choices[0]?.message?.content || 'Вибачте, виникла помилка. Спробуйте ще раз.';

      hideTyping();
      addMessage('assistant', reply);

    } catch (error) {
      console.error('AI Chat Error:', error);
      hideTyping();
      const fallbackReason = error?.message || '';
      const fallbackStatus = Number(error?.status || 0);
      const shouldUseOffline = shouldFallbackToOffline(fallbackReason, fallbackStatus);
      if (shouldUseOffline) {
        if (useProxy && shouldDisableProxy(fallbackReason, fallbackStatus)) {
          proxyAvailable = false;
        }
        if (!offlineNoticeShown) {
          addMessage('assistant', 'Перемикаюсь у локальний режим. OpenAI тимчасово недоступний, але я можу допомогти підібрати номер за параметрами.');
          offlineNoticeShown = true;
        }
        const reply = getOfflineRecommendation(message);
        addMessage('assistant', reply);
      } else {
        addMessage('assistant', 'Вибачте, виникла помилка зв\'язку. Спробуйте ще раз або зателефонуйте нам: +380 93 857 85 40');
      }
    } finally {
      isLoading = false;
      sendBtn.disabled = false;
    }
  }

  // Offline recommendation (when no API key)
  function getOfflineRecommendation(message) {
    const msg = message.toLowerCase();
    const policy = window.SVH_SITE_POLICY || { checkIn: '14:00', checkOut: '11:00', prepayment: '30%' };
    const phone = '+380 93 857 85 40';
    const minPrice = ROOMS_DATA.length > 0 ? Math.min(...ROOMS_DATA.map((r) => r.price)) : null;
    const maxPrice = ROOMS_DATA.length > 0 ? Math.max(...ROOMS_DATA.map((r) => r.price)) : null;

    // Parse number of guests
    const guestMatch = msg.match(/(\d+)\s*(люд|гост|чолов|осіб)/);
    let guests = guestMatch ? parseInt(guestMatch[1], 10) : 0;

    // Keywords
    if (msg.includes('пар') || msg.includes('двох') || msg.includes('удвох')) guests = 2;
    if (msg.includes('сім\'') || msg.includes('діт')) guests = guests || 4;
    if (msg.includes('компан')) guests = guests || 6;

    // Budget keywords
    const cheap = msg.includes('дешев') || msg.includes('бюджет') || msg.includes('недорог');
    const lux = msg.includes('люкс') || msg.includes('кращ') || msg.includes('найкращ');

    // General questions that are useful even in offline mode
    if (/(привіт|добр(ий|ого)|hello|hi|вітаю)/.test(msg)) {
      return 'Привіт! Я можу допомогти з підбором номера, цінами, правилами, порадами для відпочинку біля озера і контактами для бронювання. Що цікавить найбільше?';
    }

    if (msg.includes('контакт') || msg.includes('телефон') || msg.includes('подзвон') || msg.includes('номер телефону')) {
      return `Для бронювання телефонуйте: ${phone}. Також можу одразу підібрати номери під вашу кількість гостей.`;
    }

    if (msg.includes('заїзд') || msg.includes('засел') || msg.includes('виїзд') || msg.includes('висел')) {
      return `Заселення з ${policy.checkIn}, виселення до ${policy.checkOut}. Якщо потрібно, підкажу найзручніші номери під ваш формат відпочинку.`;
    }

    if (msg.includes('передоплат') || msg.includes('оплат')) {
      return `Для підтвердження бронювання зазвичай потрібна передоплата ${policy.prepayment}. Деталі краще уточнити телефоном: ${phone}.`;
    }

    if (msg.includes('що подивит') || msg.includes('куди піти') || msg.includes('чим зайнятись') || msg.includes('дозвілля')) {
      return 'Поруч із садибою зручно відпочивати на Світязі: пляж, прогулянки біля озера, велосипедні маршрути, вечірній BBQ на території. Якщо скажете формат відпочинку (спокійно/активно), запропоную кращий номер.';
    }

    if ((msg.includes('ціна') || msg.includes('скільки коштує') || msg.includes('вартість')) && !guests) {
      if (minPrice && maxPrice) {
        return `Орієнтовно ціни по номерах зараз від ${minPrice} до ${maxPrice} грн/ніч. Напишіть кількість гостей і бюджет, і я підберу точні варіанти.`;
      }
      return 'Підкажіть кількість гостей і бюджет, і я підберу найкращі варіанти за ціною.';
    }

    let matchedRooms = [];
    let response = '';

    // Find rooms based on criteria using loaded data
    if (guests === 2 || msg.includes('двох') || msg.includes('пар')) {
      matchedRooms = ROOMS_DATA.filter((r) => r.guests === 2);
      response = 'Для пари чудово підійдуть наші затишні двомісні номери. Рекомендую:';
    } else if (guests === 3) {
      matchedRooms = lux
        ? ROOMS_DATA.filter((r) => r.guests === 3 && r.type === 'lux')
        : ROOMS_DATA.filter((r) => r.guests === 3);
      response = lux
        ? 'Для трьох гостей рекомендую наші люкс номери з усіма зручностями:'
        : 'Для трьох гостей підійдуть ці номери:';
    } else if (guests === 4 || msg.includes('сім\'')) {
      matchedRooms = ROOMS_DATA.filter((r) => r.guests === 4);
      response = 'Для чотирьох гостей або сім\'ї маємо чудові варіанти:';
    } else if (guests >= 5 && guests <= 6) {
      matchedRooms = ROOMS_DATA.filter((r) => r.guests >= 5 && r.guests <= 6);
      response = 'Для компанії до 6 людей рекомендую:';
    } else if (guests >= 7) {
      matchedRooms = ROOMS_DATA.filter((r) => r.guests >= 7);
      response = 'Для великої компанії маємо спеціальні номери:';
    } else if (cheap) {
      matchedRooms = [...ROOMS_DATA].sort((a, b) => a.price - b.price).slice(0, 4);
      response = 'Найбюджетніші варіанти:';
    } else if (lux) {
      matchedRooms = ROOMS_DATA.filter((r) => r.type === 'lux');
      response = 'Наші найкращі люкс номери:';
    } else {
      return 'Можу допомогти не тільки з підбором номера, а й з питаннями про ціни, заїзд/виїзд та відпочинок поруч.\n\nДля старту напишіть:\n• Скільки буде гостей\n• Бюджет за ніч\n• Бажані дати';
    }

    // Add room tags for matched rooms
    if (matchedRooms.length === 0) {
      response = `На жаль, не знайшов номерів за вашими критеріями. Спробуйте уточнити запит або подзвоніть нам: ${phone}`;
    } else {
      matchedRooms.slice(0, 3).forEach((room) => {
        response += ` [ROOM:${room.id}]`;
      });
    }

    return response;
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();



// WOW block removed — all animations handled by the primary system above
