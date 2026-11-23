/**
 * CMI payment module for PrestaShop: actions of the order page's CMI panel, and of the CMI
 * column of the orders list (Complete Kit).
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

  // ── Orders list: Confirm, Void, Refund and Check CMI in the CMI column (Complete Kit) ──

  function post(url, fields) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = url;
    form.style.display = 'none';
    Object.keys(fields).forEach(function (name) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = fields[name];
      form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
  }

  function fill(text, values) {
    return Object.keys(values).reduce(function (result, key) {
      return result.split('%' + key + '%').join(values[key]);
    }, text);
  }

  function showFlash(table, flash) {
    var box = document.createElement('div');
    box.className = 'cmi-flash cmi-flash-' + (flash.type === 'success' ? 'success' : 'danger');
    box.setAttribute('role', 'status');
    box.textContent = flash.message;
    table.parentNode.insertBefore(box, table);
  }

  function initList(config) {
    var table = document.getElementById('order_grid_table');
    if (!table || !window.fetch) {
      return;
    }
    var rows = {};
    Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (row) {
      var id = row.querySelector('[data-identifier]');
      var cell = row.querySelector('td.column-cmi_payment');
      if (id && cell) {
        rows[id.getAttribute('data-identifier')] = cell;
      }
    });
    var ids = Object.keys(rows);
    if (!ids.length) {
      return;
    }
    var texts = config.texts || {};
    var send = function (entry, action, extra) {
      post(config.url, Object.assign({ cmi_action: action, id_cmi_transaction: entry.transaction_id, back: 'list' }, extra || {}));
    };
    var button = function (label, style, onClick) {
      var element = document.createElement('button');
      element.type = 'button';
      element.className = 'btn btn-sm ' + style + ' cmi-list-action';
      element.textContent = label;
      element.addEventListener('click', function (event) {
        // The row itself opens the order: keep the click on the button.
        event.preventDefault();
        event.stopPropagation();
        onClick(element);
      });
      return element;
    };
    var actions = {
      confirm: function (entry) {
        return button(texts.confirm, 'btn-success', function (element) {
          element.disabled = true;
          window.fetch(config.url + '&ajax=1&action=preauthAge&id_cmi_transaction=' + entry.transaction_id, { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : { days: 0 }; })
            .catch(function () { return { days: 0 }; })
            .then(function (data) {
              var days = parseInt(data && data.days, 10) || 0;
              var question = days > 0
                ? fill(texts.confirm_old, { days: String(days) })
                : fill(texts.confirm_question, { amount: entry.charged, currency: entry.currency });
              if (window.confirm(question)) {
                send(entry, 'confirm');
              } else {
                element.disabled = false;
              }
            });
        });
      },
      void: function (entry) {
        return button(texts.void, 'btn-outline-danger', function () {
          if (window.confirm(texts.void_question)) {
            send(entry, 'void');
          }
        });
      },
      refund: function (entry) {
        return button(texts.refund, 'btn-outline-warning', function () {
          if (window.confirm(fill(texts.refund_question, { amount: entry.remaining, currency: entry.currency }) + '\n\n' + texts.partial)) {
            send(entry, 'refund', { refund_type: 'full' });
          }
        });
      },
      check: function (entry) {
        return button(texts.check, 'btn-outline-secondary', function () {
          send(entry, 'check');
        });
      }
    };
    window.fetch(config.url + '&ajax=1&action=listActions&ids=' + encodeURIComponent(ids.join(',')), { credentials: 'same-origin' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data) {
          return;
        }
        if (data.flash && data.flash.message) {
          showFlash(table, data.flash);
        }
        Object.keys(data.orders || {}).forEach(function (id) {
          var entry = data.orders[id];
          var cell = rows[id];
          if (!cell) {
            return;
          }
          var box = document.createElement('div');
          box.className = 'cmi-list-actions';
          ['confirm', 'void', 'refund', 'check'].forEach(function (name) {
            if (entry['can_' + name]) {
              box.appendChild(actions[name](entry));
            }
          });
          if (box.childNodes.length) {
            cell.appendChild(box);
          }
        });
      })
      .catch(function () {});
  }

  function init() {
    var panel = document.getElementById('cmi-payment-panel');
    if (!panel) {
      if (window.cmiOrderList) {
        initList(window.cmiOrderList);
      }
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
