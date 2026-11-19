{**
 * plugins/generic/objectsForReview/templates/frontend/reserveDialog.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Reservation dialog: contract, due date and how the reviewer gets the work.
 * Opened by the "reserve" buttons; without JavaScript it is shown via ?reserve=<objectId>.
 *}
{assign var=input value=$reserveInput}
<dialog id="ofrReserveDialog" class="ofrReserveDialog" aria-labelledby="ofrReserveDialogTitle"
	data-open-object-id="{$reserveOpenObjectId|escape}"{if $reserveOpenObjectId} open{/if}>
	<form class="ofrReserveForm" method="post" action="{url op="reserveObject"}">
		{csrf}
		<input type="hidden" name="objectId" value="{$reserveOpenObjectId|escape}" />

		<h2 id="ofrReserveDialogTitle">{translate key="plugins.generic.objectsForReview.reserve.title"}</h2>
		<p class="ofrReserveObject">{if $reserveOpenObjectId}{$objectsForReview[$reserveOpenObjectId].description|strip_unsafe_html}{/if}</p>

		{if $reserveErrors}
			<div class="ofrErrors" role="alert">
				<p>{translate key="plugins.generic.objectsForReview.reserve.error.summary"}</p>
				<ul>
					{foreach from=$reserveErrors item=errorKey}
						<li>{translate key=$errorKey}</li>
					{/foreach}
				</ul>
			</div>
		{/if}

		<section class="ofrContract">
			<h3>{translate key="plugins.generic.objectsForReview.reserve.contract"}</h3>
			<div class="ofrContractText" tabindex="0">{$reserveContract|strip_unsafe_html}</div>
			<label class="ofrCheckbox">
				<input type="checkbox" name="acceptTerms" value="1" required{if $input.acceptTerms} checked{/if} />
				{translate key="plugins.generic.objectsForReview.reserve.acceptTerms"}
			</label>
		</section>

		<div class="ofrField">
			<label for="ofrDueDate">{translate key="plugins.generic.objectsForReview.reserve.dueDate"} <span class="ofrRequired">*</span></label>
			<input type="date" id="ofrDueDate" name="dueDate" required
				min="{$reserveDueDate.min|escape}" max="{$reserveDueDate.max|escape}" value="{$input.dueDate|escape}" />
			<p class="ofrHint">{translate key="plugins.generic.objectsForReview.reserve.dueDate.hint" maxDate=$reserveDueDate.max|date_format:$dateFormatShort}</p>
		</div>

		<fieldset class="ofrCopy">
			<legend>{translate key="plugins.generic.objectsForReview.reserve.copy"} <span class="ofrRequired">*</span></legend>
			{foreach from=['have', 'physical', 'electronic', 'other'] item=preference}
				<label class="ofrRadio">
					<input type="radio" name="copyPreference" value="{$preference}" required{if $input.copyPreference == $preference} checked{/if} />
					{translate key="plugins.generic.objectsForReview.reserve.copy.$preference"}
				</label>
			{/foreach}

			<div class="ofrConditional" data-show-for="physical">
				<div class="ofrField">
					<label for="ofrShippingAddress">{translate key="plugins.generic.objectsForReview.reserve.shippingAddress"} <span class="ofrRequired">*</span></label>
					<textarea id="ofrShippingAddress" name="shippingAddress" rows="4">{$input.shippingAddress|escape}</textarea>
				</div>
				<div class="ofrField">
					<label for="ofrPhone">{translate key="plugins.generic.objectsForReview.reserve.phone"} <span class="ofrRequired">*</span></label>
					<input type="tel" id="ofrPhone" name="phone" value="{$input.phone|escape}" pattern="\+?[0-9 ()\/.\-]{ldelim}6,30{rdelim}" autocomplete="tel" />
					<p class="ofrHint">{translate key="plugins.generic.objectsForReview.reserve.phone.hint"}</p>
				</div>
			</div>

			<div class="ofrConditional" data-show-for="other">
				<div class="ofrField">
					<label for="ofrCopyNote">{translate key="plugins.generic.objectsForReview.reserve.copyNote"} <span class="ofrRequired">*</span></label>
					<textarea id="ofrCopyNote" name="copyNote" rows="3">{$input.copyNote|escape}</textarea>
				</div>
			</div>
		</fieldset>

		<div class="ofrButtons">
			<button type="submit" class="pkp_button">{translate key="plugins.generic.objectsForReview.reserve.submit"}</button>
			<a class="pkp_button pkp_button_offset ofrCancel" href="{url op="index"}">{translate key="common.cancel"}</a>
		</div>
	</form>
