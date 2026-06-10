<?xml version="1.0" encoding="ISO-8859-1"?>

<form id="submit_cmi_payment_form" method="POST" action="{$actionslk}">
{foreach from=$data key=k item=v}
<input type="hidden" name="{$k}" value="{$v}">
 {/foreach}
</form>
<script type="text/javascript">
	document.getElementById("submit_cmi_payment_form").submit();
</script>

