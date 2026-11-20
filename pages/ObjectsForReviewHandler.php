<?php

/**
 * @file plugins/generic/objectsForReview/pages/ObjectsForReviewHandler.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewHandler
 * @ingroup plugins_generic_objectsForReview
 * @brief Reader-facing pages: the list of offers (reserve, bid, withdraw,
 *  cancel), the contract page for selected bidders and the one-click links.
 */

namespace APP\plugins\generic\objectsForReview\pages;

use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\objectsForReview\classes\ActionLink;
use APP\plugins\generic\objectsForReview\classes\Assignment;
use APP\plugins\generic\objectsForReview\classes\AssignmentWorkflow;
use APP\plugins\generic\objectsForReview\classes\CopyWorkflow;
use APP\plugins\generic\objectsForReview\classes\Reservation;
use APP\plugins\generic\objectsForReview\classes\ReservationEmailEventType;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextRequiredPolicy;
use PKP\security\Validation;

class ObjectsForReviewHandler extends Handler {

	const CONTRACT_INPUT = ['acceptTerms', 'dueDate', 'copyPreference', 'shippingAddress', 'phone', 'copyNote'];
	const MAX_STATEMENT_LENGTH = 2000;

	/**
	 * @copydoc PKPHandler::authorize()
	 */
	function authorize($request, &$args, $roleAssignments) {
		$this->addPolicy(new ContextRequiredPolicy($request));
		return parent::authorize($request, $args, $roleAssignments);
	}

	protected function plugin() {
		return PluginRegistry::getPlugin('generic', 'ObjectsForReviewPlugin');
	}

	/**
	 * View objects for review
	 *
	 * Request var "reserve" (object ID) opens the reservation dialog for that object.
	 *
	 * @param $reserveState array Reservation form state after a failed submission (objectId, errors, input)
	 */
	public function index($args, $request, array $reserveState = []) {
		$context = $request->getContext();
		$contextId = $context->getId();
		$plugin = $this->plugin();
		$templateMgr = TemplateManager::getManager($request);
		$currentUser = $request->getUser();

		$assignmentsByObject = AssignmentWorkflow::dao()->getByContextId($contextId);
		$objectsForReview = DAORegistry::getDAO('ObjectForReviewDAO')->getAll($contextId, true, true);
		$gridData = [];
		while ($objectForReview = $objectsForReview->next()) {
			if ($objectForReview->getData('offerClosed')) {
				continue;
			}
			$objectId = $objectForReview->getId();
			$assignments = $assignmentsByObject[$objectId] ?? [];
			$mine = null;
			if ($currentUser) {
				foreach (array_reverse($assignments) as $assignment) {
					if ($assignment->getUserId() == $currentUser->getId()) {
						$mine = $assignment;
						break;
					}
				}
			}
			$myStatus = $mine?->getStatus();
			$myActiveStatus = in_array($myStatus, [Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL, Assignment::STATUS_OFFERED, Assignment::STATUS_ACCEPTED], true) ? $myStatus : null;
			// Hidden offers are only listed for the people who have a bid, offer or reservation
			$hidden = $objectForReview->isHidden();
			if ($hidden && !$myActiveStatus) {
				continue;
			}
			$gridData[$objectId] = [
				'objectId' => $objectId,
				'identifierType' => $objectForReview->getIdentifierType(),
				'identifier' => $objectForReview->getIdentifier(),
				'identifierUrl' => $objectForReview->getIdentifierUrl(),
				'authors' => $objectForReview->getAuthors(),
				'title' => $objectForReview->getTitle(),
				'publisher' => $objectForReview->getPublisher(),
				'year' => $objectForReview->getYear(),
				'description' => $objectForReview->getDescription(),
				'state' => AssignmentWorkflow::getState($objectForReview, $assignments),
				'bidding' => $objectForReview->isBidding(),
				'biddingOpen' => !$hidden && AssignmentWorkflow::isBiddingOpen($objectForReview, $assignments),
				'bidDeadline' => $objectForReview->getData('bidDeadline'),
				'reservable' => !$hidden && AssignmentWorkflow::isReservable($objectForReview, $assignments),
				// Only shown to the user concerned
				'myStatus' => $myActiveStatus,
				'myDate' => $mine?->getDateCreated(),
				'dueDate' => $myStatus === Assignment::STATUS_ACCEPTED ? $mine->getData('dueDate') : null,
				'contractDeadline' => $myStatus === Assignment::STATUS_OFFERED ? $mine->getData('contractDeadline') : null,
				'copyStatus' => $myStatus === Assignment::STATUS_ACCEPTED ? CopyWorkflow::getStatus($mine) : null,
			];
		}

		$ofrInstructions = (array) $plugin->getSetting($contextId, 'ofrInstructions');
		$instructions = ($ofrInstructions[Locale::getLocale()] ?? null) ?: ($ofrInstructions[$context->getPrimaryLocale()] ?? '');

		// Reservation dialog: opened after a failed submission or via ?reserve=<objectId>
		$openObjectId = (int) ($reserveState['objectId'] ?? $request->getUserVar('reserve'));
		if ($openObjectId && (!isset($gridData[$openObjectId]) || !$gridData[$openObjectId]['reservable'] || !$currentUser)) {
			$openObjectId = 0;
		}
		$templateMgr->assign($this->contractTemplateData($request, $reserveState) + [
			'objectsForReview' => $gridData,
			'currentUser' => $currentUser,
			'instructions' => $instructions,
			'reserveDialogTemplate' => $plugin->getTemplateResource('frontend/reserveDialog.tpl'),
			'reserveOpenObjectId' => $openObjectId,
			'maxStatementLength' => self::MAX_STATEMENT_LENGTH,
		]);
		return $templateMgr->display($plugin->getTemplateResource('frontend/pages/forReview.tpl'));
	}

