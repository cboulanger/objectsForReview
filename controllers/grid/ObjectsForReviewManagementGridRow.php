<?php

/**
 * @file plugins/generic/objectsForReview/controllers/grid/ObjectsForReviewManagementGridRow.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewManagementGridRow
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Handle ObjectsForReview management grid row requests.
 */

namespace APP\plugins\generic\objectsForReview\controllers\grid;

use PKP\controllers\grid\GridRow;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RemoteActionConfirmationModal;

class ObjectsForReviewManagementGridRow extends GridRow {

	/**
	 * Constructor
	 */
	function __construct($readOnly = false) {
		parent::__construct();
	}

	//
	// Overridden template methods
	//
	/**
	 * @copydoc GridRow::initialize()
	 */
	function initialize($request, $template = null) {
		parent::initialize($request, $template);
		$objectId = $this->getId();

		if (!empty($objectId)) {
			$router = $request->getRouter();

			// Create the "edit" action
			$this->addAction(
				new LinkAction(
					'edit',
					new AjaxModal(
						$router->url($request, null, null, 'editAvailableObjectForReview', null, array('objectId' => $objectId)),
						__('grid.action.edit'),
						'modal_edit',
						true),
					__('grid.action.edit'),
					'edit'
				)
			);

			// Hide from the public list, or show again
			$hidden = !empty($this->getData()['hidden']);
			$label = __($hidden ? 'plugins.generic.objectsForReview.show' : 'plugins.generic.objectsForReview.hide');
			$this->addAction(
				new LinkAction(
					'toggleHidden',
					new AjaxAction(
						$router->url($request, null, null, 'toggleHidden', null, ['objectId' => $objectId]),
						AjaxAction::AJAX_REQUEST_TYPE_POST,
						['csrfToken' => $request->getSession()->token()]
					),
					$label,
					$hidden ? 'unhide' : 'hide'
				)
			);

			// Audit trail of the object's reservations
			$this->addAction(
				new LinkAction(
					'history',
					new AjaxModal(
						$request->getDispatcher()->url($request, \APP\core\Application::ROUTE_COMPONENT, null,
							\APP\plugins\generic\objectsForReview\controllers\ReservationActionsHandler::COMPONENT, 'history', null, ['objectId' => $objectId]),
						__('plugins.generic.objectsForReview.history'),
						'modal_information'),
					__('plugins.generic.objectsForReview.history'),
					'history'
				)
			);

			// Create the "delete" action
			$this->addAction(
				new LinkAction(
					'delete',
					new RemoteActionConfirmationModal(
						$request->getSession(),
						__('common.confirmDelete'),
						__('grid.action.delete'),
						$router->url($request, null, null, 'deleteAvailableObjectForReview', null, array('objectId' => $objectId)), 'modal_delete'
					),
					__('grid.action.delete'),
					'delete'
				)
			);
		}
	}
}

?>
