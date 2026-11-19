{**
 * CMI payment module for PrestaShop.
 *
 * "CMI payment" panel of the back-office order page.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
<div class="card mt-2" id="cmi-payment-panel">
  <div class="card-header">
    <h3 class="card-header-title">
      {l s='CMI payment' d='Modules.Cmi.Admin'}
      <span class="badge badge-{$cmi.state_class|escape:'html':'UTF-8'} ml-2">{$cmi.state_label|escape:'html':'UTF-8'}</span>
    </h3>
  </div>
  <div class="card-body">
    {if $cmi.flash}
      {* Not an .alert: PrestaShop's order page script would fold it behind "Read More". *}
      <div class="cmi-flash cmi-flash-{$cmi.flash.type|escape:'html':'UTF-8'}" role="status">{$cmi.flash.message|escape:'html':'UTF-8'}</div>
    {/if}

    <table class="table cmi-payment-details">
      <tbody>
        <tr>
          <th>{l s='CMI OrderId' d='Modules.Cmi.Admin'}</th>
          <td><code>{$cmi.oid|escape:'html':'UTF-8'}</code>{if $cmi.legacy} <span class="text-muted">({l s='paid with version 1.x' d='Modules.Cmi.Admin'})</span>{/if}</td>
        </tr>
        <tr>
          <th>{l s='Charged by CMI' d='Modules.Cmi.Admin'}</th>
          <td>
            {if $cmi.amount_known}
              <strong>{$cmi.charged|escape:'html':'UTF-8'} {$cmi.currency|escape:'html':'UTF-8'}</strong>
              {if $cmi.converted}
                <br><small class="text-muted">{l s='Order: %amount% %currency%, 1 %currency% = %rate% %charged_currency%' sprintf=['%amount%' => $cmi.amount_order, '%currency%' => $cmi.order_currency, '%rate%' => $cmi.rate, '%charged_currency%' => $cmi.currency] d='Modules.Cmi.Admin'}</small>
              {/if}
            {else}
              <span class="text-muted">{l s='Not recorded (order paid in another currency with version 1.x)' d='Modules.Cmi.Admin'}</span>
            {/if}
          </td>
        </tr>
        {if $cmi.amount_known && $cmi.refunded > 0}
          <tr>
            <th>{l s='Refunded on CMI' d='Modules.Cmi.Admin'}</th>
            <td>{$cmi.refunded|escape:'html':'UTF-8'} {$cmi.currency|escape:'html':'UTF-8'} &middot; {l s='remaining: %amount%' sprintf=['%amount%' => $cmi.remaining] d='Modules.Cmi.Admin'} {$cmi.currency|escape:'html':'UTF-8'}</td>
          </tr>
        {/if}
        <tr>
          <th>{l s='CMI store' d='Modules.Cmi.Admin'}</th>
          <td>{$cmi.client_id|escape:'html':'UTF-8'} ({if $cmi.account == 'offshore'}{l s='offshore' d='Modules.Cmi.Admin'}{else}{l s='standard' d='Modules.Cmi.Admin'}{/if})</td>
        </tr>
        {if isset($cmi.last.TransId)}
          <tr>
            <th>{l s='Last CMI transaction' d='Modules.Cmi.Admin'}</th>
            <td>
              {$cmi.last.TransId|escape:'html':'UTF-8'}
              {if isset($cmi.last.AuthCode)} &middot; {l s='authorization %code%' sprintf=['%code%' => $cmi.last.AuthCode] d='Modules.Cmi.Admin'}{/if}
              {if isset($cmi.last.MaskedPan)} &middot; {$cmi.last.MaskedPan|escape:'html':'UTF-8'}{/if}
              {if isset($cmi.last.EXTRA_CARDBRAND)} ({$cmi.last.EXTRA_CARDBRAND|escape:'html':'UTF-8'}){/if}
            </td>
          </tr>
        {/if}
        {if $cmi.next_check}
          <tr>
            <th>{l s='Next automatic check' d='Modules.Cmi.Admin'}</th>
            <td>{$cmi.next_check|escape:'html':'UTF-8'}</td>
          </tr>
        {/if}
      </tbody>
    </table>

    {if $cmi.api_enabled}
      <div class="cmi-actions">
        {if $cmi.can_confirm}
          <form method="post" action="{$cmi.action_url|escape:'html':'UTF-8'}" class="d-inline-block cmi-confirm-form"
                data-age-url="{$cmi.action_url|escape:'html':'UTF-8'}&amp;ajax=1&amp;action=preauthAge&amp;id_cmi_transaction={$cmi.transaction_id|intval}"
                data-question="{$cmi.texts.confirm|escape:'html':'UTF-8'}"
                data-old-question="{$cmi.texts.confirm_old|escape:'html':'UTF-8'}">
            <input type="hidden" name="cmi_action" value="confirm">
            <input type="hidden" name="id_cmi_transaction" value="{$cmi.transaction_id|intval}">
            <button type="submit" class="btn btn-success">{l s='Confirm' d='Modules.Cmi.Admin'}</button>
          </form>
          <form method="post" action="{$cmi.action_url|escape:'html':'UTF-8'}" class="d-inline-block cmi-ask-form"
                data-question="{$cmi.texts.void|escape:'html':'UTF-8'}">
            <input type="hidden" name="cmi_action" value="void">
            <input type="hidden" name="id_cmi_transaction" value="{$cmi.transaction_id|intval}">
            <button type="submit" class="btn btn-danger">{l s='Void' d='Modules.Cmi.Admin'}</button>
          </form>
        {/if}
        <form method="post" action="{$cmi.action_url|escape:'html':'UTF-8'}" class="d-inline-block">
          <input type="hidden" name="cmi_action" value="check">
          <input type="hidden" name="id_cmi_transaction" value="{$cmi.transaction_id|intval}">
          <button type="submit" class="btn btn-outline-secondary" title="{l s='Read the payment\'s state at CMI and update the order' d='Modules.Cmi.Admin'}">{l s='Check CMI' d='Modules.Cmi.Admin'}</button>
        </form>

        {if $cmi.can_refund}
          <form method="post" action="{$cmi.action_url|escape:'html':'UTF-8'}" class="cmi-refund-form mt-3"
                data-question="{$cmi.texts.refund|escape:'html':'UTF-8'}"
                data-invalid="{$cmi.texts.refund_invalid|escape:'html':'UTF-8'}"
                data-currency="{$cmi.currency|escape:'html':'UTF-8'}" data-max="{$cmi.remaining|escape:'html':'UTF-8'}">
            <input type="hidden" name="cmi_action" value="refund">
            <input type="hidden" name="id_cmi_transaction" value="{$cmi.transaction_id|intval}">
            <h4>{l s='Refund' d='Modules.Cmi.Admin'}</h4>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="refund_type" id="cmi-refund-full" value="full" checked>
              <label class="form-check-label" for="cmi-refund-full">{l s='Full: %amount% %currency%' sprintf=['%amount%' => $cmi.remaining, '%currency%' => $cmi.currency] d='Modules.Cmi.Admin'}</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="refund_type" id="cmi-refund-partial" value="partial">
              <label class="form-check-label" for="cmi-refund-partial">{l s='Partial' d='Modules.Cmi.Admin'}</label>
            </div>
            <div class="form-row mt-2">
              <div class="col-sm-4">
                <div class="input-group">
                  <input type="text" inputmode="decimal" class="form-control" name="refund_amount" placeholder="0.00" disabled aria-label="{l s='Amount' d='Modules.Cmi.Admin'}">
                  <div class="input-group-append"><span class="input-group-text">{$cmi.currency|escape:'html':'UTF-8'}</span></div>
                </div>
              </div>
              <div class="col-sm-8">
                <input type="text" class="form-control" name="refund_reason" maxlength="255" placeholder="{l s='Reason (optional)' d='Modules.Cmi.Admin'}">
              </div>
            </div>
            <p class="small text-muted mt-2">{l s='The refund is sent to CMI in the currency it charged. No credit slip is created in PrestaShop; create one if you need it for your accounting (without refunding again).' d='Modules.Cmi.Admin'}</p>
            <button type="submit" class="btn btn-warning">{l s='Refund via CMI' d='Modules.Cmi.Admin'}</button>
          </form>
        {/if}
      </div>
    {else}
      <p class="text-muted">{l s='Standard Kit: confirm, void and refund this payment in the CMI back office. With the Complete Kit (API credentials in the module settings) you can do it here.' d='Modules.Cmi.Admin'}</p>
    {/if}
    {if $cmi.refund_in_back_office}
      <p class="text-muted">{l s='The amount charged by CMI was not recorded for this older order: refund it from the CMI back office.' d='Modules.Cmi.Admin'}</p>
    {/if}

    {if $cmi.logs}
      <details class="mt-3 cmi-history">
        <summary>{l s='CMI history' d='Modules.Cmi.Admin'} ({$cmi.log_count|intval})</summary>
        <ul class="list-unstyled mt-2">
          {foreach from=$cmi.logs item=log}
            <li class="cmi-log-{$log.level|escape:'html':'UTF-8'}"><span class="text-muted">{$log.date_add|escape:'html':'UTF-8'}</span> &mdash; {$log.message|escape:'html':'UTF-8'}</li>
          {/foreach}
        </ul>
      </details>
    {/if}
  </div>
</div>
