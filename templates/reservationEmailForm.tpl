{**
 * plugins/generic/objectsForReview/templates/reservationEmailForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Email the reviewer of a reservation ($mode "message") or cancel the
 * reservation with an optional notification ($mode "cancel").
 *}
<script>
	$(function() {ldelim}
		$('#ofrReservationEmailForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="ofrReservationEmailForm" method="post" action="{$actionUrl|escape}">
	{csrf}
	<input type="hidden" name="objectId" value="{$objectId|escape}" />

	{fbvFormArea id="ofrReservationEmailFormArea"}
		<p>
			{if $mode == 'cancel'}
				{translate key="plugins.generic.objectsForReview.reservations.cancel.description" reviewer=$reviewerName|escape}
			{/if}
		</p>
		<p class="ofrReservationObject"><em>{$objectDescription|strip_unsafe_html}</em></p>

		{if $mode == 'cancel'}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="sendEmail" value="1" checked=$sendEmail label="plugins.generic.objectsForReview.reservations.cancel.sendEmail"}
			{/fbvFormSection}
		{/if}

		<div class="ofrEmailFields">
			{fbvFormSection label="email.to"}
				<p>{$reviewerName|escape} &lt;{$reviewerEmail|escape}&gt;</p>
			{/fbvFormSection}
			{fbvFormSection label="email.subject" required=true}
				{fbvElement type="text" id="subject" value=$subject maxlength="255" required=true}
			{/fbvFormSection}
			{fbvFormSection label="email.body" required=true}
				{fbvElement type="textarea" id="body" value=$body rich=true height=$fbvStyles.height.TALL}
			{/fbvFormSection}
		</div>

		{if $mode == 'cancel'}
			{fbvFormButtons submitText="plugins.generic.objectsForReview.reservations.cancel.submit"}
		{else}
			{fbvFormButtons submitText="plugins.generic.objectsForReview.reservations.email.submit"}
		{/if}
	{/fbvFormArea}
</form>

{if $mode == 'cancel'}
	<script>
		$(function() {ldelim}
			var $form = $('#ofrReservationEmailForm'),
				$toggle = $form.find('input[name="sendEmail"]');
			function sync() {ldelim}
				$form.find('.ofrEmailFields').toggle($toggle.is(':checked'));
			{rdelim}
			$toggle.on('change', sync);
			sync();
		{rdelim});
	</script>
{/if}
