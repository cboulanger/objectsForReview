{**
 * plugins/generic/objectsForReview/templates/doiLookup.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * "Look up DOI" button for the objects for review forms. Fetches metadata from
 * Crossref (via the grid handler's lookupDoi op) and fills the form fields.
 *
 * Parameters:
 *   $doiLookupUrl  URL of the lookupDoi op
 *}
{assign var=doiLookupId value="orDoiLookup"|concat:"-"|uniqid}
<span class="orDoiLookup" id="{$doiLookupId|escape}"
	data-lookup-url="{$doiLookupUrl|escape}"
	data-msg-loading="{translate|escape key="plugins.generic.objectsForReview.doiLookup.loading"}"
	data-msg-done="{translate|escape key="plugins.generic.objectsForReview.doiLookup.done"}"
	data-msg-failed="{translate|escape key="plugins.generic.objectsForReview.doiLookup.failed"}">
	<button type="button" class="pkp_button orDoiLookupButton">{translate key="plugins.generic.objectsForReview.doiLookup.button"}</button>
	<span class="orDoiLookupStatus" role="status" aria-live="polite"></span>
</span>
<script>
	(function($box) {ldelim}{literal}
		var $form = $box.closest('form'),
			$button = $box.find('.orDoiLookupButton'),
			$status = $box.find('.orDoiLookupStatus'),
			$type = $form.find('[name="identifierType"]');

		function field(name) {
			return $form.find('[name="' + name + '"]');
		}

		function setValue(name, value) {
			if (value) {
				field(name).val(value).trigger('change');
			}
		}

		function setTitle(value) {
			var $title = field('title'),
				editor = window.tinymce && tinymce.get($title.attr('id'));
			if (!value) {
				return;
			}
			if (editor) {
				editor.setContent($('<div>').text(value).html());
				editor.fire('change');
			} else {
				$title.val(value).trigger('change');
			}
		}

		function toggle() {
			$box.toggle($type.val() === 'doi');
			$status.text('');
		}
		$type.on('change', toggle);
		toggle();

		$button.on('click', function() {
			$button.prop('disabled', true);
			$status.text($box.data('msgLoading'));
			$.getJSON($box.data('lookupUrl'), {doi: field('identifier').val()})
				.done(function(json) {
					if (!json || !json.status) {
						$status.text((json && json.content) || $box.data('msgFailed'));
						return;
					}
					var data = json.content;
					setValue('identifier', data.identifier);
					setValue('resourceType', data.resourceType);
					setValue('authors', data.authors);
					setTitle(data.title);
					setValue('publisher', data.publisher);
					setValue('year', data.year);
					$status.text($box.data('msgDone'));
				})
				.fail(function() {
					$status.text($box.data('msgFailed'));
				})
				.always(function() {
					$button.prop('disabled', false);
				});
		});
	{/literal}{rdelim})($('#{$doiLookupId|escape:"javascript"}'));
</script>
