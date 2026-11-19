{**
 * CMI payment module for PrestaShop.
 *
 * Shown under the payment option when the customer chooses CMI.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
<section class="cmi-payment-option">
  {if $cmi_description}
    <p>{$cmi_description|escape:'html':'UTF-8'|nl2br nofilter}</p>
  {/if}
  <p>{l s='You will be redirected to the secure payment page of CMI to enter your card details.' d='Modules.Cmi.Shop'}</p>
  {if $cmi_converted}
    <p><small>{l s='Amount charged by CMI: %amount%.' sprintf=['%amount%' => $cmi_charged] d='Modules.Cmi.Shop'}</small></p>
  {/if}
</section>
