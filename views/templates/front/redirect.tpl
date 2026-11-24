{**
 * CMI payment module for PrestaShop.
 *
 * Sends the customer to CMI's payment page with the signed form.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {l s='Redirecting to CMI' d='Modules.Cmi.Shop'}
{/block}

{block name='page_content'}
  <section id="cmi-redirect" class="card card-block">
    <p>{l s='Thank you for your order %reference%. You are being redirected to CMI\'s secure payment page.' sprintf=['%reference%' => $cmi_reference] d='Modules.Cmi.Shop'}</p>
    <form id="cmi-payment-form" action="{$cmi_action|escape:'html':'UTF-8'}" method="post" target="_top" data-signed="{$cmi_signed|escape:'html':'UTF-8'}">
      {foreach from=$cmi_fields key=name item=value}
        <input type="hidden" name="{$name|escape:'html':'UTF-8'}" value="{$value|escape:'html':'UTF-8'}">
      {/foreach}
      <noscript>
        <p>{l s='Click the button to continue to the payment page.' d='Modules.Cmi.Shop'}</p>
      </noscript>
      <button type="submit" class="btn btn-primary" id="cmi-payment-submit">{l s='Pay with CMI' d='Modules.Cmi.Shop'}</button>
      <a class="btn btn-link" href="{$cmi_cancel_url|escape:'html':'UTF-8'}">{l s='Cancel the order' d='Modules.Cmi.Shop'}</a>
    </form>
  </section>
{/block}