</dialog>

<style>
	.ofrReserveDialog { max-width: 42rem; width: calc(100% - 2rem); border: 1px solid #ccc; border-radius: 4px; padding: 1.5rem; }
	.ofrReserveDialog::backdrop { background: rgba(0, 0, 0, 0.5); }
	.ofrReserveDialog h2 { margin-top: 0; }
	.ofrReserveObject { font-style: italic; }
	.ofrContractText { max-height: 14rem; overflow-y: auto; border: 1px solid #ddd; padding: 0.75rem; margin-bottom: 0.75rem; background: #fafafa; }
	.ofrReserveDialog .ofrField { margin: 1rem 0; }
	.ofrReserveDialog .ofrField label { display: block; font-weight: bold; margin-bottom: 0.25rem; }
	.ofrReserveDialog textarea, .ofrReserveDialog input[type=tel] { width: 100%; box-sizing: border-box; }
	.ofrReserveDialog fieldset { border: 1px solid #ddd; padding: 0.75rem 1rem; margin: 1rem 0; }
	.ofrReserveDialog legend { font-weight: bold; padding: 0 0.25rem; }
	.ofrRadio, .ofrCheckbox { display: block; margin: 0.35rem 0; }
	.ofrHint { font-size: 0.875rem; color: #555; margin: 0.25rem 0 0; }
	.ofrRequired { color: #b00; }
	.ofrErrors { border-left: 4px solid #b00; background: #fdf0f0; padding: 0.5rem 1rem; margin-bottom: 1rem; }
	.ofrButtons { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; }
	.ofrDueDate { font-size: 0.875rem; margin: 0 0 0.5rem; }
</style>

<script>
	(function () {ldelim}
		var dialog = document.getElementById('ofrReserveDialog');
		if (!dialog) return;
		var form = dialog.querySelector('form');

		// Show the fields for the selected delivery option; only visible fields are required
		function syncConditional() {ldelim}
			var checked = form.querySelector('input[name="copyPreference"]:checked');
			var value = checked ? checked.value : '';
			dialog.querySelectorAll('[data-show-for]').forEach(function (box) {ldelim}
				var active = box.getAttribute('data-show-for') === value;
				box.hidden = !active;
				box.querySelectorAll('input, textarea').forEach(function (field) {ldelim}
					field.disabled = !active;
					field.required = active;
				{rdelim});
			{rdelim});
		{rdelim}
		form.addEventListener('change', syncConditional);
		syncConditional();

		if (typeof dialog.showModal !== 'function') return; // very old browsers: server-rendered fallback

		document.querySelectorAll('.ofrReserveButton').forEach(function (button) {ldelim}
			button.addEventListener('click', function (event) {ldelim}
				event.preventDefault();
				if (form.elements.objectId.value !== button.getAttribute('data-object-id')) {ldelim}
					var errors = dialog.querySelector('.ofrErrors');
					if (errors) errors.remove();
				{rdelim}
				form.elements.objectId.value = button.getAttribute('data-object-id');
				dialog.querySelector('.ofrReserveObject').textContent = button.getAttribute('data-description');
				dialog.showModal();
			{rdelim});
		{rdelim});

		dialog.querySelector('.ofrCancel').addEventListener('click', function (event) {ldelim}
			event.preventDefault();
			dialog.close();
		{rdelim});

		if (dialog.getAttribute('data-open-object-id') !== '0') {ldelim}
			dialog.removeAttribute('open');
			dialog.showModal();
		{rdelim}
	{rdelim})();
</script>
