{**
 * plugins/generic/objectsForReview/templates/management.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Backend pages of the "Objects for Review" menu section.
 *
 * $view: available | reservations | reviewedWorks | settings
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>

	<div class="app__contentPanel objectsForReviewManagement">
		{if $view == 'available'}
			{load_url_in_div id="objectsForReviewGridContainer" url=$gridUrl}

		{elseif $view == 'settings'}
			{load_url_in_div id="objectsForReviewSettingsContainer" url=$settingsUrl}

		{elseif $view == 'reservations'}
			{if $users|@count > 1}
				<form class="ofrFilter" method="get" action="{url op="reservations"}">
					<label for="ofrFilterUser">{translate key="plugins.generic.objectsForReview.column.reservedBy"}</label>
					<select id="ofrFilterUser" name="userId" onchange="this.form.submit()">
						<option value="">{translate key="plugins.generic.objectsForReview.filter.allUsers"}</option>
						{foreach from=$users key=userId item=userName}
							<option value="{$userId|escape}"{if $userId == $filterUserId} selected{/if}>{$userName|escape}</option>
						{/foreach}
					</select>
					<noscript><button type="submit" class="pkp_button">{translate key="plugins.generic.objectsForReview.filter.apply"}</button></noscript>
				</form>
			{/if}
			{if $rows|@count}
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.objectsForReview.column.object"}</th>
							<th>{translate key="plugins.generic.objectsForReview.column.reservedBy"}</th>
							<th>{translate key="plugins.generic.objectsForReview.column.dates"}</th>
							<th>{translate key="plugins.generic.objectsForReview.reserve.copy"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$rows item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>{$row.user|escape}</td>
								<td class="ofrNoWrap">
									{if $row.dateReserved}{translate key="plugins.generic.objectsForReview.column.reservedOn"}: {$row.dateReserved|date_format:$dateFormatShort}<br />{/if}
									{if $row.dueDate}<strong>{translate key="plugins.generic.objectsForReview.reserve.dueDate"}: {$row.dueDate|date_format:$dateFormatShort}</strong>{/if}
								</td>
								<td>
									{$row.copyPreferenceLabel|escape}
									{if $row.shippingAddress}<div class="ofrDetail">{$row.shippingAddress|escape|nl2br}</div>{/if}
									{if $row.phone}<div class="ofrDetail">{translate key="plugins.generic.objectsForReview.reserve.phone"}: {$row.phone|escape}</div>{/if}
									{if $row.copyNote}<div class="ofrDetail">{$row.copyNote|escape|nl2br}</div>{/if}
								</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{translate key="plugins.generic.objectsForReview.reservations.none"}</p>
			{/if}

		{elseif $view == 'reviewedWorks'}
			{if $rows|@count}
				<table class="ofrTable">
					<thead>
						<tr>
							<th>{translate key="plugins.generic.objectsForReview.column.object"}</th>
							<th>{translate key="plugins.generic.objectsForReview.column.submission"}</th>
							<th>{translate key="common.status"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$rows item=row}
							<tr>
								<td>{include file=$ofrObjectCell row=$row}</td>
								<td>
									{if $row.submissionUrl}
										<a href="{$row.submissionUrl|escape}">#{$row.submissionId|escape} {$row.submissionTitle|strip_tags|escape}</a>
									{else}
										#{$row.submissionId|escape}
									{/if}
								</td>
								<td>{$row.submissionStatus|escape}</td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			{else}
				<p>{translate key="plugins.generic.objectsForReview.reviewedWorks.none"}</p>
			{/if}
		{/if}
	</div>
{/block}
