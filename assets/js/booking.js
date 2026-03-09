(function () {
  'use strict';

  const form = document.getElementById('bookingRequestForm');
  if (!form) return;

  const statusNode = document.getElementById('bookingFormStatus');
  const bookingIdNode = document.getElementById('bookingRequestId');
  const submitBtn = document.getElementById('bookingSubmitButton');

  const guestsInput = form.elements.guests;
  const roomInput = form.elements.room;
  const checkinInput = form.elements.checkin;
  const checkoutInput = form.elements.checkout;
  const messageInput = form.elements.message;

  const guestsSelect = document.getElementById('booking-guests-select');
  const roomSelect = document.getElementById('booking-room-select');
  const guestsGrid = document.getElementById('bookingGuestsGrid');
  const roomGrid = document.getElementById('bookingRoomGrid');
  const roomCountHint = document.getElementById('bookingRoomCountHint');
  const roomAutoHint = document.getElementById('bookingRoomAutoHint');
  const minSubmitHint = document.getElementById('bookingMinSubmitHint');

  const privacyModal = document.getElementById('bookingPrivacyModal');
  const privacyOpenButtons = Array.from(document.querySelectorAll('[data-booking-privacy-open]'));
  const privacyCloseButtons = privacyModal
    ? Array.from(privacyModal.querySelectorAll('[data-booking-privacy-close]'))
    : [];

  const uaDateRe = /^(\d{2})\.(\d{2})\.(\d{4})$/;
  const isoDateRe = /^\d{4}-\d{2}-\d{2}$/;
  const roomCodeRe = /^room-(?:[1-9]|1[0-9]|20)$/;

  const isDevMode = document.documentElement.dataset.devMode === '1' || document.body.classList.contains('dev-mode');

  const ROOM_TYPE_LABELS = {
    lux: 'Люкс',
    standard: 'Стандарт',
    economy: 'Економ',
    bunk: 'Двоярусний',
    future: 'У підготовці'
  };

  const FALLBACK_ROOMS = [
    { id: 1, capacity: 3, type: 'lux' },
    { id: 2, capacity: 3, type: 'standard' },
    { id: 3, capacity: 4, type: 'standard' },
    { id: 4, capacity: 2, type: 'standard' },
    { id: 5, capacity: 4, type: 'standard' },
    { id: 6, capacity: 4, type: 'standard' },
    { id: 7, capacity: 4, type: 'standard' },
    { id: 8, capacity: 4, type: 'standard' },
    { id: 9, capacity: 6, type: 'bunk' },
    { id: 10, capacity: 6, type: 'bunk' },
    { id: 11, capacity: 4, type: 'lux' },
    { id: 12, capacity: 6, type: 'bunk' },
    { id: 13, capacity: 8, type: 'lux' },
    { id: 14, capacity: 2, type: 'standard' },
    { id: 15, capacity: 2, type: 'standard' },
    { id: 16, capacity: 2, type: 'standard' },
    { id: 17, capacity: 3, type: 'standard' },
    { id: 18, capacity: 3, type: 'economy' },
    { id: 19, capacity: 6, type: 'future' },
    { id: 20, capacity: 6, type: 'future' }
  ];

  let csrfToken = '';
  let minSubmitSeconds = 3;
  let maxGuests = 8;
  let formIssuedAtMs = Date.now();
  let isSubmitting = false;
  let roomsCatalog = [];

  const debugLog = (...args) => {
    if (!isDevMode) return;
    // eslint-disable-next-line no-console
    console.debug('[booking]', ...args);
  };

  const getTodayIso = () => {
    const now = new Date();
    const offset = now.getTimezoneOffset() * 60000;
    return new Date(now.getTime() - offset).toISOString().slice(0, 10);
  };

  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
  const isNativeDateField = (inputNode) => Boolean(inputNode) && String(inputNode.type || '').toLowerCase() === 'date';

  function guestWord(value) {
    const n = Math.abs(Number(value) || 0) % 100;
    const n1 = n % 10;
    if (n > 10 && n < 20) return 'гостей';
    if (n1 > 1 && n1 < 5) return 'гості';
    if (n1 === 1) return 'гість';
    return 'гостей';
  }

  function parseIsoDate(value) {
    if (!isoDateRe.test(value)) return null;

    const [yearPart, monthPart, dayPart] = value.split('-');
    const year = Number(yearPart);
    const month = Number(monthPart);
    const day = Number(dayPart);

    const date = new Date(Date.UTC(year, month - 1, day));
    if (Number.isNaN(date.getTime())) return null;
    if (date.getUTCFullYear() !== year) return null;
    if (date.getUTCMonth() + 1 !== month) return null;
    if (date.getUTCDate() !== day) return null;
    return date;
  }

  function isoToUaDate(isoDate) {
    return `${isoDate.slice(8, 10)}.${isoDate.slice(5, 7)}.${isoDate.slice(0, 4)}`;
  }

  function parseBookingDate(value) {
    const normalized = String(value || '').trim();
    if (normalized === '') return null;

    if (isoDateRe.test(normalized)) {
      const parsedIso = parseIsoDate(normalized);
      return parsedIso ? { iso: normalized, date: parsedIso } : null;
    }

    const uaMatch = normalized.match(uaDateRe);
    if (!uaMatch) return null;

    const day = uaMatch[1];
    const month = uaMatch[2];
    const year = uaMatch[3];
    const isoDate = `${year}-${month}-${day}`;
    const parsedUa = parseIsoDate(isoDate);
    return parsedUa ? { iso: isoDate, date: parsedUa } : null;
  }

  function normalizeDateInputValue(inputNode) {
    if (!inputNode) return;
    const parsed = parseBookingDate(inputNode.value);
    if (!parsed) return;
    inputNode.value = isNativeDateField(inputNode) ? parsed.iso : isoToUaDate(parsed.iso);
  }

  function formatDateInputOnType(inputNode) {
    if (!inputNode) return;
    if (isNativeDateField(inputNode)) return;
    const digits = String(inputNode.value || '').replace(/\D+/g, '').slice(0, 8);
    const chunks = [];
    if (digits.length > 0) chunks.push(digits.slice(0, 2));
    if (digits.length > 2) chunks.push(digits.slice(2, 4));
    if (digits.length > 4) chunks.push(digits.slice(4, 8));
    inputNode.value = chunks.join('.');
  }

  function looksLikeNonsense(text) {
    const raw = String(text || '').trim().toLowerCase();
    if (raw.length < 8) return false;

    const normalized = raw
      .replace(/[^a-zа-яіїєґ\s]/gi, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    if (normalized.length < 8) return false;
    if (/(.)\1{5,}/u.test(normalized)) return true;

    const compact = normalized.replace(/\s+/g, '');
    const uniqueRatio = new Set(compact).size / Math.max(1, compact.length);

    const vowelsRe = /[aeiouyаеиіоуюяєіїґ]/i;
    const tokens = normalized.split(' ').filter(Boolean);

    let longNoVowelTokens = 0;
    let repeatTokenCount = 0;
    const seen = new Map();

    tokens.forEach((token) => {
      if (token.length >= 6 && !vowelsRe.test(token)) {
        longNoVowelTokens += 1;
      }
      const prev = seen.get(token) || 0;
      seen.set(token, prev + 1);
      if (prev + 1 >= 3 && token.length >= 3) {
        repeatTokenCount += 1;
      }
    });

    if (longNoVowelTokens >= 2) return true;
    if (repeatTokenCount >= 1) return true;
    if (compact.length >= 16 && uniqueRatio < 0.24) return true;
    return false;
  }

  function setStatus(message, tone) {
    if (!statusNode) return;
    statusNode.textContent = message || '';
    statusNode.classList.remove('error', 'success');
    if (tone === 'error') statusNode.classList.add('error');
    if (tone === 'success') statusNode.classList.add('success');
  }

  function setLoading(isLoading) {
    if (!submitBtn) return;
    submitBtn.disabled = isLoading;
    submitBtn.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    submitBtn.textContent = isLoading ? 'Надсилаємо...' : 'Надіслати заявку';
  }

  function getSelectedGuests() {
    const value = Number(guestsInput ? guestsInput.value : 0);
    return Number.isFinite(value) ? value : 0;
  }

  function getSelectedRoom() {
    return String(roomInput ? roomInput.value : '').trim();
  }

  function setGuestsValue(value) {
    if (!guestsInput) return;

    const numeric = Number(value);
    const normalized = Number.isFinite(numeric) ? clamp(Math.round(numeric), 1, maxGuests) : 0;
    guestsInput.value = normalized > 0 ? String(normalized) : '';

    if (guestsGrid) {
      guestsGrid.querySelectorAll('button[data-value]').forEach((button) => {
        const active = Number(button.dataset.value) === normalized;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-checked', active ? 'true' : 'false');
      });
    }

    if (guestsSelect) {
      guestsSelect.value = normalized > 0 ? String(normalized) : '';
    }

    if (roomsCatalog.length > 0) {
      renderRoomButtons();
    }
  }

  function setRoomValue(code) {
    if (!roomInput) return;

    const normalized = String(code || '').trim();
    roomInput.value = roomCodeRe.test(normalized) ? normalized : '';

    if (roomGrid) {
      roomGrid.querySelectorAll('button[data-room]').forEach((button) => {
        const active = button.dataset.room === roomInput.value;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-checked', active ? 'true' : 'false');
      });
    }

    if (roomSelect) {
      roomSelect.value = roomInput.value;
    }
  }

  function ensureSelectedRoomIsVisible(visibleRooms) {
    const selected = getSelectedRoom();
    if (!selected) return;
    const stillVisible = visibleRooms.some((room) => room.code === selected);
    if (!stillVisible) {
      setRoomValue('');
    }
  }

  function getFilteredRooms() {
    const selectedGuests = getSelectedGuests();

    return roomsCatalog.filter((room) => selectedGuests <= 0 || room.capacity >= selectedGuests);
  }

  function renderGuestButtons() {
    if (guestsGrid) {
      guestsGrid.innerHTML = '';

      for (let i = 1; i <= maxGuests; i += 1) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'booking-chip';
        button.dataset.value = String(i);
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-checked', 'false');
        button.textContent = String(i);
        guestsGrid.appendChild(button);
      }
    }

    if (guestsSelect) {
      guestsSelect.innerHTML = '';
      for (let i = 1; i <= maxGuests; i += 1) {
        const option = document.createElement('option');
        option.value = String(i);
        option.textContent = `${i} ${guestWord(i)}`;
        guestsSelect.appendChild(option);
      }
    }

    const selectedGuests = getSelectedGuests();
    if (selectedGuests >= 1 && selectedGuests <= maxGuests) {
      setGuestsValue(selectedGuests);
    } else {
      setGuestsValue(Math.min(2, maxGuests));
    }
  }

  function renderRoomButtons() {
    const visibleRooms = getFilteredRooms();
    ensureSelectedRoomIsVisible(visibleRooms);

    if (!getSelectedRoom()) {
      if (visibleRooms.length > 0) {
        setRoomValue(visibleRooms[0].code);
      } else {
        setRoomValue('');
      }
    }

    if (roomGrid) {
      roomGrid.innerHTML = '';

      if (visibleRooms.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'booking-room-empty';
        empty.textContent = 'Немає номерів для обраної кількості гостей.';
        roomGrid.appendChild(empty);
      } else {
        visibleRooms.forEach((room) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'booking-room-btn';
          button.dataset.room = room.code;
          button.setAttribute('role', 'radio');
          button.setAttribute('aria-checked', 'false');

          const title = document.createElement('strong');
          title.textContent = `Номер ${room.id}`;

          const meta = document.createElement('small');
          const typeText = ROOM_TYPE_LABELS[room.type] || 'Стандарт';
          meta.textContent = `${room.capacity} ${guestWord(room.capacity)} · ${typeText}`;

          button.appendChild(title);
          button.appendChild(meta);
          roomGrid.appendChild(button);
        });
      }
    }

    if (roomSelect) {
      roomSelect.innerHTML = '';
      if (visibleRooms.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'Немає доступних номерів';
        roomSelect.appendChild(option);
      } else {
        visibleRooms.forEach((room) => {
          const option = document.createElement('option');
          option.value = room.code;
          option.textContent = `Номер ${room.id} (${room.capacity} ${guestWord(room.capacity)})`;
          roomSelect.appendChild(option);
        });
      }
    }

    setRoomValue(getSelectedRoom());

    if (roomCountHint) {
      const count = visibleRooms.length;
      roomCountHint.textContent = count > 0
        ? `Доступно ${count} номер(ів) для вибору.`
        : 'Немає номерів для обраної кількості гостей.';
    }

    if (roomAutoHint) {
      const guests = getSelectedGuests();
      if (guests > 0) {
        roomAutoHint.textContent = `Показуємо номери для ${guests} ${guestWord(guests)} і більше.`;
      } else {
        roomAutoHint.textContent = 'Після вибору гостей покажемо лише номери, що підходять за місткістю.';
      }
    }
  }

  function roomByCode(code) {
    return roomsCatalog.find((room) => room.code === code) || null;
  }

  function normalizeRoomType(typeValue) {
    const raw = String(typeValue || '').toLowerCase().trim();
    if (Object.prototype.hasOwnProperty.call(ROOM_TYPE_LABELS, raw)) return raw;
    return 'standard';
  }

  function normalizeRoomRecord(record) {
    const id = Number(record && record.id);
    if (!Number.isFinite(id) || id < 1 || id > 20) return null;

    const capacityRaw = Number(record.capacity || record.guests || 0);
    const capacity = Number.isFinite(capacityRaw) && capacityRaw > 0 ? clamp(Math.round(capacityRaw), 1, 20) : 2;

    return {
      id,
      code: `room-${id}`,
      capacity,
      type: normalizeRoomType(record.type),
    };
  }

  async function loadRoomCatalog() {
    let loaded = [];

    try {
      const response = await fetch('/api/rooms.php?action=list', {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' }
      });

      if (response.ok) {
        const data = await response.json();
        if (data && data.success === true && Array.isArray(data.rooms)) {
          loaded = data.rooms
            .map(normalizeRoomRecord)
            .filter(Boolean);
        }
      }
    } catch (error) {
      debugLog('Failed to load rooms from API, using fallback.', error);
    }

    if (loaded.length === 0) {
      loaded = FALLBACK_ROOMS.map((room) => normalizeRoomRecord(room)).filter(Boolean);
    }

    const uniqueByCode = new Map();
    loaded.forEach((room) => {
      if (!uniqueByCode.has(room.code)) {
        uniqueByCode.set(room.code, room);
      }
    });

    roomsCatalog = Array.from(uniqueByCode.values()).sort((a, b) => a.id - b.id);
    debugLog('Rooms loaded:', roomsCatalog.length);
  }

  function updateDateConstraints() {
    const todayIso = getTodayIso();
    const checkinParsed = parseBookingDate(checkinInput ? checkinInput.value : '');
    const checkoutParsed = parseBookingDate(checkoutInput ? checkoutInput.value : '');

    if (checkinInput && isNativeDateField(checkinInput)) {
      checkinInput.min = todayIso;
    }

    if (checkoutInput && isNativeDateField(checkoutInput)) {
      checkoutInput.min = checkinParsed ? checkinParsed.iso : todayIso;
    }

    if (checkinParsed && checkoutParsed && checkoutParsed.date <= checkinParsed.date && checkoutInput) {
      checkoutInput.value = '';
    }
  }

  function updatePolicyConstraints(payload) {
    if (!payload || typeof payload !== 'object') return;
    const bookingPolicy = payload.booking && typeof payload.booking === 'object' ? payload.booking : {};

    const loadedMaxGuests = Number(bookingPolicy.max_guests);
    if (Number.isFinite(loadedMaxGuests) && loadedMaxGuests > 0 && loadedMaxGuests <= 20) {
      maxGuests = loadedMaxGuests;
      renderGuestButtons();
    }

    const loadedMinSeconds = Number(bookingPolicy.min_submit_seconds);
    if (Number.isFinite(loadedMinSeconds) && loadedMinSeconds >= 1 && loadedMinSeconds <= 60) {
      minSubmitSeconds = loadedMinSeconds;
    }

    if (minSubmitHint) {
      minSubmitHint.textContent = `Антиспам-захист: форма стане доступною для надсилання через ${minSubmitSeconds} с після завантаження.`;
    }
  }

  function validateForm() {
    const errors = [];

    const nameValue = String(form.elements.name.value || '').trim();
    const phoneValue = String(form.elements.phone.value || '').trim();
    const emailValue = String(form.elements.email.value || '').trim();
    const checkinValue = String(form.elements.checkin.value || '').trim();
    const checkoutValue = String(form.elements.checkout.value || '').trim();
    const roomValue = getSelectedRoom();
    const guestsValue = getSelectedGuests();
    const messageValue = String(messageInput ? messageInput.value || '' : '').trim();
    const consentChecked = Boolean(form.elements.consent.checked);

    if (nameValue.length < 2 || nameValue.length > 100) {
      errors.push("Вкажіть ім'я (2-100 символів).");
    } else if (looksLikeNonsense(nameValue)) {
      errors.push("Ім'я виглядає некоректно. Перевірте, будь ласка, написання.");
    }

    const phoneDigits = phoneValue.replace(/\D+/g, '');
    if (phoneDigits.length < 9 || phoneDigits.length > 15) {
      errors.push('Вкажіть коректний телефон (9-15 цифр).');
    }

    if (emailValue !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue)) {
      errors.push('Email має некоректний формат.');
    }

    const checkinParsed = parseBookingDate(checkinValue);
    const checkoutParsed = parseBookingDate(checkoutValue);
    if (!checkinParsed || !checkoutParsed) {
      errors.push('Вкажіть коректні дати у форматі дд.мм.рррр.');
    } else {
      const today = parseIsoDate(getTodayIso());
      if (today && checkinParsed.date < today) {
        errors.push('Дата заїзду не може бути в минулому.');
      }
      if (checkoutParsed.date <= checkinParsed.date) {
        errors.push('Дата виїзду має бути пізніше дати заїзду.');
      }
    }

    if (!Number.isFinite(guestsValue) || guestsValue < 1 || guestsValue > maxGuests) {
      errors.push(`Кількість гостей має бути від 1 до ${maxGuests}.`);
    }

    if (!roomCodeRe.test(roomValue)) {
      errors.push('Оберіть конкретний номер із переліку.');
    } else {
      const selectedRoom = roomByCode(roomValue);
      if (selectedRoom && guestsValue > selectedRoom.capacity) {
        errors.push(`Для номера ${selectedRoom.id} доступно до ${selectedRoom.capacity} ${guestWord(selectedRoom.capacity)}.`);
      }
    }

    if (messageValue.length > 2000) {
      errors.push('Коментар занадто довгий (макс. 2000 символів).');
    } else if (messageValue.length >= 12 && looksLikeNonsense(messageValue)) {
      errors.push('Коментар виглядає некоректно. Уточніть текст, будь ласка.');
    }

    if (!consentChecked) {
      errors.push('Потрібна згода на обробку персональних даних.');
    }

    const elapsedMs = Date.now() - formIssuedAtMs;
    if (elapsedMs < minSubmitSeconds * 1000) {
      errors.push(`Зачекайте ${minSubmitSeconds} с перед надсиланням форми.`);
    }

    return errors;
  }

  async function refreshCsrfAndPolicy() {
    const response = await fetch('/api/booking.php?action=csrf', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json'
      }
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();
    if (!data || data.success !== true || typeof data.csrf_token !== 'string' || data.csrf_token === '') {
      throw new Error('Invalid CSRF response');
    }

    csrfToken = data.csrf_token;
    formIssuedAtMs = Date.now();

    if (data.policy) {
      updatePolicyConstraints(data.policy);
    }

    debugLog('CSRF and policy loaded');
  }

  function buildPayload() {
    const formData = new FormData(form);
    const checkinRaw = String(formData.get('checkin') || '').trim();
    const checkoutRaw = String(formData.get('checkout') || '').trim();
    const checkinParsed = parseBookingDate(checkinRaw);
    const checkoutParsed = parseBookingDate(checkoutRaw);

    const payload = {
      name: String(formData.get('name') || '').trim(),
      phone: String(formData.get('phone') || '').trim(),
      email: String(formData.get('email') || '').trim(),
      checkin: checkinParsed ? checkinParsed.iso : checkinRaw,
      checkout: checkoutParsed ? checkoutParsed.iso : checkoutRaw,
      guests: Number(formData.get('guests') || 0),
      room: String(formData.get('room') || '').trim(),
      message: String(formData.get('message') || '').trim(),
      consent: Boolean(formData.get('consent')),
      website: String(formData.get('website') || '')
    };

    debugLog('Submit payload preview', {
      ...payload,
      phone: payload.phone ? '[provided]' : '',
      email: payload.email ? '[provided]' : ''
    });

    return payload;
  }

  async function handleSubmit(event) {
    event.preventDefault();
    if (isSubmitting) return;

    if (bookingIdNode) bookingIdNode.textContent = '';
    setStatus('', '');

    const errors = validateForm();
    if (errors.length > 0) {
      setStatus(errors.join(' '), 'error');
      return;
    }

    if (!csrfToken) {
      try {
        await refreshCsrfAndPolicy();
      } catch {
        setStatus('Не вдалося ініціалізувати захист форми. Оновіть сторінку.', 'error');
        return;
      }
    }

    isSubmitting = true;
    setLoading(true);

    try {
      const response = await fetch('/api/booking.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(buildPayload())
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.success !== true) {
        const message = typeof data.error === 'string' && data.error.trim() !== ''
          ? data.error.trim()
          : 'Не вдалося надіслати заявку. Спробуйте ще раз.';

        if (response.status === 400 && /csrf/i.test(message)) {
          csrfToken = '';
          refreshCsrfAndPolicy().catch(() => {});
        }

        setStatus(message, 'error');
        return;
      }

      const requestId = typeof data.booking_id === 'string' ? data.booking_id : '';
      const okMessage = typeof data.message === 'string' && data.message.trim() !== ''
        ? data.message.trim()
        : 'Дякуємо! Заявку отримано.';

      setStatus(okMessage, 'success');
      if (bookingIdNode && requestId !== '') {
        bookingIdNode.textContent = `Номер заявки: ${requestId}`;
      }

      form.reset();
      renderGuestButtons();
      setRoomValue('');
      updateDateConstraints();
      renderRoomButtons();
      await refreshCsrfAndPolicy().catch(() => {});
    } catch {
      setStatus('Помилка мережі. Перевірте з\'єднання та спробуйте ще раз.', 'error');
    } finally {
      isSubmitting = false;
      setLoading(false);
    }
  }

  function bindDateField(inputNode) {
    if (!inputNode) return;

    if (!isNativeDateField(inputNode)) {
      inputNode.addEventListener('input', () => {
        formatDateInputOnType(inputNode);
      });
    }

    inputNode.addEventListener('blur', () => {
      normalizeDateInputValue(inputNode);
      updateDateConstraints();
    });

    inputNode.addEventListener('change', updateDateConstraints);
  }

  function openPrivacyModal(event) {
    if (!privacyModal) return;
    if (event) event.preventDefault();

    privacyModal.classList.add('is-open');
    privacyModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('is-modal-open');

    const closeButton = privacyModal.querySelector('[data-booking-privacy-close]');
    if (closeButton) closeButton.focus();
  }

  function closePrivacyModal() {
    if (!privacyModal) return;
    privacyModal.classList.remove('is-open');
    privacyModal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('is-modal-open');
  }

  function bindPrivacyModal() {
    if (!privacyModal) return;

    privacyOpenButtons.forEach((button) => {
      button.addEventListener('click', openPrivacyModal);
    });

    privacyCloseButtons.forEach((button) => {
      button.addEventListener('click', closePrivacyModal);
    });

    privacyModal.addEventListener('click', (event) => {
      if (event.target === privacyModal) {
        closePrivacyModal();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && privacyModal.classList.contains('is-open')) {
        closePrivacyModal();
      }
    });
  }

  function bindChoiceControls() {
    if (guestsGrid) {
      guestsGrid.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-value]');
        if (!button) return;
        const value = Number(button.dataset.value || 0);
        setGuestsValue(value);
      });
    }

    if (guestsSelect) {
      guestsSelect.addEventListener('change', () => {
        const value = Number(guestsSelect.value || 0);
        setGuestsValue(value);
      });
    }

    if (roomGrid) {
      roomGrid.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-room]');
        if (!button) return;
        setRoomValue(button.dataset.room || '');
      });
    }

    if (roomSelect) {
      roomSelect.addEventListener('change', () => {
        setRoomValue(roomSelect.value || '');
      });
    }

  }

  async function init() {
    renderGuestButtons();
    await loadRoomCatalog();
    renderRoomButtons();

    normalizeDateInputValue(checkinInput);
    normalizeDateInputValue(checkoutInput);
    updateDateConstraints();

    bindDateField(checkinInput);
    bindDateField(checkoutInput);
    bindChoiceControls();
    bindPrivacyModal();

    form.addEventListener('submit', handleSubmit);

    try {
      await refreshCsrfAndPolicy();
    } catch {
      setStatus('Не вдалося ініціалізувати CSRF-токен. Оновіть сторінку.', 'error');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
