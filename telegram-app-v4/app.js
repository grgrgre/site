(function () {
  window.__SVH_TG_APP_BOOTED = true;
  var state = {
    tg: null,
    initData: '',
    accessToken: '',
    viewer: null,
    counts: { new: 0, total: 0 },
    bookings: [],
    filtered: [],
    activeBooking: null,
    activeBookingId: '',
    query: '',
    loading: false,
    bootTimeoutId: 0
  };

  var els = {
    viewerLabel: document.getElementById('viewerLabel'),
    newCount: document.getElementById('newCount'),
    totalCount: document.getElementById('totalCount'),
    searchInput: document.getElementById('searchInput'),
    clearSearchButton: document.getElementById('clearSearchButton'),
    refreshButton: document.getElementById('refreshButton'),
    bookingList: document.getElementById('bookingList'),
    detailView: document.getElementById('detailView'),
    listHint: document.getElementById('listHint'),
    detailHint: document.getElementById('detailHint')
  };

  function extend(target, source) {
    var result = target || {};
    var input = source || {};
    var keys = Object.keys(input);
    var index = 0;
    for (index = 0; index < keys.length; index += 1) {
      result[keys[index]] = input[keys[index]];
    }
    return result;
  }

  function escapeHtml(value) {
    var safeValue = value === null || value === undefined ? '' : value;
    return String(safeValue)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function readQueryParam(name) {
    var raw = String(window.location.search || '').replace(/^\?/, '');
    var parts;
    var index;
    var pair;
    var key;
    var target = String(name || '').trim();
    if (!target) {
      return '';
    }
    if (!raw) {
      return '';
    }

    parts = raw.split('&');
    for (index = 0; index < parts.length; index += 1) {
      pair = parts[index].split('=');
      key = decodeURIComponent(pair[0] || '');
      if (key === target) {
        return String(decodeURIComponent(pair[1] || '')).trim();
      }
    }

    return '';
  }

  function readBookingIdFromUrl() {
    return readQueryParam('booking_id').toUpperCase();
  }

  function setViewerText(text) {
    els.viewerLabel.textContent = text;
  }

  function setCounts() {
    els.newCount.textContent = String((state.counts && state.counts.new) || 0);
    els.totalCount.textContent = String((state.counts && state.counts.total) || 0);
  }

  function clearBootTimeout() {
    if (state.bootTimeoutId) {
      window.clearTimeout(state.bootTimeoutId);
      state.bootTimeoutId = 0;
    }
  }

  function showLoading(message) {
    els.bookingList.innerHTML = '<div class="app-loading">' + escapeHtml(message) + '</div>';
    els.detailView.innerHTML = '<div class="app-loading">' + escapeHtml(message) + '</div>';
  }

  function renderError(message) {
    els.bookingList.innerHTML = '<div class="app-error">' + escapeHtml(message) + '</div>';
    els.detailView.innerHTML = '<div class="app-error">' + escapeHtml(message) + '</div>';
    els.listHint.textContent = 'Не вдалося завантажити заявки.';
    els.detailHint.textContent = 'Сталася помилка.';
  }

  function apiRequest(action, extra, onSuccess, onError) {
    var xhr = new XMLHttpRequest();
    var payload = {
      action: action,
      init_data: state.initData
    };
    if (state.accessToken) {
      payload.access_token = state.accessToken;
    }

    extend(payload, extra || {});

    xhr.open('POST', '/api/telegram-miniapp.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.timeout = 15000;

    xhr.onreadystatechange = function () {
      var response;
      if (xhr.readyState !== 4) {
        return;
      }

      if (xhr.status === 0) {
        onError('Не вдалося підключитися до сервера.');
        return;
      }

      try {
        response = JSON.parse(xhr.responseText || '{}');
      } catch (error) {
        onError('Сервер повернув некоректну відповідь.');
        return;
      }

      if (xhr.status < 200 || xhr.status >= 300 || response.success === false) {
        onError(response.error || 'Сервер повернув помилку.');
        return;
      }

      onSuccess(response);
    };

    xhr.onerror = function () {
      onError('Помилка мережі при завантаженні заявок.');
    };

    xhr.ontimeout = function () {
      onError('Сервер відповідає занадто довго. Спробуйте ще раз.');
    };

    xhr.send(JSON.stringify(payload));
  }

  function applyFilter() {
    var needle = String(state.query || '').trim().toLowerCase();
    var index;
    var booking;
    var haystack;

    state.filtered = [];
    if (!needle) {
      state.filtered = state.bookings.slice();
    } else {
      for (index = 0; index < state.bookings.length; index += 1) {
        booking = state.bookings[index];
        haystack = [
          booking.booking_id,
          booking.name,
          booking.phone,
          booking.email,
          booking.room_label
        ].join(' ').toLowerCase();

        if (haystack.indexOf(needle) !== -1) {
          state.filtered.push(booking);
        }
      }
    }

    renderList();
  }

  function renderList() {
    var html = '';
    var index;
    var booking;
    var activeClass;
    var previewHtml;

    if (state.loading) {
      els.listHint.textContent = 'Завантаження...';
      els.bookingList.innerHTML = '<div class="app-loading">Завантажую список заявок...</div>';
      return;
    }

    els.listHint.textContent = state.filtered.length > 0
      ? 'Показано ' + state.filtered.length + ' заявок.'
      : 'Нічого не знайдено.';

    if (state.filtered.length === 0) {
      els.bookingList.innerHTML = '<div class="list-empty">Немає заявок для показу.</div>';
      return;
    }

    for (index = 0; index < state.filtered.length; index += 1) {
      booking = state.filtered[index];
      activeClass = booking.booking_id === state.activeBookingId ? ' is-active' : '';
      previewHtml = booking.preview
        ? '<div class="booking-preview">' + escapeHtml(booking.preview) + '</div>'
        : '';

      html += ''
        + '<button class="booking-item' + activeClass + '" type="button" data-booking-id="' + escapeHtml(booking.booking_id) + '">'
        + '  <div class="booking-topline">'
        + '    <span class="booking-title">' + escapeHtml(booking.name || booking.booking_id) + '</span>'
        + '    <span class="status-pill" data-tone="' + escapeHtml(booking.status_tone) + '">' + escapeHtml(booking.status_label) + '</span>'
        + '  </div>'
        + '  <div class="booking-subtitle">' + escapeHtml(booking.booking_id) + ' · ' + escapeHtml(booking.room_label || booking.room_code || 'Номер не вказано') + '</div>'
        + '  <div class="booking-meta">'
        + '    <span class="chip">' + escapeHtml(booking.checkin_date) + ' → ' + escapeHtml(booking.checkout_date) + '</span>'
        + '    <span class="chip">' + escapeHtml(String(booking.guests || 0)) + ' гостей</span>'
        + '  </div>'
        + previewHtml
        + '</button>';
    }

    els.bookingList.innerHTML = html;

    Array.prototype.forEach.call(els.bookingList.querySelectorAll('[data-booking-id]'), function (button) {
      button.addEventListener('click', function () {
        openBooking(button.getAttribute('data-booking-id') || '');
      });
    });
  }

  function renderDetail() {
    var booking;
    var callButton;
    var emailButton;
    var processButton;
    var restoreButton;

    if (!state.activeBooking) {
      els.detailHint.textContent = "Деталі з'являться тут.";
      els.detailView.innerHTML = '<div class="detail-empty">Оберіть заявку зі списку або відкрийте її з кнопки в боті.</div>';
      return;
    }

    booking = state.activeBooking;
    callButton = booking.tel_url
      ? '<a class="action-button secondary" href="' + escapeHtml(booking.tel_url) + '">Подзвонити</a>'
      : '';
    emailButton = booking.mailto_url
      ? '<a class="action-button secondary" href="' + escapeHtml(booking.mailto_url) + '">Email</a>'
      : '';
    processButton = booking.can_mark_processed
      ? '<button class="status-button primary" type="button" data-status="processed">Завершити заявку</button>'
      : '';
    restoreButton = booking.can_mark_new
      ? '<button class="status-button warn" type="button" data-status="new">Повернути в нові</button>'
      : '';

    els.detailHint.textContent = booking.booking_id;
    els.detailView.innerHTML = ''
      + '<article class="detail-card">'
      + '  <div class="detail-header">'
      + '    <div>'
      + '      <h3>' + escapeHtml(booking.name || booking.booking_id) + '</h3>'
      + '      <div class="booking-subtitle">' + escapeHtml(booking.booking_id) + ' · ' + escapeHtml(booking.room_label || booking.room_code || 'Номер не вказано') + '</div>'
      + '    </div>'
      + '    <span class="status-pill" data-tone="' + escapeHtml(booking.status_tone) + '">' + escapeHtml(booking.status_label) + '</span>'
      + '  </div>'
      + '  <div class="detail-grid">'
      + '    <div class="detail-field"><span>Дати</span><strong>' + escapeHtml(booking.checkin_date) + ' → ' + escapeHtml(booking.checkout_date) + '</strong></div>'
      + '    <div class="detail-field"><span>Гості</span><strong>' + escapeHtml(String(booking.guests || 0)) + '</strong></div>'
      + '    <div class="detail-field"><span>Телефон</span><strong>' + (booking.phone ? '<a href="' + escapeHtml(booking.tel_url || '#') + '">' + escapeHtml(booking.phone) + '</a>' : 'не вказано') + '</strong></div>'
      + '    <div class="detail-field"><span>Email</span><strong>' + (booking.email ? '<a href="' + escapeHtml(booking.mailto_url || '#') + '">' + escapeHtml(booking.email) + '</a>' : 'не вказано') + '</strong></div>'
      + '    <div class="detail-field"><span>Створено</span><strong>' + escapeHtml(booking.created_at || '—') + '</strong></div>'
      + '    <div class="detail-field"><span>Номер</span><strong>' + escapeHtml(booking.room_label || booking.room_code || 'не вказано') + '</strong></div>'
      + '  </div>'
      + '  <div class="detail-message">' + escapeHtml(booking.message || 'Без коментаря від гостя.') + '</div>'
      + '  <div class="detail-actions">'
      + '    <a class="action-button primary" href="' + escapeHtml(booking.admin_url) + '" target="_blank" rel="noopener noreferrer">Відкрити в адмінці</a>'
      +      callButton
      +      emailButton
      + '  </div>'
      + '  <div class="status-actions">'
      +      processButton
      +      restoreButton
      + '  </div>'
      + '</article>';

    Array.prototype.forEach.call(els.detailView.querySelectorAll('[data-status]'), function (button) {
      button.addEventListener('click', function () {
        updateStatus(button.getAttribute('data-status') || '');
      });
    });
  }

  function syncBackButton() {
    if (!state.tg || !state.tg.BackButton) {
      return;
    }

    if (window.innerWidth <= 900 && state.activeBookingId) {
      state.tg.BackButton.show();
      state.tg.BackButton.onClick(function () {
        state.activeBooking = null;
        state.activeBookingId = '';
        renderList();
        renderDetail();
        if (state.tg && state.tg.BackButton) {
          state.tg.BackButton.hide();
        }
      });
      return;
    }

    state.tg.BackButton.hide();
  }

  function bootstrap() {
    state.loading = true;
    showLoading('Завантажую заявки...');
    clearBootTimeout();
    state.bootTimeoutId = window.setTimeout(function () {
      renderError('Mini App завис на завантаженні. Натисніть «Оновити» або відкрийте кнопку ще раз.');
      setViewerText('Запит завис або був заблокований.');
      state.loading = false;
    }, 16000);

    apiRequest('bootstrap', {
      booking_id: state.activeBookingId || readBookingIdFromUrl()
    }, function (payload) {
      var viewerName = '';
      clearBootTimeout();
      state.loading = false;
      state.viewer = payload.viewer || null;
      state.counts = payload.counts || { new: 0, total: 0 };
      state.bookings = payload.bookings || [];
      state.activeBooking = payload.active_booking || null;
      state.activeBookingId = '';

      if (state.activeBooking && state.activeBooking.booking_id) {
        state.activeBookingId = state.activeBooking.booking_id;
      } else if (state.bookings[0] && state.bookings[0].booking_id) {
        state.activeBookingId = state.bookings[0].booking_id;
      }

      setCounts();

      if (state.viewer) {
        viewerName = [state.viewer.first_name || '', state.viewer.last_name || ''].join(' ').replace(/\s+/g, ' ').trim();
        if (state.viewer.username) {
          viewerName = viewerName ? viewerName + ' · @' + state.viewer.username : '@' + state.viewer.username;
        }
        setViewerText(viewerName || ('ID ' + state.viewer.id));
      } else {
        setViewerText('Mini App підключено.');
      }

      applyFilter();
      renderDetail();
      syncBackButton();
    }, function (message) {
      clearBootTimeout();
      state.loading = false;
      renderError(message || 'Не вдалося завантажити заявки.');
      setViewerText('Mini App не зміг отримати дані.');
    });
  }

  function openBooking(bookingId) {
    var id = String(bookingId || '').trim().toUpperCase();
    if (!id) {
      return;
    }

    state.activeBookingId = id;
    renderList();
    els.detailView.innerHTML = '<div class="app-loading">Відкриваю заявку...</div>';

    apiRequest('booking', { booking_id: id }, function (payload) {
      state.activeBooking = payload.booking || null;
      renderDetail();
      syncBackButton();
    }, function (message) {
      els.detailView.innerHTML = '<div class="app-error">' + escapeHtml(message || 'Не вдалося відкрити заявку.') + '</div>';
    });
  }

  function updateStatus(status) {
    var bookingId = state.activeBookingId;
    if (!bookingId || !status) {
      return;
    }

    Array.prototype.forEach.call(els.detailView.querySelectorAll('[data-status]'), function (button) {
      button.disabled = true;
    });

    apiRequest('set_status', {
      booking_id: bookingId,
      status: status
    }, function (payload) {
      var index;
      state.counts = payload.counts || state.counts;
      state.activeBooking = payload.booking || state.activeBooking;

      for (index = 0; index < state.bookings.length; index += 1) {
        if (state.bookings[index].booking_id === bookingId) {
          state.bookings[index] = extend(extend({}, state.bookings[index]), payload.booking || {});
          state.bookings[index].preview = (payload.booking && payload.booking.message) || state.bookings[index].preview;
        }
      }

      setCounts();
      applyFilter();
      renderDetail();

      if (state.tg && state.tg.HapticFeedback && state.tg.HapticFeedback.notificationOccurred) {
        state.tg.HapticFeedback.notificationOccurred('success');
      }
    }, function (message) {
      if (state.tg && state.tg.showAlert) {
        state.tg.showAlert(message || 'Не вдалося змінити статус.');
      }
      renderDetail();
    });
  }

  function bindUi() {
    els.searchInput.addEventListener('input', function (event) {
      state.query = event.target.value || '';
      applyFilter();
    });

    els.clearSearchButton.addEventListener('click', function () {
      state.query = '';
      els.searchInput.value = '';
      applyFilter();
    });

    els.refreshButton.addEventListener('click', function () {
      bootstrap();
    });

    window.addEventListener('resize', function () {
      syncBackButton();
    });
  }

  function initTelegram() {
    var tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    if (!tg) {
      renderError('Цю сторінку потрібно відкривати з кнопки в Telegram-боті.');
      setViewerText('Telegram WebApp API не знайдено.');
      return false;
    }

    state.tg = tg;
    state.initData = tg.initData || '';
    state.accessToken = readQueryParam('access_token');
    if (!state.initData && !state.accessToken) {
      renderError('Telegram не передав auth initData. Відкрийте Mini App через кнопку в боті ще раз.');
      setViewerText('Немає Telegram auth initData.');
      return false;
    }
    if (!state.initData && state.accessToken) {
      setViewerText('Підключення через резервний токен Telegram...');
    }

    tg.ready();
    tg.expand();
    if (tg.setHeaderColor) {
      tg.setHeaderColor('#0c5f78');
    }
    if (tg.setBackgroundColor) {
      tg.setBackgroundColor('#f5f0e7');
    }

    return true;
  }

  bindUi();
  state.activeBookingId = readBookingIdFromUrl();

  if (initTelegram()) {
    bootstrap();
  }
})();
