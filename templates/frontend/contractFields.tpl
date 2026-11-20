{**
 * plugins/generic/objectsForReview/templates/frontend/contractFields.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Contract fields: terms, due date and how the reviewer gets the work.
 * Used by the reservation dialog (direct reservation) and the contract page
 * (selected bidders). Expects $reserveContract, $reserveDueDate,
 * $reserveErrors and $reserveInput.
 *}
{assign var=input value=$reserveInput}
<div class="ofrContractFields">
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
</div>

<style>
	.ofrContractText { max-height: 14rem; overflow-y: auto; border: 1px solid #ddd; padding: 0.75rem; margin-bottom: 0.75rem; background: #fafafa; }
	.ofrContractFields .ofrField, .ofrResponse .ofrField { margin: 1rem 0; }
	.ofrContractFields .ofrField label, .ofrResponse .ofrField label { display: block; font-weight: bold; margin-bottom: 0.25rem; }
	.ofrContractFields textarea, .ofrContractFields input[type=tel], .ofrResponse textarea { width: 100%; box-sizing: border-box; }
	.ofrContractFields fieldset { border: 1px solid #ddd; padding: 0.75rem 1rem; margin: 1rem 0; }
	.ofrContractFields legend { font-weight: bold; padding: 0 0.25rem; }
	.ofrRadio, .ofrCheckbox { display: block; margin: 0.35rem 0; }
	.ofrHint { font-size: 0.875rem; color: #555; margin: 0.25rem 0 0; }
	.ofrRequired { color: #b00; }
	.ofrErrors { border-left: 4px solid #b00; background: #fdf0f0; padding: 0.5rem 1rem; margin-bottom: 1rem; }
</style>

<script>
	// Show the fields for the selected delivery option; only visible fields are required
	document.querySelectorAll('.ofrContractFields').forEach(function (box) {ldelim}
		function sync() {ldelim}
			var checked = box.querySelector('input[name="copyPreference"]:checked');
			var value = checked ? checked.value : '';
			box.querySelectorAll('[data-show-for]').forEach(function (part) {ldelim}
				var active = part.getAttribute('data-show-for') === value;
				part.hidden = !active;
				part.querySelectorAll('input, textarea').forEach(function (field) {ldelim}
					field.disabled = !active;
					field.required = active;
				{rdelim});
			{rdelim});
		{rdelim}
		box.addEventListener('change', sync);
		sync();
	{rdelim});
</script>
