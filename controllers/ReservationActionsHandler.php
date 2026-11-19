<?php

/**
 * @file plugins/generic/objectsForReview/controllers/ReservationActionsHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationActionsHandler
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Modals of the backend Reservations page: email the reviewer, cancel
 *  the reservation (with a templated notification), and show the audit trail.
 */

namespace APP\plugins\generic\objectsForReview\controllers;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\objectsForReview\classes\ReservationLog;
use APP\plugins\generic\objectsForReview\classes\ReservationMailer;
use APP\plugins\generic\objectsForReview\controllers\form\ReservationEmailForm;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class ReservationActionsHandler extends Handler {

	/** Component name used in URLs */
	const COMPONENT = 'plugins.generic.objectsForReview.controllers.ReservationActionsHandler';

	/** @var \APP\plugins\generic\objectsForReview\ObjectsForReviewPlugin */
	public $plugin;

	public function __construct($plugin) {
		parent::__construct();
		$this->plugin = $plugin;
		$this->addRoleAssignment(
			[Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN],
			['emailForm', 'sendEmail', 'cancelForm', 'cancel', 'history']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	public function emailForm($args, $request) {
		return $this->showForm($request, ReservationEmailForm::MODE_MESSAGE);
	}

	public function sendEmail($args, $request) {
		return $this->saveForm($request, ReservationEmailForm::MODE_MESSAGE);
	}

	public function cancelForm($args, $request) {
		return $this->showForm($request, ReservationEmailForm::MODE_CANCEL);
	}

	public function cancel($args, $request) {
		return $this->saveForm($request, ReservationEmailForm::MODE_CANCEL);
	}

	/**
	 * Audit trail of an object (all reservations, past and current).
	 */
	public function history($args, $request) {
		$object = $this->getObject($request, false);
		if (!$object) {
			return new JSONMessage(false, __('user.authorization.accessDenied'));
		}
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'objectDescription' => $object->getDescription(),
			'history' => ReservationLog::getHistory($object),
		]);
		return new JSONMessage(true, $templateMgr->fetch($this->plugin->getTemplateResource('reservationHistory.tpl')));
	}

	protected function showForm($request, string $mode) {
		$object = $this->getObject($request);
		if (!$object) {
			return new JSONMessage(false, __('plugins.generic.objectsForReview.reservations.notReserved'));
		}
		ReservationMailer::ensureTemplates($this->plugin, $request->getContext()->getId());
		$form = new ReservationEmailForm($this->plugin, $request->getContext(), $object, $mode);
		$form->initData();
		return new JSONMessage(true, $form->fetch($request));
	}

	protected function saveForm($request, string $mode) {
		$object = $this->getObject($request);
		if (!$object || !$request->checkCSRF()) {
			return new JSONMessage(false, __('plugins.generic.objectsForReview.reservations.notReserved'));
		}
		$form = new ReservationEmailForm($this->plugin, $request->getContext(), $object, $mode);
		$form->readInputData();
		if (!$form->validate()) {
			return new JSONMessage(true, $form->fetch($request));
		}
		$form->execute();
		return DAO::getDataChangedEvent($object->getId());
	}

	/**
	 * The requested object of the current journal.
	 * @param bool $reservedOnly Only return objects that are currently reserved
	 */
	protected function getObject($request, bool $reservedOnly = true) {
		$object = DAORegistry::getDAO('ObjectForReviewDAO')->getById((int) $request->getUserVar('objectId'));
		if (!$object || $object->getContextId() != $request->getContext()->getId()) {
			return null;
		}
		if ($reservedOnly && (!$object->getUserId() || $object->getSubmissionId() || !Repo::user()->get((int) $object->getUserId(), true))) {
			return null;
		}
		return $object;
	}
}
