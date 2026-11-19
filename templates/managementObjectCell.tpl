{**
 * plugins/generic/objectsForReview/templates/managementObjectCell.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Object description with (linked) identifier, used in the backend tables.
 *
 * $row: objectId, description, identifierType, identifier, identifierUrl
 *}
{$row.description|strip_unsafe_html}
<div class="ofrIdentifier">
	{$row.identifierType|escape}:
	{if $row.identifierUrl}
		<a href="{$row.identifierUrl|escape}" target="_blank" rel="noopener noreferrer">{$row.identifier|escape}</a>
	{else}
		{$row.identifier|escape}
	{/if}
</div>
