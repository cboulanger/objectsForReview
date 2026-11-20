{**
 * plugins/generic/objectsForReview/templates/reservationEmailForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * A workflow step with a prefilled, editable email (see ReservationEmailForm):
 * $mode message | cancel | contractOffer | unsuccessful | copyStep (see CopyStepForm)
 *}
<script>
	$(function() {ldelim}
		$('#ofrReservationEmailForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="ofrReservationEmailForm" method="post" action="{$actionUrl|escape}">
	{csrf}
	<input type="hidden" name="objectId" value="{$objectId|escape}" />
	{foreach from=$assignmentIds item=assignmentId}
		<input type="hidden" name="assignmentIds[]" value="{$assignmentId|escape}" />
	{/foreach}
	{if $step}<input type="hidden" name="step" value="{$step|escape}" />{/if}

	{fbvFormArea id="ofrReservationEmailFormArea"}
		<p class="ofrReservationObject"><em>{$objectDescription|strip_unsafe_html}</em></p>
		{if $mode == 'cancel'}
			<p>{if $cancelOffer}{translate key="plugins.generic.objectsForReview.contractOffer.cancel.description" reviewer=$reviewerName|escape}{else}{translate key="plugins.generic.objectsForReview.reservations.cancel.description" reviewer=$reviewerName|escape}{/if}</p>
		{elseif $mode == 'contractOffer'}
			<p>{translate key="plugins.generic.objectsForReview.contractOffer.description" reviewer=$reviewerName|escape}</p>
		{elseif $mode == 'unsuccessful'}
			<p>{translate key="plugins.generic.objectsForReview.unsuccessful.description"}</p>
		{elseif $stepDescription}
			<p class="ofrStepDescription">{$stepDescription|escape}</p>
		{/if}

		{if $returnTargets}
			{fbvFormSection list=true label="plugins.generic.objectsForReview.copy.returnTarget"}
				{foreach from=$returnTargets key=target item=address}
					<label class="ofrReturnTarget">
						<input type="radio" name="returnTarget" value="{$target|escape}"{if $target == $returnTarget} checked{/if} />
						<strong>{translate key="plugins.generic.objectsForReview.copy.to.$target"}</strong>
						<span class="ofrDetail">{$address|escape|nl2br}</span>
					</label>
				{/foreach}
			{/fbvFormSection}
		{/if}

		{if !$noEmail}
		{if $optionalEmail}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="sendEmail" value="1" checked=$sendEmail label="plugins.generic.objectsForReview.reservations.cancel.sendEmail"}
			{/fbvFormSection}
		{/if}

		<div class="ofrEmailFields">
			{fbvFormSection label="email.to"}
				{foreach from=$recipients item=recipient}
					<p class="ofrRecipient">{$recipient.name|escape} &lt;{$recipient.email|escape}&gt;</p>
				{/foreach}
			{/fbvFormSection}
			{fbvFormSection label="email.subject" required=true}
				{fbvElement type="text" id="subject" value=$subject maxlength="255" required=true}
			{/fbvFormSection}
			{fbvFormSection label="email.body" required=true}
				{fbvElement type="textarea" id="body" value=$body rich=true height=$fbvStyles.height.TALL}
				{if $mode == 'contractOffer'}
					<p class="ofrHint">{translate key="plugins.generic.objectsForReview.contractOffer.linkHint"}</p>
				{elseif $mode == 'unsuccessful'}
					<p class="ofrHint">{translate key="plugins.generic.objectsForReview.unsuccessful.linkHint"}</p>
				{elseif $bodyHint}
					<p class="ofrHint">{$bodyHint|escape}</p>
				{/if}
			{/fbvFormSection}
		</div>
		{/if}

		{if $submitKey}
			{fbvFormButtons submitText=$submitKey}
		{elseif $mode == 'cancel'}
			{fbvFormButtons submitText="plugins.generic.objectsForReview.reservations.cancel.submit"}
		{elseif $mode == 'contractOffer'}
			{fbvFormButtons submitText="plugins.generic.objectsForReview.contractOffer.submit"}
		{elseif $mode == 'unsuccessful'}
			{fbvFormButtons submitText="plugins.generic.objectsForReview.unsuccessful.submit"}
		{else}
			{fbvFormButtons submitText="plugins.generic.objectsForReview.reservations.email.submit"}
		{/if}
	{/fbvFormArea}
</form>

{if $optionalEmail}
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
