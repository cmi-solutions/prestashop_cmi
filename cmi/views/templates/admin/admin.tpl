<form action="{$cmi_form|escape:'htmlall':'UTF-8'}" method="post" style="margin-bottom:25px;">
   
    <div role="tabpanel">
        <div class="tab-content">
            <div id="cmi_step1" class="tab-pane active" role="tabpanel">
                <div class="panel">	
					{if isset($postErrors) && $postErrors}
					<div class="alert alert-danger" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true"><i class="material-icons">close</i></span>
						</button>
						<div class="alert-text">
							{foreach $postErrors as $postError}
								<p>{$postError}</p>
							{/foreach }
						</div>
					</div>
					{/if}
					{if isset($successMsg) && $successMsg}
					<div class="module_confirmation conf confirm alert alert-success">
						<button type="button" class="close" data-dismiss="alert">×</button>
						{$successMsg}
					</div>
					{/if}
                    <h4>Configurer la solution de paiement Cmi dans votre PrestaShop </h4>
				   <div class="form-group">
                        <div><label style='text-align:left'for='currency_store'>Devises de store CMI </label> <sup>*</sup></div>
                        <select name="currency_store[]" style="width:130px;" id="currency_store" multiple required>
								<option value="504"{if isset($currencies_store["504"]) && $currencies_store["504"]}{if $currencies_store["504"] == "504"} selected="selected"{/if}{/if}>MAD</option>
								<option value="978"{if isset($currencies_store["978"]) && $currencies_store["978"]}{if $currencies_store["978"] == "978"} selected="selected"{/if}{/if}>EUR</option>
                                <option value="840"{if isset($currencies_store["840"]) && $currencies_store["840"]}{if $currencies_store["840"] == "840"} selected="selected"{/if}{/if}>USD</option>
                                <option value="826"{if isset($currencies_store["826"]) && $currencies_store["826"]}{if $currencies_store["826"] == "826"} selected="selected"{/if}{/if}>GBP</option>
                                <option value="784"{if isset($currencies_store["784"]) && $currencies_store["784"]}{if $currencies_store["784"] == "784"} selected="selected"{/if}{/if}>AED</option>
                                <option value="756"{if isset($currencies_store["756"]) && $currencies_store["756"]}{if $currencies_store["756"] == "756"} selected="selected"{/if}{/if}>CHF</option>
                                <option value="682"{if isset($currencies_store["682"]) && $currencies_store["682"]}{if $currencies_store["682"] == "682"} selected="selected"{/if}{/if}>SAR</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:none">
                        <div><label style='text-align:left'for='merchid'>{l s='MAD merchant id' mod='cmi'}</label> <sup>*</sup></div>
                        <input size="10" type="text" name="merchid" value="{$merchid}" class="inputBp" id="merchid"required/>
                    </div>
                    <div class="form-group" style="display:none">
                        <div><label style='text-align:left'for='merchid_offshore'>{l s='Offshore merchant id' mod='cmi'}</label> <sup>*</sup></div>
                        <input size="10" type="text" name="merchid_offshore" value="{$merchid_offshore}" class="inputBp" id="merchid_offshore"required/>
                    </div>
				   <div class="form-group">
                        <div><label style='text-align:left'for='actionslk'>l'URL de la Gateway de paiement </label> <sup>*</sup></div>
                        <input size="100" type="text" name="actionslk" value="{$actionslk}" class="inputBp" id="actionslk"required/>
                    </div>
					<div class="form-group">
                        <div><label style='text-align:left'for='secretkey'>Clé de hachage</label> <sup>*</sup></div>
                        <input size="10" type="password" name="secretkey" value="{$secretkey}" class="inputBp" id="secretkey"required/>
						<span toggle="#secretkey" class="fa fa-fw fa-eye-slash field-icon toggle-password"></span>
                    </div>
					<div class="form-group">
                        <div><label style='text-align:left'for='callback_mode'>Callback</label></div>
                        <select name="callback_mode" style="width:130px;" id="callback_mode">
                                <option value="0"{if $callback_mode == 0} selected="selected"{/if}>Server-to-server</option>
                                <option value="1"{if $callback_mode == 1} selected="selected"{/if}>Redirection automatique</option>
                                <option value="2"{if $callback_mode == 2} selected="selected"{/if}>Hybride</option>
                        </select>
                    </div> 
				   <div class="form-group">
                        <div><label style='text-align:left'for='actionslkapi'>l'URL de la Gateway de paiement API </label> <sup>*</sup></div>
                        <input size="100" type="text" name="actionslkapi" value="{$actionslkapi}" class="inputBp" id="actionslkapi"/>
                    </div>
					<div class="form-group">
                        <div><label style='text-align:left'for='usernameapi'>Nom d'utilisateur API</label> <sup>*</sup></div>
                        <input size="10" type="text" name="usernameapi" value="{$usernameapi}" class="inputBp" id="usernameapi"/>
                    </div>
					<div class="form-group">
                        <div><label style='text-align:left'for='passwordapi'>Mot de passe API</label> <sup>*</sup></div>
                        <input size="10" type="password" name="passwordapi" value="{$passwordapi}" class="inputBp" id="passwordapi"/>
						<span toggle="#passwordapi" class="fa fa-fw fa-eye-slash field-icon toggle-password"></span>
                    </div>
					
                   <div class="form-group">
                        <div><label style='text-align:left'for='cardholder_authentication'>Authentification du titulaire de la carte</label> <sup>*</sup></div>
                        <select name="cardholder_authentication" style="width:130px;" id="cardholder_authentication">
                                <option value="1"{if $cardholder_authentication == 1} selected="selected"{/if}>OUI</option>
                                <option value="2"{if $cardholder_authentication == 2} selected="selected"{/if}>NON</option>
                        </select>
                    </div>
					
                   <div class="form-group">
                        <div><label style='text-align:left'for='confirmation_mode'>Mode de confirmation des Transaction CMI</label> <sup>*</sup></div>
                        <select name="confirmation_mode" style="width:130px;" id="confirmation_mode">
                                <option value="1"{if $confirmation_mode == 1} selected="selected"{/if}>Automatique</option>
                                <option value="2"{if $confirmation_mode == 2} selected="selected"{/if}>Manuelle</option>
                        </select>
                    </div>
                   <div class="form-group">
                        <div><label style='text-align:left'for='redirection_mode'>Mode de redirection vers la page de confirmation de commande</label> <sup>*</sup></div>
                        <select name="redirection_mode" style="width:130px;" id="redirection_mode">>
                                <option value="0"{if $redirection_mode == 2} selected="selected"{/if}>Manuelle</option>
                                <option value="1"{if $redirection_mode == 1} selected="selected"{/if}>Automatique</option
                        </select>
                    </div>
                   <hr>

                    <input class="btn btn-primary" name="submitCmi_config" value="{l s='Save' mod='cmi'}" type="submit" />
                </div>
            </div>
        </div>
    </div>
