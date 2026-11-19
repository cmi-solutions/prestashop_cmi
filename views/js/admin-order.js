/**
 * CMI payment module for PrestaShop: actions of the order page's CMI panel.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function () {
  'use strict';

  function lock(form) {
    Array.prototype.forEach.call(form.querySelectorAll('button'), function (button) {
      button.disabled = true;
    });
  }

  function parseAmount(value) {
    var clean = String(value || '').replace(/\s/g, '').replace(',', '.');
    return /^\d+(\.\d{1,2})?$/.test(clean) ? parseFloat(clean) : NaN;
  }

  function init() {
    var panel = document.getElementById('cmi-payment-panel');
    if (!panel) {
      return;
    }

    // Void: simple question.
    Array.prototype.forEach.call(panel.querySelectorAll('.cmi-ask-form'), function (form) {
      form.addEventListener('submit', function (event) {
        if (!window.confirm(form.getAttribute('data-question'))) {
          event.preventDefault();
          return;
        }
        lock(form);
      });
    });

    // Confirm: warn first when CMI reports an old pre-authorization.
    Array.prototype.forEach.call(panel.querySelectorAll('.cmi-confirm-form'), function (form) {
      var asked = false;
      form.addEventListener('submit', function (event) {
        if (asked) {
          return;
        }
        event.preventDefault();
        var ask = function (days) {
          var question = days > 0
            ? form.getAttribute('data-old-question').replace('%days%', String(days))
            : form.getAttribute('data-question');
          if (window.confirm(question)) {
            asked = true;
            lock(form);
            form.submit();
          }
        };
        if (!window.fetch) {
          ask(0);
          return;
        }
        window.fetch(form.getAttribute('data-age-url'), { credentials: 'same-origin' })
          .then(function (response) { return response.ok ? response.json() : { days: 0 }; })
          .then(function (data) { ask(parseInt(data && data.days, 10) || 0); })
          .catch(function () { ask(0); });
      });
    });

    // Refund: full or partial, amount checked before asking.
    Array.prototype.forEach.call(panel.querySelectorAll('.cmi-refund-form'), function (form) {
      var amountField = form.querySelector('[name="refund_amount"]');
      Array.prototype.forEach.call(form.querySelectorAll('[name="refund_type"]'), function (radio) {
        radio.addEventListener('change', function () {
          amountField.disabled = form.querySelector('[name="refund_type"]:checked').value !== 'partial';
          if (!amountField.disabled) {
            amountField.focus();
          }
        });
      });
      form.addEventListener('submit', function (event) {
        var max = parseFloat(form.getAttribute('data-max'));
        var partial = form.querySelector('[name="refund_type"]:checked').value === 'partial';
        var amount = partial ? parseAmount(amountField.value) : max;
        if (partial && (!(amount > 0) || amount > max + 0.001)) {
          event.preventDefault();
          window.alert(form.getAttribute('data-invalid'));
          return;
        }
        if (!window.confirm(form.getAttribute('data-question').replace('%amount%', amount.toFixed(2)))) {
          event.preventDefault();
          return;
        }
        lock(form);
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
