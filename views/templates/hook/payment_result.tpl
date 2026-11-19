{**
 * CMI payment module for PrestaShop.
 *
 * Payment result on the order confirmation page and in the customer's order details.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
<section class="cmi-payment-result box">
  <h3>{l s='Card payment (CMI)' d='Modules.Cmi.Shop'}</h3>
  <p><strong>{$cmi_label|escape:'html':'UTF-8'}</strong> &mdash; {$cmi_message|escape:'html':'UTF-8'}</p>
  {if $cmi_retry_url}
    <p><a class="btn btn-primary" href="{$cmi_retry_url|escape:'html':'UTF-8'}">{l s='Pay now by card' d='Modules.Cmi.Shop'}</a></p>
  {/if}
</section>