</form>
<p style="font-size:0.8em">
    {l s='This module has been developped by ' mod='cmi'}<a href="https://www.cmi.co.ma/">Cmi</a>
</p>
<script type="text/javascript">
jQuery( document ).ready(function() {
	jQuery( "#actionslkapi" ).parent().hide();
	jQuery( "#usernameapi" ).parent().hide();
	jQuery( "#passwordapi" ).parent().hide();
		  jQuery("#actionslkapi").removeAttr("required");
		  jQuery("#usernameapi").removeAttr("required");
		  jQuery("#passwordapi").removeAttr("required");
	if( jQuery("select#callback_mode").val() == "0"){
		  jQuery( "#actionslkapi" ).parent().hide();
		  jQuery( "#usernameapi" ).parent().hide();
			jQuery( "#passwordapi" ).parent().hide();
		  jQuery("#actionslkapi").removeAttr("required");
		  jQuery("#usernameapi").removeAttr("required");
		  jQuery("#passwordapi").removeAttr("required");
	  } else {
		  jQuery("#actionslkapi").prop("required",true);
		  jQuery("#usernameapi").prop("required",true);
		  jQuery("#passwordapi").prop("required",true);
		  jQuery( "#actionslkapi" ).parent().show();
		  jQuery( "#usernameapi" ).parent().show();
			jQuery( "#passwordapi" ).parent().show();
	  }
	jQuery("select#callback_mode").on("change", function() {
	  if( this.value == "0"){
		  jQuery("#actionslkapi").removeAttr("required");
		  jQuery("#usernameapi").removeAttr("required");
		  jQuery("#passwordapi").removeAttr("required");
		  jQuery( "#actionslkapi" ).parent().hide();
		  jQuery( "#usernameapi" ).parent().hide();
			jQuery( "#passwordapi" ).parent().hide();
	  } else {
		  jQuery("#actionslkapi").prop("required",true);
		  jQuery("#usernameapi").prop("required",true);
		  jQuery("#passwordapi").prop("required",true);
		  jQuery( "#actionslkapi" ).parent().show();
		  jQuery( "#usernameapi" ).parent().show();
			jQuery( "#passwordapi" ).parent().show();
	  }
	 });
	 jQuery(".toggle-password").click(function() {
		jQuery(this).toggleClass("fa-eye-slash fa-eye");
		var input = jQuery(jQuery(this).attr("toggle"));
		if (input.attr("type") == "password") {
			input.attr("type", "text");
		} else {
			input.attr("type", "password");
		}
	});
		var currencies = [];
	jQuery("select#currency_store option:selected").each(function() {
		currencies.push(jQuery(this).val());
		
		
	});
		if(jQuery.inArray("504", currencies) > -1  && (currencies.length > 1)){
			jQuery( "#merchid_offshore" ).parent().show();
			jQuery( "#merchid" ).parent().show(); 
			jQuery( "#merchid_offshore" ).prop("required",true);
			jQuery( "#merchid" ).prop("required",true);
		} 
	if((currencies.length == 1)) {
		if(jQuery("select#currency_store option[value='504']").is(':selected')) {
			jQuery( "#merchid_offshore" ).parent().hide();
			jQuery( "#merchid_offshore" ).removeAttr("required");
			jQuery( "#merchid" ).parent().show();
			jQuery( "#merchid" ).prop("required",true);
		} else {
			jQuery( "#merchid_offshore" ).parent().show();
			jQuery( "#merchid_offshore" ).prop("required",true);
			jQuery( "#merchid" ).parent().hide();
			jQuery( "#merchid" ).removeAttr("required");
		}
	} 
	jQuery("select#currency_store").on("change", function() {
	if(jQuery("select#currency_store option[value='504']").is(':selected')){
			jQuery( "#merchid_offshore" ).parent().hide();
			jQuery( "#merchid_offshore" ).removeAttr("required");
			jQuery( "#merchid" ).parent().show();
			jQuery( "#merchid" ).prop("required",true);
	} else {
			jQuery( "#merchid_offshore" ).parent().show();
			jQuery( "#merchid_offshore" ).prop("required",true);
			jQuery( "#merchid" ).parent().hide();
			jQuery( "#merchid" ).removeAttr("required");
	}
	var strCurrs = jQuery(this).val();
	if((strCurrs.indexOf('504') > -1) && (strCurrs.length > 1)){
			jQuery( "#merchid_offshore" ).parent().show();
			jQuery( "#merchid" ).parent().show(); 
			jQuery( "#merchid_offshore" ).prop("required",true);
			jQuery( "#merchid" ).prop("required",true);
	}
	});
 });
</script>