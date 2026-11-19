{**
 * CMI payment module for PrestaShop.
 *
 * Payment not completed (declined, cancelled, or no result yet).
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {$cmi_label|escape:'html':'UTF-8'}
{/block}

{block name='page_content'}
  <section id="cmi-result" class="card card-block">
    <p>{l s='Order reference: %reference%' sprintf=['%reference%' => $cmi_reference] d='Modules.Cmi.Shop'}</p>
    <p class="alert {if $cmi_state == 'failed' || $cmi_state == 'client_cancelled'}alert-warning{else}alert-info{/if}">{$cmi_message|escape:'html':'UTF-8'}</p>
    {if $cmi_retry_url}
      <p><a class="btn btn-primary" href="{$cmi_retry_url|escape:'html':'UTF-8'}">{l s='Pay again by card' d='Modules.Cmi.Shop'}</a></p>
    {/if}
    <p>
      <a href="{$cmi_history_url|escape:'html':'UTF-8'}">{l s='See my orders' d='Modules.Cmi.Shop'}</a>
      &middot;
      <a href="{$cmi_contact_url|escape:'html':'UTF-8'}">{l s='Contact the shop' d='Modules.Cmi.Shop'}</a>
    </p>
  </section>
{/block}
