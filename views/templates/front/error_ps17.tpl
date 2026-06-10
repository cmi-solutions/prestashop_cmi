{extends file='page.tpl'}
{block name="page_content"}
	<div>
		<h3>{l s='An error occurred' mod='cmi'}:</h3>
		<ul class="alert alert-danger">
			<li>{l s='The payment could not be processed. You should have received more informations on the previous page' mod='cmi'}</li>
		</ul>
	</div>
{/block}