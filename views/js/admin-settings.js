/**
 * CMI payment module for PrestaShop: configuration page.
 * Show/hide button on the secret fields, API fields shown only for the Complete Kit, and the
 * CMI platform (test / production) filling in its addresses.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function () {
  'use strict';

  // Set by the module with Media::addJsDef.
  var config = window.cmiSettings || {};
  var texts = config.texts || {};

  function addRevealButton(input) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-default cmi-reveal';
    button.setAttribute('aria-label', texts.reveal || 'Show / hide');
    button.textContent = '👁';
    button.addEventListener('click', function () {
      input.type = input.type === 'password' ? 'text' : 'password';
    });
    input.parentNode.insertBefore(button, input.nextSibling);
  }

  function rowOf(name) {
    var field = document.querySelector('[name="' + name + '"]');
    return field ? field.closest('.form-group') : null;
  }

  function init() {
    Array.prototype.forEach.call(document.querySelectorAll('input.cmi-secret'), function (input) {
      input.setAttribute('autocomplete', 'new-password');
      addRevealButton(input);
    });

    var kit = document.querySelector('[name="CMI_KIT"]');
    var apiRows = ['CMI_API_URL', 'CMI_API_USERNAME', 'CMI_API_PASSWORD'].map(rowOf).filter(Boolean);
    var toggle = function () {
      var complete = kit && kit.value === 'complete';
      apiRows.forEach(function (row) {
        row.style.opacity = complete ? '1' : '0.5';
      });
    };
    if (kit) {
      kit.addEventListener('change', toggle);
      toggle();
    }

    // CMI platform: switching it replaces the addresses of the other platform (an address
    // CMI gave you for something else is kept).
    var environment = document.querySelector('[name="CMI_ENVIRONMENT"]');
    var platforms = config.platforms || {};
    if (environment) {
      var previous = environment.value;
      environment.addEventListener('change', function () {
        var from = platforms[previous] || {};
        var to = platforms[environment.value] || {};
        [['CMI_GATEWAY_URL', 'gateway'], ['CMI_API_URL', 'api']].forEach(function (pair) {
          var field = document.querySelector('[name="' + pair[0] + '"]');
          if (field && to[pair[1]] && (field.value === '' || field.value === from[pair[1]])) {
            field.value = to[pair[1]];
          }
        });
        previous = environment.value;
      });
    }

    Array.prototype.forEach.call(document.querySelectorAll('input.cmi-select-all'), function (input) {
      input.addEventListener('click', function () {
        input.select();
      });
    });

    // "Order status after capture": the name and color fields only for "Custom".
    var captured = document.querySelector('[name="CMI_CAPTURED_STATE"]');
    var customRows = ['CMI_CUSTOM_STATE_NAME', 'CMI_CUSTOM_STATE_COLOR'].map(rowOf).filter(Boolean);
    var toggleCustom = function () {
      var custom = captured && captured.value === 'custom';
      customRows.forEach(function (row) {
        row.style.display = custom ? '' : 'none';
      });
    };
    if (captured) {
      captured.addEventListener('change', toggleCustom);
      toggleCustom();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
