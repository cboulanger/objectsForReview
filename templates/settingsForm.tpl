{**
 * plugins/generic/objectsForReview/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Objects for Review plugin settings
 *
 *}
<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#orSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="orSettingsForm" method="post" action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true standalone=$standalone}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="orSettingsFormNotification"}

	{fbvFormArea id="objectsForReviewSettingsFormArea"}

			<!--{fbvElement type="select" id="section" from=$sections selected=$section translate=false size=$fbvStyles.size.SMALL}-->

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="onlyReserved" value="1" label="plugins.generic.objectsForReview.settings.onlyReserved" checked=$onlyReserved}
		{/fbvFormSection}
		{fbvFormSection list=true label="plugins.generic.objectsForReview.settings.displayOptions"}
			{fbvElement type="checkbox" id="displayAsList" value="1" label="plugins.generic.objectsForReview.settings.displayAsList" checked=$displayAsList}
		{/fbvFormSection}

		{fbvFormSection list=true label="plugins.generic.objectsForReview.settings.notifyEmail"}
			{fbvElement type="text" label="plugins.generic.objectsForReview.settings.notifyEmail.description" name="ofrNotifyEmail" id="ofrNotifyEmail" value=$ofrNotifyEmail maxlength="255" inline=true size=$fbvStyles.size.MEDIUM}
		{/fbvFormSection}

		{fbvFormSection list=true label="plugins.generic.objectsForReview.settings.instructions"}
			{fbvElement type="textarea" label="plugins.generic.objectsForReview.settings.instructions.description" name="ofrInstructions" id="ofrInstructions" value=$ofrInstructions multilingual="true" inline=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.objectsForReview.settings.contract"}
			{fbvElement type="textarea" label="plugins.generic.objectsForReview.settings.contract.description" name="ofrContract" id="ofrContract" value=$ofrContract multilingual=true rich=true}
		{/fbvFormSection}

		{fbvFormSection label="plugins.generic.objectsForReview.settings.dueMonths" description="plugins.generic.objectsForReview.settings.dueMonths.description"}
			{fbvElement type="text" label="plugins.generic.objectsForReview.settings.dueMonthsDefault" id="ofrDueMonthsDefault" value=$ofrDueMonthsDefault maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
			{fbvElement type="text" label="plugins.generic.objectsForReview.settings.dueMonthsMax" id="ofrDueMonthsMax" value=$ofrDueMonthsMax maxlength="2" inline=true size=$fbvStyles.size.SMALL required=true}
		{/fbvFormSection}

		{if $standalone}
			{fbvFormButtons hideCancel=true submitText="common.save"}
		{else}
			{fbvFormButtons}
		{/if}

	{/fbvFormArea}

	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>

</form>
