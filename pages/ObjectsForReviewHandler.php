<?php

/**
 * @file plugins/generic/objectsForReview/pages/ObjectsForReviewHandler.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewPlugin
 * @ingroup plugins_generic_objectsForReview
 * @brief Handle reader-facing router requests for the objects for review plugin
 */

namespace APP\plugins\generic\objectsForReview\pages;
use APP\handler\Handler;
use APP\core\Application;
use PKP\facades\Locale;
use PKP\db\DAORegistry;
use APP\plugins\generic\objectsForReview\classes\ObjectForReviewDAO;
use APP\plugins\generic\objectsForReview\classes\Reservation;
use APP\plugins\generic\objectsForReview\classes\ReservationLog;
use PKP\core\PKPRequest;
use APP\template\TemplateManager;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class ObjectsForReviewHandler extends Handler {

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextRequiredPolicy($request));
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * View objects for review
	 *
	 * Request var "reserve" (object ID) opens the reservation dialog for that object.
	 *
	 * @param $args array
	 * @param $request PKPRequest
	 * @param $reserveState array Reservation form state after a failed submission (objectId, errors, input)
	 */
	public function index($args, $request, array $reserveState = []) {
		$context = $request->getContext();
		$contextId = $context->getId();
		$plugin = PluginRegistry::getPlugin('generic', 'ObjectsForReviewPlugin');
		$templateMgr = TemplateManager::getManager($request);
		$currentUser = $request->getUser();

		$objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
		$objectsForReview = $objectForReviewDao->getAll($contextId, true, true);
		$gridData = array();
		while ($objectForReview = $objectsForReview->next()) {
			$objectId = $objectForReview->getId();
			$gridData[$objectId] = array(
				'objectId' => $objectId,
				'identifierType' => $objectForReview->getIdentifierType(),
				'identifier' => $objectForReview->getIdentifier(),
				'identifierUrl' => $objectForReview->getIdentifierUrl(),
				'authors' => $objectForReview->getAuthors(),
				'title' => $objectForReview->getTitle(),
				'publisher' => $objectForReview->getPublisher(),
				'year' => $objectForReview->getYear(),
				'description' => $objectForReview->getDescription(),
				'userId' => $objectForReview->getUserId(),
				// Only shown to the reviewer who reserved the object
				'dueDate' => ($currentUser && $currentUser->getId() == $objectForReview->getUserId()) ? $objectForReview->getData('dueDate') : null,
			);
		}

		$ofrInstructions = (array) $plugin->getSetting($contextId, 'ofrInstructions');
		$instructions = ($ofrInstructions[Locale::getLocale()] ?? null) ?: ($ofrInstructions[$context->getPrimaryLocale()] ?? '');

		// Reservation dialog: opened after a failed submission or via ?reserve=<objectId>
		$openObjectId = (int) ($reserveState['objectId'] ?? $request->getUserVar('reserve'));
		if ($openObjectId && (!isset($gridData[$openObjectId]) || $gridData[$openObjectId]['userId'] || !$currentUser)) {
			$openObjectId = 0;
		}
		$dueDateRange = Reservation::getDueDateRange($plugin, $contextId);

		$templateMgr->assign([
			'objectsForReview' => $gridData,
			'currentUser' => $currentUser,
			'instructions' => $instructions,
			'reserveDialogTemplate' => $plugin->getTemplateResource('frontend/reserveDialog.tpl'),
			'reserveContract' => Reservation::getContract($plugin, $context),
			'reserveDueDate' => $dueDateRange,
			'reserveOpenObjectId' => $openObjectId,
			'reserveErrors' => $reserveState['errors'] ?? [],
			'reserveInput' => ($reserveState['input'] ?? []) + ['dueDate' => $dueDateRange['default'], 'copyPreference' => ''],
		]);
		return $templateMgr->display($plugin->getTemplateResource('frontend/pages/forReview.tpl'));
	}

	/**
	 * Reserve an object (submission of the reservation dialog).
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function reserveObject($args, $request) {
		if (!$request->checkCSRF()) fatalError('CSRF check failed, please try again!');
		if (!$request->getUser()) {
			Validation::redirectLogin();
		}
		$objectId = (int) $request->getUserVar('objectId');
		$currentUser = $request->getUser();
		$currentContext = $request->getContext();
		$plugin = PluginRegistry::getPlugin('generic', 'ObjectsForReviewPlugin');
		$objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
		$objectForReview = $objectForReviewDao->getById($objectId);
		if (!$objectForReview || $objectForReview->getUserId() || $objectForReview->getSubmissionId()
			|| $currentContext->getId() != $objectForReview->getContextId()) {
			$request->redirect(null, 'for-review');
		}

		$input = [];
		foreach (['acceptTerms', 'dueDate', 'copyPreference', 'shippingAddress', 'phone', 'copyNote'] as $name) {
			$input[$name] = $request->getUserVar($name);
		}
		[$errors, $data] = Reservation::validate($input, Reservation::getDueDateRange($plugin, $currentContext->getId()));
		if ($errors) {
			return $this->index($args, $request, ['objectId' => $objectId, 'errors' => $errors, 'input' => $input]);
		}

		Reservation::apply($objectForReview, $currentUser->getId(), $data);
		$objectForReviewDao->updateObject($objectForReview);
		ReservationLog::logEvent($objectForReview, ReservationLog::EVENT_RESERVED, $currentUser, $currentUser,
			['reviewDueDate' => $data['dueDate']]);
		$plugin->notifyEditor($currentUser, $objectForReview->getDescription(), 'OFR_NEW_RESERVATION',
			Reservation::getEmailDetails($objectForReview, $currentContext));
		$request->redirect(null, 'for-review');
	}

	/**
	 * Cancel the current user's reservation of an object.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function cancelObject($args, $request) {
		if (!$request->checkCSRF()) fatalError('CSRF check failed, please try again!');
		if (!$request->getUser()) {
			Validation::redirectLogin();
		}
		$objectId = (int) $request->getUserVar('objectId');
		$currentUser = $request->getUser();
		$currentContext = $request->getContext();
		$objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
		$objectForReview = $objectForReviewDao->getById($objectId);
		if ($objectForReview && $objectForReview->getUserId() == $currentUser->getId() && $currentContext->getId() == $objectForReview->getContextId()) {
			Reservation::clear($objectForReview);
			$objectForReviewDao->updateObject($objectForReview);
			ReservationLog::logEvent($objectForReview, ReservationLog::EVENT_CANCELLED_BY_REVIEWER, $currentUser, $currentUser);
			$plugin = PluginRegistry::getPlugin('generic', 'ObjectsForReviewPlugin');
			$plugin->notifyEditor($currentUser, $objectForReview->getDescription(), 'OFR_CANCEL_RESERVATION');
		}
		$request->redirect(null, 'for-review');
	}
}
