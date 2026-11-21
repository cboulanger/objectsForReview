<?php
/**
 * @file plugins/generic/objectsForReview/controllers/grid/ObjectsForReviewManagementGridCellProvider.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewManagementGridCellProvider
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Class for a cell provider to display information about objectsForReview items
 */

namespace APP\plugins\generic\objectsForReview\controllers\grid;


use PKP\controllers\grid\GridCellProvider;
use PKP\core\PKPString;



class ObjectsForReviewManagementGridCellProvider extends GridCellProvider {

	//
	// Template methods from GridCellProvider
	//

	/**
	 * Extracts variables for a given column from a data element
	 * so that they may be assigned to template before rendering.
	 *
	 * @copydoc GridCellProvider::getTemplateVarsFromRowColumn()
	 */
	function getTemplateVarsFromRowColumn($row, $column) {
		$objectsForReviewItem = $row->getData();
		switch ($column->getId()) {
			case 'identifierType':
				return array('label' => $objectsForReviewItem['identifierType']);
			case 'identifier':
				return array('label' => $objectsForReviewItem['identifier']);
			case 'description':
				return array('label' => $this->getDescriptionHtml($objectsForReviewItem));
			case 'user':
				return array('label' => $objectsForReviewItem['user']);	
		}
	}

	/**
	 * The description (column flag anyhtml): a review essay is labelled as such, its works
	 * are indented below it.
	 */
	protected function getDescriptionHtml(array $item): string {
		$description = PKPString::stripUnsafeHtml((string) $item['description']);
		switch ($item['essayRole'] ?? null) {
			case 'container':
				return '<strong>' . htmlspecialchars(__('plugins.generic.objectsForReview.reviewEssay.label')) . '</strong> ' . $description;
			case 'member':
				return '<span class="ofrEssayMember">↳ ' . $description . '</span>';
		}
		return $description;
	}
}

?>
