<?php

/**
 * @file plugins/generic/objectsForReview/controllers/grid/ObjectsForReviewManagementGridHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewManagementGridHandler
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Handle ObjectsForReview management grid requests.
 */


namespace APP\plugins\generic\objectsForReview\controllers\grid;

use APP\plugins\generic\objectsForReview\classes\ObjectForReviewDAO;
use APP\plugins\generic\objectsForReview\classes\Assignment;
use APP\plugins\generic\objectsForReview\classes\AssignmentWorkflow;
use APP\plugins\generic\objectsForReview\classes\Roles;
use APP\core\Application;
use APP\plugins\generic\objectsForReview\classes\CrossrefLookup;
use APP\plugins\generic\objectsForReview\classes\IsbnLookup;

use APP\plugins\generic\objectsForReview\controllers\grid\form\AvailableObjectsForReviewForm;
use APP\plugins\generic\objectsForReview\controllers\grid\form\ObjectsForReviewForm;

use APP\plugins\generic\objectsForReview\controllers\grid\ObjectsForReviewManagementGridRow;
use APP\plugins\generic\objectsForReview\controllers\grid\ObjectsForReviewManagementGridCellProvider;

use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridHandler;

use APP\facades\Repo;

use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class ObjectsForReviewManagementGridHandler extends GridHandler {
	public $plugin;

	/**
	 * Constructor
	 */
	function __construct($plugin) {
		parent::__construct();
		$this->plugin = $plugin;
		$this->addRoleAssignment(
			array(Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR),
			array('fetchGrid', 'fetchRow', 'addAvailableObjectForReview', 'editAvailableObjectForReview', 'updateAvailableObjectForReview', 'deleteAvailableObjectForReview', 'toggleHidden', 'lookupDoi', 'lookupIsbn')
		);
	}

	//
	// Getters/Setters
	//

	//
	// Overridden template methods
	//
 	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		if (!parent::authorize($request, $args, $roleAssignments)) {
			return false;
		}
		// Section editors need to be book review editors
		$userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
		return Roles::canManage($this->plugin, $request->getContext()->getId(), $request->getUser()?->getId(), $userRoles);
	}

	/**
	 * @copydoc Gridhandler::initialize()
	 */
	function initialize($request, $args = null) {
		parent::initialize($request, $args);
		$context = $request->getContext();

		// Set the grid details.
		$this->setTitle('plugins.generic.objectsForReview.management.gridTitle');
		$this->setEmptyRowText('plugins.generic.objectsForReview.noneCreated');

		// Get the items and add the data to the grid
		$objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
		$objectsForReview = $objectForReviewDao->getAll($context->getId(), true, true);
		$assignmentsByObject = AssignmentWorkflow::dao()->getByContextId($context->getId());

		$gridData = array();
		while ($objectForReview = $objectsForReview->next()) {
			$objectId = $objectForReview->getId();
			$assignments = $assignmentsByObject[$objectId] ?? [];
			$state = AssignmentWorkflow::getState($objectForReview, $assignments);
			$userName = __('plugins.generic.objectsForReview.offerState.' . $state);
			if ($objectForReview->getUserId() && ($user = Repo::user()->get($objectForReview->getUserId(), true))) {
				$userName .= ': ' . $user->getFullName() . ' (' . $user->getEmail() . ')';
			} elseif ($objectForReview->isBidding() && in_array($state, [AssignmentWorkflow::STATE_OPEN, AssignmentWorkflow::STATE_SELECTION], true)) {
				$userName .= ' (' . __('plugins.generic.objectsForReview.offerState.bidCount',
					['number' => count(AssignmentWorkflow::filter($assignments, Assignment::BIDDER_STATUSES))]) . ')';
			}
			if ($objectForReview->isHidden()) {
				$userName .= ' · ' . __('plugins.generic.objectsForReview.hidden');
			}

			$gridData[$objectId] = array(
				'identifierType' => $objectForReview->getIdentifierType(),
				'identifier' => $objectForReview->getIdentifier(),
				'description' => $objectForReview->getDescription(),
				'user' => $userName,
				'hidden' => $objectForReview->isHidden(),
			);
		}

		$this->setGridDataElements($gridData);

		// Add grid-level actions
		$router = $request->getRouter();
		$this->addAction(
			new LinkAction(
				'addAvailableObjectForReview',
				new AjaxModal(
					$router->url($request, null, null, 'addAvailableObjectForReview', null, array()),
					__('plugins.generic.objectsForReview.addAvailableObjectForReview'),
					'modal_add_item'
				),
				__('plugins.generic.objectsForReview.addAvailableObjectForReview'),
				'add_item'
			)
		);

		// Columns
		$cellProvider = new ObjectsForReviewManagementGridCellProvider();
		$this->addColumn(new GridColumn(
			'description',
			'plugins.generic.objectsForReview.itemDescription',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider,
			array('html' => true)
		));
		$this->addColumn(new GridColumn(
			'identifierType',
			'plugins.generic.objectsForReview.itemIdentifierType',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider
		));
		$this->addColumn(new GridColumn(
			'identifier',
			'plugins.generic.objectsForReview.itemIdentifier',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider
		));
		$this->addColumn(new GridColumn(
			'user',
			'common.status',
			null,
			'controllers/grid/gridCell.tpl',
			$cellProvider
		));

	}

	//
	// Overridden methods from GridHandler
	//
	/**
	 * @copydoc Gridhandler::getRowInstance()
	 */
	function getRowInstance() {
		return new ObjectsForReviewManagementGridRow();
	}

	//
	// Public Grid Actions
	//

	/**
	 * An action to add a new available ObjectForReview item
	 * @param $args array Arguments to the request
	 * @param $request PKPRequest
	 */
	function addAvailableObjectForReview($args, $request) {
		// Calling editObjectForReview with an empty ID will add
		// a new ObjectsForReview item.
		return $this->editAvailableObjectForReview($args, $request);
	}

	/**
	 * An action to edit an available objectForReview
	 * @param $args array Arguments to the request
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function editAvailableObjectForReview($args, $request) {
		$objectId = $request->getUserVar('objectId');
		$context = $request->getContext();

		$this->setupTemplate($request);

        $availableObjectsForReviewForm = new AvailableObjectsForReviewForm($this->plugin, $context->getId(), $objectId);
        $availableObjectsForReviewForm->initData();
        return new JSONMessage(true, $availableObjectsForReviewForm->fetch($request));

	}

	/**
	 * Update a objectForReview
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function updateAvailableObjectForReview($args, $request) {
		$objectId = $request->getUserVar('objectId');
		$context = $request->getContext();

		$this->setupTemplate($request);

		// Create and populate the form
		$availableObjectsForReviewForm = new AvailableObjectsForReviewForm($this->plugin, $context->getId(), $objectId);
		$availableObjectsForReviewForm->readInputData();
		// Validate
		if ($availableObjectsForReviewForm->validate()) {
			// Save
			$objectsForReview = $availableObjectsForReviewForm->execute();
 			return DAO::getDataChangedEvent($objectId);
		} else {
			// Present any errors
			return new JSONMessage(true, $availableObjectsForReviewForm->fetch($request));
		}
	}

	/**
	 * Delete a objectForReview
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function deleteAvailableObjectForReview($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
		$objectForReview = $objectForReviewDao->getById((int) $request->getUserVar('objectId'));
		// Only objects of the current journal
		if (!$objectForReview || $objectForReview->getContextId() != $request->getContext()->getId()) {
			return new JSONMessage(false);
		}
		$objectForReviewDao->deleteObject($objectForReview);
		return DAO::getDataChangedEvent();
	}

	/**
	 * Hide an object from the public list, or show it again.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function toggleHidden($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			return new JSONMessage(false);
		}
		$object = (new ObjectForReviewDAO())->getById((int) $request->getUserVar('objectId'));
		if (!$object || $object->getContextId() != $request->getContext()->getId()) {
			return new JSONMessage(false);
		}
		AssignmentWorkflow::setHidden($object, !$object->isHidden(), $request->getUser());
		return DAO::getDataChangedEvent($object->getId());
	}

	/**
	 * Look up metadata for a DOI at Crossref
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function lookupDoi($args, $request) {
		return CrossrefLookup::handleRequest($request);
	}

	/**
	 * Look up metadata for an ISBN (Open Library, Crossref)
	 * @param $args array
	 * @param $request PKPRequest
	 * @return JSONMessage
	 */
	function lookupIsbn($args, $request) {
		return IsbnLookup::handleRequest($request);
	}

}

?>
