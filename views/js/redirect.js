/**
 * CMI payment module for PrestaShop: sends the signed payment form to CMI's page.
 *
 * CMI checks the signature against every field it receives. Some modules add hidden fields to
 * every form (anti-spam, translation...): remove each field this module did not sign, then send
 * the form without a "submit" event, so nothing is added back.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function () {
  'use strict';

  function init() {
    var form = document.getElementById('cmi-payment-form');
    if (!form) {
      return;
    }
    var signed = (form.getAttribute('data-signed') || '').split(',');
    var send = function () {
      Array.prototype.slice.call(form.elements).forEach(function (field) {
        if (field.name && signed.indexOf(field.name) === -1) {
          field.parentNode.removeChild(field);
        }
      });
      HTMLFormElement.prototype.submit.call(form);
    };
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      setTimeout(send, 0);
    });
    send();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
