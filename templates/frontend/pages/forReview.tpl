{**
 * plugins/generic/objectsForReview/templates/frontend/pages/forReview.tpl
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * The template used for listing available objects for review
 *}

{include file="frontend/components/header.tpl" pageTitle="plugins.generic.objectsForReview.frontendTitle"}

<script type="text/javascript">
  window.addEventListener('DOMContentLoaded', function(){ldelim}
    new Tablesort(document.getElementById('objects-for-review-table'));
  {rdelim});
</script>

<div class="page objectsForReview">
  {include file="frontend/components/breadcrumbs.tpl" currentTitleKey="plugins.generic.objectsForReview.frontendTitle"}

  <p>{$instructions|strip_unsafe_html}</p>

  {if !$currentUser}
    <p>{translate key='plugins.generic.objectsForReview.logInToReserve'}</p>
  {/if}

		<table id="objects-for-review-table" style="width:100%">
      <tr data-sort-method='none'>
        <th>{translate key="plugins.generic.objectsForReview.itemTitle"}</th>
        <th>{translate key="plugins.generic.objectsForReview.itemAuthors"}</th>
        <th>{translate key="plugins.generic.objectsForReview.itemPublisher"}</th>
        <th>{translate key="plugins.generic.objectsForReview.itemYearShort"}</th>
        <th></th> 
      </tr>
			{foreach from=$objectsForReview item=objectForReview}
      <tr>
				<td data-sort='{$objectForReview.title|strip_tags}'>
            {$objectForReview.title|strip_unsafe_html}<br />
            {$objectForReview.identifierType|escape}:
            {if $objectForReview.identifierUrl}
              <a href="{$objectForReview.identifierUrl|escape}" target="_blank" rel="noopener noreferrer">{$objectForReview.identifier|escape}</a>
            {else}
              {$objectForReview.identifier|escape}
            {/if}
				</td>
        <td>
            {$objectForReview.authors}
        </td>
        <td>
            {$objectForReview.publisher}
        </td>
        <td>
            {$objectForReview.year}
        </td>
        <td class="ofrStatusCell">
          {assign var=o value=$objectForReview}
          {if $o.myStatus == 'accepted'}
            {* The current user's reservation: due date and cancel button *}
            {if $o.dueDate}
              <p class="ofrDueDate">{translate key="plugins.generic.objectsForReview.reserve.yourDueDate" dueDate=$o.dueDate|date_format:$dateFormatShort}</p>
            {/if}
            <form class="pkp_form" id="cancelObjectForm-{$o.objectId|escape}" method="post" action="{url op="cancelObject"}" onsubmit="return confirm('{translate|escape:"javascript"|escape key="plugins.generic.objectsForReview.cancel.confirm"}');">
              {csrf}
              <input type="hidden" name="objectId" value="{$o.objectId|escape}" />
              <button type="submit" id="cancelObject-{$o.objectId|escape}" class="pkp_button pkp_button_offset">{translate key='plugins.generic.objectsForReview.cancel'}</button>
            </form>
          {elseif $o.myStatus == 'offered'}
            {* Selected bidder: respond to the contract offer *}
            <p class="ofrMyStatus">{translate key="plugins.generic.objectsForReview.bid.selected" deadline=$o.contractDeadline|date_format:$dateFormatShort}</p>
            <a class="pkp_button ofrRespondButton" href="{url op="contract" objectId=$o.objectId}">{translate key="plugins.generic.objectsForReview.bid.respond"}</a>
          {elseif $o.myStatus == 'bid' || $o.myStatus == 'unsuccessful'}
            {* The current user's bid *}
            <p class="ofrMyStatus">{if $o.myStatus == 'bid'}{translate key="plugins.generic.objectsForReview.bid.yours" date=$o.myDate|date_format:$dateFormatShort}{else}{translate key="plugins.generic.objectsForReview.bid.unsuccessful"}{/if}</p>
            <form class="pkp_form" method="post" action="{url op="withdrawBid"}" onsubmit="return confirm('{translate|escape:"javascript"|escape key="plugins.generic.objectsForReview.bid.withdraw.confirm"}');">
              {csrf}
              <input type="hidden" name="objectId" value="{$o.objectId|escape}" />
              <button type="submit" class="pkp_button pkp_button_offset ofrWithdrawButton">{translate key='plugins.generic.objectsForReview.bid.withdraw'}</button>
            </form>
          {elseif $o.reservable}
            {if $currentUser}
              <a class="pkp_button ofrReserveButton" id="reserveObject-{$o.objectId|escape}" href="{url op="index" reserve=$o.objectId}" data-object-id="{$o.objectId|escape}" data-description="{$o.description|strip_tags|escape}">{translate key='plugins.generic.objectsForReview.reserve'}</a>
            {else}
              {translate key='plugins.generic.objectsForReview.objectAvailable'}
            {/if}
          {elseif $o.biddingOpen}
            {if $o.bidDeadline}<p class="ofrBidDeadline">{translate key="plugins.generic.objectsForReview.bid.deadline" deadline=$o.bidDeadline|date_format:$dateFormatShort}</p>{/if}
            {if $currentUser}
              <button type="button" class="pkp_button ofrBidButton" data-object-id="{$o.objectId|escape}" data-description="{$o.description|strip_tags|escape}">{translate key='plugins.generic.objectsForReview.bid'}</button>
            {else}
              {translate key='plugins.generic.objectsForReview.bid.open'}
            {/if}
          {elseif $o.state == 'selection' || $o.state == 'contractOffered'}
            {translate key='plugins.generic.objectsForReview.bid.closed'}
          {else}
            {translate key='plugins.generic.objectsForReview.objectReserved'}
          {/if}
        </td>
        </tr>
			{/foreach}
		</table>
		{if $currentUser}
			{include file=$reserveDialogTemplate}
			<dialog id="ofrBidDialog" class="ofrReserveDialog" aria-labelledby="ofrBidDialogTitle">
				<form method="post" action="{url op="bid"}">
					{csrf}
					<input type="hidden" name="objectId" value="" />
					<h2 id="ofrBidDialogTitle">{translate key="plugins.generic.objectsForReview.bid.title"}</h2>
					<p class="ofrReserveObject"></p>
					<p>{translate key="plugins.generic.objectsForReview.bid.description"}</p>
					<div class="ofrField">
						<label for="ofrStatement">{translate key="plugins.generic.objectsForReview.bid.statement"}</label>
						<textarea id="ofrStatement" name="statement" rows="5" maxlength="{$maxStatementLength|escape}"></textarea>
						<p class="ofrHint">{translate key="plugins.generic.objectsForReview.bid.statement.hint"}</p>
					</div>
					<div class="ofrButtons">
						<button type="submit" class="pkp_button">{translate key="plugins.generic.objectsForReview.bid.submit"}</button>
						<button type="button" class="pkp_button pkp_button_offset ofrCancel">{translate key="common.cancel"}</button>
					</div>
				</form>
			</dialog>
			<style>
				#ofrBidDialog .ofrField label { display: block; font-weight: bold; margin-bottom: 0.25rem; }
				#ofrBidDialog textarea { width: 100%; box-sizing: border-box; }
				#ofrBidDialog .ofrHint { font-size: 0.875rem; color: #555; }
				.ofrMyStatus, .ofrBidDeadline { font-size: 0.875rem; margin: 0 0 0.5rem; }
			</style>
			<script>
				(function () {ldelim}
					var dialog = document.getElementById('ofrBidDialog');
					if (!dialog || typeof dialog.showModal !== 'function') return;
					var form = dialog.querySelector('form');
					document.querySelectorAll('.ofrBidButton').forEach(function (button) {ldelim}
						button.addEventListener('click', function () {ldelim}
							form.elements.objectId.value = button.getAttribute('data-object-id');
							dialog.querySelector('.ofrReserveObject').textContent = button.getAttribute('data-description');
							dialog.showModal();
						{rdelim});
					{rdelim});
					dialog.querySelector('.ofrCancel').addEventListener('click', function () {ldelim} dialog.close(); {rdelim});
				{rdelim})();
			</script>
		{/if}
</div>
{include file="frontend/components/footer.tpl"}
