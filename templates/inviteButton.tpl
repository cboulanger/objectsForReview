{**
 * plugins/generic/objectsForReview/templates/inviteButton.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * A POST button changing the invitation list (up, down, remove); see invite.tpl.
 * $do, $assignmentId, $labelKey, $symbol
 *}
<form class="ofrInlineForm" method="post" action="{url op="inviteAction"}">
	{csrf}
	<input type="hidden" name="objectId" value="{$objectId|escape}" />
	<input type="hidden" name="assignmentId" value="{$assignmentId|escape}" />
	<input type="hidden" name="do" value="{$do|escape}" />
	<button type="submit" class="ofrLinkButton" title="{translate key=$labelKey}" aria-label="{translate key=$labelKey}">{if $symbol}{$symbol}{else}{translate key=$labelKey}{/if}</button>
</form>
