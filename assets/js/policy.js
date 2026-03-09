(function () {
  'use strict';

  const policy = window.SVH_SITE_POLICY || {
    checkIn: '14:00',
    checkOut: '11:00',
    prepayment: '30%'
  };

  window.SVH_SITE_POLICY = policy;

  function applyPolicyText() {
    const checkinText = `Заселення з ${policy.checkIn}, виселення до ${policy.checkOut}. За домовленістю можливе раннє заселення або пізнє виселення.`;
    const prepaymentFaqText = `Приймаємо готівку, картки (Visa/Mastercard), а також переказ на картку. При бронюванні потрібна передоплата ${policy.prepayment}.`;
    const prepaymentBookingText = `Для підтвердження бронювання необхідно внести передоплату ${policy.prepayment}. Решту суми оплачуєте при заселенні.`;

    document.querySelectorAll('[data-policy-checkin-text]').forEach((node) => {
      node.textContent = checkinText;
    });

    document.querySelectorAll('[data-policy-prepayment-text]').forEach((node) => {
      if (node.closest('.faq-item')) {
        node.textContent = prepaymentFaqText;
      } else {
        node.textContent = prepaymentBookingText;
      }
    });

    document.querySelectorAll('[data-policy-checkin-time]').forEach((node) => {
      node.textContent = policy.checkIn;
    });
    document.querySelectorAll('[data-policy-checkout-time]').forEach((node) => {
      node.textContent = policy.checkOut;
    });
    document.querySelectorAll('[data-policy-prepayment-value]').forEach((node) => {
      node.textContent = policy.prepayment;
    });
  }

  function applyPayload(payload) {
    if (!payload || typeof payload !== 'object') return;
    if (typeof payload.checkin === 'string' && payload.checkin.trim() !== '') {
      policy.checkIn = payload.checkin.trim();
    }
    if (typeof payload.checkout === 'string' && payload.checkout.trim() !== '') {
      policy.checkOut = payload.checkout.trim();
    }
    if (typeof payload.prepayment === 'string' && payload.prepayment.trim() !== '') {
      policy.prepayment = payload.prepayment.trim();
    }
  }

  async function loadPolicyFromApi() {
    const response = await fetch('/api/policy.php', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json'
      }
    });

    if (!response.ok) {
      throw new Error(`Policy API HTTP ${response.status}`);
    }

    const data = await response.json();
    const payload = data && typeof data === 'object' ? (data.policy || data) : null;
    applyPayload(payload);
    applyPolicyText();
  }

  function initPolicy() {
    applyPolicyText();
    loadPolicyFromApi().catch(() => {
      // Keep fallback policy values if API is temporarily unavailable.
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPolicy, { once: true });
  } else {
    initPolicy();
  }
})();