	/**
	 * Reserve an object (direct reservation mode; submission of the reservation dialog).
	 */
	function reserveObject($args, $request) {
		[$object, $user, $context] = $this->requirePost($request);
		$assignments = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		if ($object->isHidden() || !AssignmentWorkflow::isReservable($object, $assignments)) {
			$request->redirect(null, 'for-review');
		}
		$input = $this->contractInput($request);
		[$errors, $data] = Reservation::validate($input, Reservation::getDueDateRange($this->plugin(), $context->getId()));
		if ($errors) {
			return $this->index($args, $request, ['objectId' => $object->getId(), 'errors' => $errors, 'input' => $input]);
		}
		AssignmentWorkflow::reserve($this->plugin(), $context, $object, $user, $data);
		$request->redirect(null, 'for-review');
	}

	/**
	 * The reviewer cancels their reservation (retracts).
	 */
	function cancelObject($args, $request) {
		[$object, $user] = $this->requirePost($request);
		$assignment = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(), [Assignment::STATUS_ACCEPTED]);
		if ($assignment) {
			AssignmentWorkflow::retract($this->plugin(), $object, $assignment, $user);
		}
		$request->redirect(null, 'for-review');
	}

	/**
	 * The reviewer confirms that the review copy has arrived.
	 */
	function confirmReceipt($args, $request) {
		[$object, $user, $context] = $this->requirePost($request);
		$assignment = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(), [Assignment::STATUS_ACCEPTED]);
		if ($assignment && CopyWorkflow::getStatus($assignment) === CopyWorkflow::SENT) {
			CopyWorkflow::confirmReceipt($this->plugin(), $context, $object, $assignment, $user);
		}
		$request->redirect(null, 'for-review');
	}

	/**
	 * Bid for an object (bidding mode), with an optional statement.
	 */
	function bid($args, $request) {
		[$object, $user, $context] = $this->requirePost($request);
		$assignments = AssignmentWorkflow::dao()->getByObjectId($object->getId());
		$hasBid = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(),
			array_merge(Assignment::BIDDER_STATUSES, [Assignment::STATUS_OFFERED], Assignment::REVIEWER_STATUSES));
		if (!$object->isHidden() && AssignmentWorkflow::isBiddingOpen($object, $assignments) && !$hasBid) {
			$statement = mb_substr(trim((string) $request->getUserVar('statement')), 0, self::MAX_STATEMENT_LENGTH);
			AssignmentWorkflow::bid($this->plugin(), $context, $object, $user, $statement);
		}
		$request->redirect(null, 'for-review');
	}

	/**
	 * Withdraw one's bid (also from the list of unsuccessful bidders).
	 */
	function withdrawBid($args, $request) {
		[$object, $user] = $this->requirePost($request);
		$assignment = AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $user->getId(), Assignment::BIDDER_STATUSES);
		if ($assignment) {
			AssignmentWorkflow::withdrawBid($object, $assignment, $user);
		}
		$request->redirect(null, 'for-review');
	}

	/**
	 * Contract page for a logged-in bidder who has been selected (alternative to the email link).
	 */
	function contract($args, $request) {
		if (!$request->getUser()) {
			Validation::redirectLogin();
		}
		$object = $this->getObject($request, (int) $request->getUserVar('objectId'));
		$assignment = $object ? AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $request->getUser()->getId(), [Assignment::STATUS_OFFERED]) : null;
		return $this->showResponsePage($request, $assignment ? 'contract' : 'invalid', $object, $assignment);
	}

	/**
	 * One-click link from an email: shows what will happen and a confirmation button.
	 * Opening the link never changes anything.
	 */
	function link($args, $request) {
		[$link, $assignment, $object] = $this->resolveLink($request, (string) ($args[0] ?? ''));
		if (!$link) {
			return $this->showResponsePage($request, 'invalid');
		}
		$view = match ($link->action) {
			ActionLink::ACTION_CONTRACT => 'contract',
			ActionLink::ACTION_LEAVE => 'leave',
			default => 'confirm',
		};
		return $this->showResponsePage($request, $view, $object, $assignment, ['key' => $args[0], 'linkAction' => $link->action]);
	}

	/**
	 * Answer to a contract offer (from the link page or the logged-in contract page).
	 */
	function respond($args, $request) {
		if (!$request->isPost() || !$request->checkCSRF()) {
			$request->redirect(null, 'for-review');
		}
		$context = $request->getContext();
		$key = (string) $request->getUserVar('key');
		$link = null;
		if ($key !== '') {
			[$link, $assignment, $object] = $this->resolveLink($request, $key);
			if (!$link) {
				return $this->showResponsePage($request, 'invalid');
			}
		} else {
			if (!$request->getUser()) {
				Validation::redirectLogin();
			}
			$object = $this->getObject($request, (int) $request->getUserVar('objectId'));
			$assignment = $object ? AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $request->getUser()->getId(),
				[Assignment::STATUS_OFFERED, Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL]) : null;
		}
		if (!$assignment) {
			return $this->showResponsePage($request, 'invalid');
		}
		$extra = $key !== '' ? ['key' => $key] : [];
		$decision = (string) $request->getUserVar('decision');

		if ($link && in_array($link->action, array_keys(self::COPY_LINK_STEPS), true) || $link?->action === ActionLink::ACTION_RETRACT) {
			return $this->confirmLink($request, $link, $object, $assignment);
		}
		if ($decision === 'leave' && $assignment->isBidder()) {
			AssignmentWorkflow::withdrawBid($object, $assignment, Repo::user()->get($assignment->getUserId(), true));
			$link && ActionLink::markUsed((int) $link->link_id);
			return $this->showResponsePage($request, 'left', $object, $assignment);
		}
		if ($assignment->getStatus() !== Assignment::STATUS_OFFERED) {
			return $this->showResponsePage($request, 'invalid');
		}
		if ($decision === 'decline') {
			$reason = mb_substr(trim((string) $request->getUserVar('declineReason')), 0, self::MAX_STATEMENT_LENGTH);
			AssignmentWorkflow::declineContract($this->plugin(), $context, $object, $assignment, $reason);
			return $this->showResponsePage($request, 'declined', $object, $assignment);
		}
		$input = $this->contractInput($request);
		[$errors, $data] = Reservation::validate($input, Reservation::getDueDateRange($this->plugin(), $context->getId()));
		if ($errors) {
			return $this->showResponsePage($request, 'contract', $object, $assignment, $extra, ['errors' => $errors, 'input' => $input]);
		}
		AssignmentWorkflow::acceptContract($this->plugin(), $context, $object, $assignment, $data);
		return $this->showResponsePage($request, 'accepted', $object, $assignment);
	}

	/** Copy steps taken through one-click links: link action => [copy step, who uses the link] */
	const COPY_LINK_STEPS = [
		ActionLink::ACTION_COPY_SENT => ['publisherSent', 'publisher'],
		ActionLink::ACTION_RECEIVED => ['markReceived', 'reviewer'],
		ActionLink::ACTION_RETURN_SENT => ['returnSent', 'reviewer'],
		ActionLink::ACTION_RETURN_RECEIVED => ['returned', 'publisher'],
	];

	/**
	 * Confirmation of a copy step or retraction through a one-click link.
	 */
	protected function confirmLink($request, object $link, $object, Assignment $assignment) {
		$plugin = $this->plugin();
		$context = $request->getContext();
		$reviewer = Repo::user()->get($assignment->getUserId(), true);
		ActionLink::markUsed((int) $link->link_id);
		if ($link->action === ActionLink::ACTION_RETRACT) {
			AssignmentWorkflow::retract($plugin, $object, $assignment, $reviewer, true);
			return $this->showResponsePage($request, 'done', $object, $assignment, ['linkAction' => $link->action]);
		}
		[$step, $who] = self::COPY_LINK_STEPS[$link->action];
		$actor = $who === 'publisher' ? CopyWorkflow::getPublisherContact($object) : $reviewer;
		CopyWorkflow::apply($plugin, $context, $object, $assignment, $step, $actor, [], true);
		// Tell the next person, with their one-click link
		if ($step === 'publisherSent' && $reviewer) {
			CopyWorkflow::sendAutomatic($plugin, $context, $object, $assignment, ReservationEmailEventType::COPY_SENT, $reviewer, $step);
		}
		if ($step === 'returnSent' && $assignment->getData('returnTarget') === CopyWorkflow::BY_PUBLISHER
				&& ($contact = CopyWorkflow::getPublisherContact($object))) {
			CopyWorkflow::sendAutomatic($plugin, $context, $object, $assignment, ReservationEmailEventType::RETURN_SENT, $contact, $step);
		}
		return $this->showResponsePage($request, 'done', $object, $assignment, ['linkAction' => $link->action]);
	}

	//
	// Helpers
	//

	/**
	 * @return array [link row, Assignment, ObjectForReview] or [null, null, null]
	 */
	protected function resolveLink($request, string $key): array {
		$link = ActionLink::find($key);
		$assignment = $link ? AssignmentWorkflow::dao()->getById((int) $link->assignment_id) : null;
		$object = $assignment ? $this->getObject($request, $assignment->getObjectId()) : null;
		if (!$object) {
			return [null, null, null];
		}
		$copyStatus = CopyWorkflow::getStatus($assignment);
		$valid = match ($link->action) {
			ActionLink::ACTION_CONTRACT => $assignment->getStatus() === Assignment::STATUS_OFFERED,
			ActionLink::ACTION_LEAVE => $assignment->isBidder(),
			ActionLink::ACTION_RETRACT => $assignment->getStatus() === Assignment::STATUS_ACCEPTED,
			ActionLink::ACTION_COPY_SENT => $copyStatus === CopyWorkflow::REQUESTED && $object->getPublisherContactId(),
			ActionLink::ACTION_RECEIVED => $copyStatus === CopyWorkflow::SENT,
			ActionLink::ACTION_RETURN_SENT => $copyStatus === CopyWorkflow::RETURN_REQUESTED,
			ActionLink::ACTION_RETURN_RECEIVED => in_array($copyStatus, [CopyWorkflow::RETURN_REQUESTED, CopyWorkflow::RETURN_SENT], true)
				&& $assignment->getData('returnTarget') === CopyWorkflow::BY_PUBLISHER && $object->getPublisherContactId(),
			default => false,
		};
		return $valid ? [$link, $assignment, $object] : [null, null, null];
	}

	/**
	 * @param string $view contract | leave | confirm | done | invalid | accepted | declined | left
	 */
	protected function showResponsePage($request, string $view, $object = null, ?Assignment $assignment = null,
			array $extra = [], array $formState = []) {
		$plugin = $this->plugin();
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign($this->contractTemplateData($request, $formState) + $extra + [
			'view' => $view,
			'objectId' => $object?->getId(),
			'objectDescription' => $object?->getDescription(),
			'reviewerName' => $assignment ? Repo::user()->get($assignment->getUserId(), true)?->getFullName() : null,
			'publisherName' => $object ? CopyWorkflow::getPublisherContact($object)?->getFullName() : null,
			'contractDeadline' => $assignment?->getData('contractDeadline'),
			'dueDate' => $assignment?->getData('dueDate'),
			'contractFieldsTemplate' => $plugin->getTemplateResource('frontend/contractFields.tpl'),
			'maxStatementLength' => self::MAX_STATEMENT_LENGTH,
		]);
		return $templateMgr->display($plugin->getTemplateResource('frontend/pages/respond.tpl'));
	}

	/**
	 * Template data for the contract fields (reservation dialog and contract page).
	 */
	protected function contractTemplateData($request, array $formState): array {
		$plugin = $this->plugin();
		$context = $request->getContext();
		$dueDateRange = Reservation::getDueDateRange($plugin, $context->getId());
		return [
			'reserveContract' => Reservation::getContract($plugin, $context),
			'reserveDueDate' => $dueDateRange,
			'reserveErrors' => $formState['errors'] ?? [],
			'reserveInput' => ($formState['input'] ?? []) + ['dueDate' => $dueDateRange['default'], 'copyPreference' => ''],
			'contractFieldsTemplate' => $plugin->getTemplateResource('frontend/contractFields.tpl'),
		];
	}

	protected function contractInput($request): array {
		$input = [];
		foreach (self::CONTRACT_INPUT as $name) {
			$input[$name] = $request->getUserVar($name);
		}
		return $input;
	}

	/**
	 * An object of the current journal.
	 */
	protected function getObject($request, int $objectId) {
		$object = $objectId ? DAORegistry::getDAO('ObjectForReviewDAO')->getById($objectId) : null;
		return $object && $object->getContextId() == $request->getContext()->getId() ? $object : null;
	}

	/**
	 * Checks for the POST actions of logged-in users on the list page.
	 * @return array [ObjectForReview, User, Context]
	 */
	protected function requirePost($request): array {
		if (!$request->checkCSRF()) fatalError('CSRF check failed, please try again!');
		if (!$request->getUser()) {
			Validation::redirectLogin();
		}
		$object = $this->getObject($request, (int) $request->getUserVar('objectId'));
		if (!$object || $object->getSubmissionId() || $object->getData('offerClosed')) {
			$request->redirect(null, 'for-review');
		}
		return [$object, $request->getUser(), $request->getContext()];
	}
}
