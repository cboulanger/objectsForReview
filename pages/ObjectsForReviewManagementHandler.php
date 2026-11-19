<?php

/**
 * @file plugins/generic/objectsForReview/pages/ObjectsForReviewManagementHandler.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewManagementHandler
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Backend pages of the plugin (left menu section "Objects for Review").
 */

namespace APP\plugins\generic\objectsForReview\pages;

use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\objectsForReview\classes\Reservation;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use APP\plugins\generic\objectsForReview\controllers\ReservationActionsHandler;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class ObjectsForReviewManagementHandler extends Handler {

	/** Page name used in URLs */
	const PAGE = 'manageObjectsForReview';

	/** @copydoc PKPHandler::_isBackendPage */
	public $_isBackendPage = true;

	/** @var \APP\plugins\generic\objectsForReview\ObjectsForReviewPlugin */
	public $plugin;

	public function __construct($plugin) {
		parent::__construct();
		$this->plugin = $plugin;
		$this->addRoleAssignment(
			[Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN],
			['index', 'reservations', 'reviewedWorks', 'settings']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		return parent::authorize($request, $args, $roleAssignments);
	}

	/**
	 * Available objects: the management grid (formerly under Settings > Website).
	 */
	public function index($args, $request) {
		$gridUrl = $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
			'plugins.generic.objectsForReview.controllers.grid.ObjectsForReviewManagementGridHandler', 'fetchGrid');
		return $this->display($request, 'available', 'plugins.generic.objectsForReview.menu.available', ['gridUrl' => $gridUrl]);
	}

	/**
	 * Reservations: objects reserved by a user and not yet attached to a submission.
	 */
	public function reservations($args, $request) {
		$filterUserId = (int) $request->getUserVar('userId');
		$rows = [];
		$users = [];
		foreach ($this->getObjects($request) as $object) {
			if (!$object->getUserId() || $object->getSubmissionId()) {
				continue;
			}
			$userId = (int) $object->getUserId();
			if (!isset($users[$userId])) {
				$user = Repo::user()->get($userId, true);
				$users[$userId] = $user ? $user->getFullName() . ' (' . $user->getEmail() . ')' : '#' . $userId;
			}
			if ($filterUserId && $filterUserId !== $userId) {
				continue;
			}
			$rows[] = $this->objectRow($object) + ['user' => $users[$userId], 'actions' => $this->reservationActions($request, $object)]
				+ Reservation::getDisplayData($object);
		}
		asort($users);
		usort($rows, fn ($a, $b) => strcmp((string) $a['dueDate'], (string) $b['dueDate']));
		return $this->display($request, 'reservations', 'plugins.generic.objectsForReview.menu.reservations', [
			'rows' => $rows,
			'users' => $users,
			'filterUserId' => $filterUserId,
		]);
	}

	/**
	 * Reviewed works: all objects attached to a submission.
	 */
	public function reviewedWorks($args, $request) {
		$dispatcher = $request->getDispatcher();
		$rows = [];
		foreach ($this->getObjects($request) as $object) {
			if (!$object->getSubmissionId()) {
				continue;
			}
			$submission = Repo::submission()->get((int) $object->getSubmissionId());
			$publication = $submission ? $submission->getCurrentPublication() : null;
			$rows[] = $this->objectRow($object) + [
				'submissionId' => $object->getSubmissionId(),
				'submissionTitle' => $publication ? $publication->getLocalizedFullTitle() : null,
				'submissionStatus' => $submission ? __($submission->getStatusKey()) : null,
				'submissionUrl' => $submission ? $dispatcher->url($request, Application::ROUTE_PAGE, null,
					'dashboard', 'editorial', null, ['workflowSubmissionId' => $submission->getId()]) : null,
			];
		}
		usort($rows, fn ($a, $b) => $b['submissionId'] <=> $a['submissionId']);
		return $this->display($request, 'reviewedWorks', 'plugins.generic.objectsForReview.menu.reviewedWorks', ['rows' => $rows]);
	}

	/**
	 * Settings: the plugin settings form, loaded from the plugin settings component.
	 */
	public function settings($args, $request) {
		$settingsUrl = $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
			'grid.settings.plugins.SettingsPluginGridHandler', 'manage', null,
			['verb' => 'settings', 'plugin' => $this->plugin->getName(), 'category' => 'generic', 'standalone' => 1]);
		return $this->display($request, 'settings', 'plugins.generic.objectsForReview.menu.settings', ['settingsUrl' => $settingsUrl]);
	}

	/**
	 * Modal actions for a reservation: email the reviewer, cancel, show the audit trail.
	 * @return LinkAction[]
	 */
	protected function reservationActions($request, $object): array {
		$actions = [];
		foreach ([
			'email' => ['emailForm', 'plugins.generic.objectsForReview.reservations.email', 'modal_email'],
			'cancel' => ['cancelForm', 'plugins.generic.objectsForReview.reservations.cancel', 'modal_delete'],
			'history' => ['history', 'plugins.generic.objectsForReview.history', 'modal_information'],
		] as $name => [$op, $labelKey, $modalClass]) {
			$url = $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
				ReservationActionsHandler::COMPONENT, $op, null, ['objectId' => $object->getId()]);
			$actions[$name] = new LinkAction('ofr-' . $name . '-' . $object->getId(),
				new AjaxModal($url, __($labelKey), $modalClass), __($labelKey));
		}
		return $actions;
	}

	/**
	 * @return \APP\plugins\generic\objectsForReview\classes\ObjectForReview[]
	 */
	protected function getObjects($request) {
		$objects = DAORegistry::getDAO('ObjectForReviewDAO')->getAll($request->getContext()->getId());
		return $objects->toArray();
	}

	protected function objectRow($object) {
		return [
			'objectId' => $object->getId(),
			'description' => $object->getDescription(),
			'identifierType' => $object->getIdentifierType(),
			'identifier' => $object->getIdentifier(),
			'identifierUrl' => $object->getIdentifierUrl(),
		];
	}

	protected function display($request, $view, $titleKey, array $data) {
		$this->setupTemplate($request);
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign($data + [
			'view' => $view,
			'pageTitle' => __($titleKey),
			'ofrObjectCell' => $this->plugin->getTemplateResource('managementObjectCell.tpl'),
		]);
		return $templateMgr->display($this->plugin->getTemplateResource('management.tpl'));
	}
}
