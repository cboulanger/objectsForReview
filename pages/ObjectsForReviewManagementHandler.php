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
use APP\plugins\generic\objectsForReview\classes\Assignment;
use APP\plugins\generic\objectsForReview\classes\AssignmentWorkflow;
use APP\plugins\generic\objectsForReview\classes\CopyWorkflow;
use APP\plugins\generic\objectsForReview\controllers\form\CopyStepForm;
use APP\plugins\generic\objectsForReview\classes\Reservation;
use APP\plugins\generic\objectsForReview\classes\Roles;
use PKP\linkAction\request\RemoteActionConfirmationModal;
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
			['index', 'desk', 'assignments', 'reservations', 'reviewedWorks', 'settings']
		);
		$this->addRoleAssignment(
			[Role::ROLE_ID_SUB_EDITOR],
			['index', 'desk', 'assignments', 'reservations', 'reviewedWorks']
		);
	}

	/**
	 * @copydoc PKPHandler::authorize()
	 * Section editors need to be book review editors.
	 */
	public function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
		if (!parent::authorize($request, $args, $roleAssignments)) {
			return false;
		}
		$userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
		return Roles::canManage($this->plugin, $request->getContext()->getId(), $request->getUser()?->getId(), $userRoles);
	}

	/**
	 * Available objects: the management grid (formerly under Settings > Website).
	 */
	public function index($args, $request) {
		Roles::ensureGroups($this->plugin, $request->getContext()->getId());
		$gridUrl = $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
			'plugins.generic.objectsForReview.controllers.grid.ObjectsForReviewManagementGridHandler', 'fetchGrid');
		return $this->display($request, 'available', 'plugins.generic.objectsForReview.menu.available', ['gridUrl' => $gridUrl]);
	}

	/**
	 * Old URL of the assignments page.
	 */
	public function reservations($args, $request) {
		$request->redirect(null, null, 'assignments');
	}

	/**
	 * Desk: what needs the book review editor's attention.
	 */
	public function desk($args, $request) {
		$context = $request->getContext();
		$contextId = $context->getId();
		$assignmentsByObject = AssignmentWorkflow::dao()->getByContextId($contextId);
		$selection = $offers = $unsuccessful = $closed = $copies = $notifications = [];
		$userCache = [];

		foreach ($this->getObjects($request) as $object) {
			$assignments = $assignmentsByObject[$object->getId()] ?? [];
			$state = AssignmentWorkflow::getState($object, $assignments);
			$bidders = AssignmentWorkflow::filter($assignments, Assignment::BIDDER_STATUSES);
			$row = $this->objectRow($object);

			if ($state === AssignmentWorkflow::STATE_CLOSED) {
				$closed[] = $row + ['offerActions' => $this->offerActions($request, $object, ['reopenOffer'])];
				continue;
			}

			// Selection: bids to decide on, or a closed bidding round without a reviewer
			if ($object->isBidding() && in_array($state, [AssignmentWorkflow::STATE_OPEN, AssignmentWorkflow::STATE_SELECTION], true)
				&& ($bidders || $state === AssignmentWorkflow::STATE_SELECTION)) {
				$dropouts = AssignmentWorkflow::filter($assignments, Assignment::DROPOUT_STATUSES);
				$lastDropout = $dropouts ? end($dropouts) : null;
				$selection[] = $row + [
					'state' => $state,
					'biddingOpen' => AssignmentWorkflow::isBiddingOpen($object, $assignments),
					'bidDeadline' => $object->getData('bidDeadline'),
					'deadlinePassed' => AssignmentWorkflow::isBidDeadlinePassed($object),
					'dropout' => $lastDropout ? [
						'name' => $this->userName($lastDropout->getUserId(), $userCache),
						'status' => $lastDropout->getStatus(),
						'date' => $lastDropout->getDateStatus(),
						'reason' => $lastDropout->getData('declineReason'),
					] : null,
					'bidders' => array_map(fn ($a) => $this->bidderRow($request, $object, $a, $userCache), $bidders),
					'offerActions' => $this->offerActions($request, $object, array_merge(
						$state === AssignmentWorkflow::STATE_SELECTION ? ['reopenBidding'] : [],
						['switchToDirect', 'closeOffer']
					)),
					'historyAction' => $this->assignmentActions($request, $object, null, ['history'])['history'],
				];
			}

			foreach (AssignmentWorkflow::filter($assignments, [Assignment::STATUS_OFFERED]) as $assignment) {
				$offers[] = $row + [
					'user' => $this->userName($assignment->getUserId(), $userCache),
					'dateOffered' => $assignment->getData('dateOffered'),
					'contractDeadline' => $assignment->getData('contractDeadline'),
					'contractReminderSent' => $assignment->getData('contractReminderSent'),
					'actions' => $this->assignmentActions($request, $object, $assignment, ['email', 'cancel', 'history']),
				];
			}

			foreach ($assignments as $assignment) {
				if (CopyWorkflow::isOpen($object, $assignment)) {
					$copies[] = $row + $this->copyRow($request, $object, $assignment, $userCache);
				}
				if (ReservationActionsHandler::canNotifyPublisher($object, $assignment)) {
					$notifications[] = $row + [
						'reviewer' => $this->userName($assignment->getUserId(), $userCache),
						'publisherContact' => CopyWorkflow::getPublisherContact($object)?->getFullName(),
						'action' => $this->linkAction($request, 'copyStepForm',
							['objectId' => $object->getId(), 'assignmentId' => $assignment->getId(), 'step' => CopyStepForm::NOTIFY_PUBLISHER],
							'plugins.generic.objectsForReview.copy.step.notifyPublisher', 'modal_email', 'notify-' . $assignment->getId()),
					];
				}
			}

			$remaining = AssignmentWorkflow::filter($assignments, [Assignment::STATUS_BID]);
			if ($remaining && in_array($state, [AssignmentWorkflow::STATE_ASSIGNED, AssignmentWorkflow::STATE_SUBMITTED], true)) {
				$reviewer = AssignmentWorkflow::filter($assignments, Assignment::REVIEWER_STATUSES);
				$unsuccessful[] = $row + [
					'reviewer' => $reviewer ? $this->userName(end($reviewer)->getUserId(), $userCache) : '',
					'names' => array_map(fn ($a) => $this->userName($a->getUserId(), $userCache), $remaining),
					'action' => $this->linkAction($request, 'unsuccessfulForm', ['objectId' => $object->getId()],
						'plugins.generic.objectsForReview.unsuccessful.action', 'modal_email', 'unsuccessful-' . $object->getId()),
				];
			}
		}

		return $this->display($request, 'desk', 'plugins.generic.objectsForReview.menu.desk', [
			'selection' => $selection,
			'offers' => $offers,
			'unsuccessful' => $unsuccessful,
			'closed' => $closed,
			'copies' => $copies,
			'notifications' => $notifications,
		]);
	}

	/**
	 * Assignments: all bidders and reviewers, filterable by status and person.
	 */
	public function assignments($args, $request) {
		$contextId = $request->getContext()->getId();
		$statusFilter = (string) ($request->getUserVar('status') ?? 'active');
		$filterUserId = (int) $request->getUserVar('userId');
		$statuses = match ($statusFilter) {
			'active' => [Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED],
			'all' => null,
			default => [$statusFilter],
		};
		$objects = [];
		foreach ($this->getObjects($request) as $object) {
			$objects[$object->getId()] = $object;
		}
		$rows = [];
		$users = [];
		$userCache = [];
		foreach (AssignmentWorkflow::dao()->getByContextId($contextId, $statuses) as $objectId => $assignments) {
			if (!isset($objects[$objectId])) {
				continue;
			}
			foreach ($assignments as $assignment) {
				$userId = $assignment->getUserId();
				$users[$userId] = $this->userName($userId, $userCache);
				if ($filterUserId && $filterUserId !== $userId) {
					continue;
				}
				$active = in_array($assignment->getStatus(), [Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED], true);
				$rows[] = $this->objectRow($objects[$objectId]) + [
					'copyStatusLabel' => CopyWorkflow::getStatusLabel($assignment),
					'copyActions' => $this->copyActions($request, $objects[$objectId], $assignment),
					'user' => $users[$userId],
					'status' => $assignment->getStatus(),
					'dateCreated' => $assignment->getDateCreated(),
					'dateStatus' => $assignment->getDateStatus(),
					'contractDeadline' => $assignment->getStatus() === Assignment::STATUS_OFFERED ? $assignment->getData('contractDeadline') : null,
					'actions' => $this->assignmentActions($request, $objects[$objectId], $assignment, $active ? ['email', 'cancel', 'history'] : ['history']),
				] + Reservation::getDisplayData($assignment);
			}
		}
		asort($users);
		usort($rows, fn ($a, $b) => strcmp((string) ($a['dueDate'] ?: $a['contractDeadline'] ?: '9999'), (string) ($b['dueDate'] ?: $b['contractDeadline'] ?: '9999'))
			?: strcmp((string) $b['dateStatus'], (string) $a['dateStatus']));
		return $this->display($request, 'assignments', 'plugins.generic.objectsForReview.menu.assignments', [
			'rows' => $rows,
			'users' => $users,
			'filterUserId' => $filterUserId,
			'statusFilter' => $statusFilter,
			'statusOptions' => array_merge(['active', 'all'], [
				Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL, Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED,
				Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED, Assignment::STATUS_WITHDRAWN, Assignment::STATUS_DECLINED,
				Assignment::STATUS_EXPIRED, Assignment::STATUS_RETRACTED, Assignment::STATUS_CANCELLED, Assignment::STATUS_DISCARDED,
			]),
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
	 * Modal actions for an assignment (or, without one, for the object): email, cancel, audit trail.
	 * @return LinkAction[]
	 */
	protected function assignmentActions($request, $object, ?Assignment $assignment, array $names): array {
		$definitions = [
			'email' => ['emailForm', 'plugins.generic.objectsForReview.reservations.email', 'modal_email'],
			'cancel' => ['cancelForm', $assignment?->getStatus() === Assignment::STATUS_OFFERED
				? 'plugins.generic.objectsForReview.contractOffer.cancel' : 'plugins.generic.objectsForReview.reservations.cancel', 'modal_delete'],
			'history' => ['history', 'plugins.generic.objectsForReview.history', 'modal_information'],
		];
		$actions = [];
		foreach ($names as $name) {
			[$op, $labelKey, $modalClass] = $definitions[$name];
			$params = ['objectId' => $object->getId()] + ($assignment && $name !== 'history' ? ['assignmentId' => $assignment->getId()] : []);
			$actions[$name] = $this->linkAction($request, $op, $params, $labelKey, $modalClass,
				$name . '-' . $object->getId() . '-' . ($assignment?->getId() ?? 0));
		}
		return $actions;
	}

	/**
	 * Offer-level actions confirmed in a dialog.
	 * @return LinkAction[]
	 */
	protected function offerActions($request, $object, array $names): array {
		$actions = [];
		foreach ($names as $name) {
			$url = $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
				ReservationActionsHandler::COMPONENT, $name, null, ['objectId' => $object->getId()]);
			$label = __('plugins.generic.objectsForReview.desk.' . $name);
			$actions[$name] = new LinkAction('ofr-' . $name . '-' . $object->getId(),
				new RemoteActionConfirmationModal($request->getSession(), __('plugins.generic.objectsForReview.desk.' . $name . '.confirm'),
					$label, $url, 'modal_confirm'),
				$label);
		}
		return $actions;
	}

	protected function linkAction($request, string $op, array $params, string $labelKey, string $modalClass, string $id): LinkAction {
		$url = $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
			ReservationActionsHandler::COMPONENT, $op, null, $params);
		return new LinkAction('ofr-' . $id, new AjaxModal($url, __($labelKey), $modalClass), __($labelKey));
	}

	/**
	 * A review copy as shown on the To-do page.
	 */
	protected function copyRow($request, $object, Assignment $assignment, array &$userCache): array {
		$contact = CopyWorkflow::getPublisherContact($object);
		return [
			'reviewer' => $this->userName($assignment->getUserId(), $userCache),
			'assignmentStatus' => $assignment->getStatus(),
			'copyPreferenceLabel' => __('plugins.generic.objectsForReview.reserve.copy.' . $assignment->getData('copyPreference')),
			'copyStatusLabel' => CopyWorkflow::getStatusLabel($assignment),
			'copyStatusDate' => $assignment->getData('copyStatusDate'),
			'journalHoldsCopy' => (bool) $object->getData('journalHoldsCopy'),
			'publisherContact' => $contact ? $contact->getFullName() . ($contact->getLocalizedData('affiliation') ? ', ' . $contact->getLocalizedData('affiliation') : '') : null,
			'copyActions' => $this->copyActions($request, $object, $assignment),
			'historyAction' => $this->assignmentActions($request, $object, null, ['history'])['history'],
		];
	}

	/**
	 * The review copy steps the editor can take now.
	 * @return LinkAction[]
	 */
	protected function copyActions($request, $object, Assignment $assignment): array {
		$actions = [];
		foreach (CopyWorkflow::getAllowedSteps($this->plugin, $object, $assignment) as $step) {
			$actions[$step] = $this->linkAction($request, 'copyStepForm',
				['objectId' => $object->getId(), 'assignmentId' => $assignment->getId(), 'step' => $step],
				'plugins.generic.objectsForReview.copy.step.' . $step, 'modal_edit', $step . '-' . $assignment->getId());
		}
		return $actions;
	}

	/**
	 * A bidder as shown when choosing among bidders.
	 */
	protected function bidderRow($request, $object, Assignment $assignment, array &$userCache): array {
		$user = Repo::user()->get($assignment->getUserId(), true);
		return [
			'assignmentId' => $assignment->getId(),
			'name' => $user ? $user->getFullName() : '#' . $assignment->getUserId(),
			'email' => $user?->getEmail(),
			'affiliation' => $user?->getLocalizedData('affiliation'),
			'date' => $assignment->getDateCreated(),
			'status' => $assignment->getStatus(),
			'statement' => $assignment->getData('statement'),
			'reviewCount' => AssignmentWorkflow::dao()->countReviewsByUser($assignment->getUserId(), $object->getContextId()),
			'selectAction' => $this->linkAction($request, 'contractOfferForm',
				['objectId' => $object->getId(), 'assignmentId' => $assignment->getId()],
				'plugins.generic.objectsForReview.desk.select', 'modal_email', 'select-' . $assignment->getId()),
		];
	}

	protected function userName(int $userId, array &$cache): string {
		if (!isset($cache[$userId])) {
			$user = Repo::user()->get($userId, true);
			$cache[$userId] = $user ? $user->getFullName() . ' (' . $user->getEmail() . ')' : '#' . $userId;
		}
		return $cache[$userId];
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
