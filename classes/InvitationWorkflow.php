<?php

/**
 * @file plugins/generic/objectsForReview/classes/InvitationWorkflow.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class InvitationWorkflow
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Inviting particular people to review an object (issue #2). Each
 *  person on an object's invitation list is an assignment:
 *
 *  listed --(invitation sent)--> invited --(reserves)--> accepted   (direct reservation)
 *                                        --(applies)---> bid        (bidding)
 *                                        --(declines)--> invitationDeclined
 *                                        --(deadline)--> invitationExpired
 *
 *  Declined and expired invitations can be sent again (manually). Once an
 *  invitee reserves or applies, the normal workflow applies (AssignmentWorkflow).
 */

namespace APP\plugins\generic\objectsForReview\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\objectsForReview\pages\ObjectsForReviewManagementHandler;
use PKP\core\Core;
use PKP\security\Role;
use PKP\security\Validation;
use PKP\user\User;
use PKP\userGroup\relationships\UserUserGroup;
use PKP\userGroup\UserGroup;

class InvitationWorkflow {

	const DEFAULT_INVITATION_DAYS = 7;
	const MAX_INVITATION_DAYS = 90;

	/** Statuses from which an invitation can be sent (again) */
	const SENDABLE_STATUSES = [Assignment::STATUS_LISTED, Assignment::STATUS_INVITED,
		Assignment::STATUS_INVITATION_DECLINED, Assignment::STATUS_INVITATION_EXPIRED];

	/** Statuses of people who can't be added to the list (again) */
	const BLOCKING_STATUSES = [Assignment::STATUS_BID, Assignment::STATUS_UNSUCCESSFUL, Assignment::STATUS_OFFERED,
		Assignment::STATUS_ACCEPTED, Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED,
		Assignment::STATUS_LISTED, Assignment::STATUS_INVITED, Assignment::STATUS_INVITATION_DECLINED, Assignment::STATUS_INVITATION_EXPIRED];

	/**
	 * The invitation list of an object, in order: everyone added to it, also
	 * those who have since reserved or applied (they keep their position).
	 * @return Assignment[]
	 */
	public static function getList(ObjectForReview $object): array {
		$list = array_values(array_filter(AssignmentWorkflow::dao()->getByObjectId($object->getId()),
			fn (Assignment $a) => $a->isInvitee() || $a->getData('position') !== null));
		usort($list, fn ($a, $b) => ((int) $a->getData('position')) <=> ((int) $b->getData('position')) ?: $a->getId() <=> $b->getId());
		return $list;
	}

	/**
	 * What the invitees are asked to do: reserve (direct reservation) or apply (bidding).
	 */
	public static function getKind(ObjectForReview $object): string {
		return $object->isBidding() ? 'bid' : 'reserve';
	}

	public static function getEmailType(ObjectForReview $object): ReservationEmailEventType {
		return $object->isBidding() ? ReservationEmailEventType::INVITATION_BID : ReservationEmailEventType::INVITATION_RESERVE;
	}

	/**
	 * Can invitations be sent (and answered) now? The offer must be open; hiding
	 * it from the public list doesn't matter.
	 */
	public static function canInvite(ObjectForReview $object, ?array $assignments = null): bool {
		$assignments ??= AssignmentWorkflow::dao()->getByObjectId($object->getId());
		return $object->isBidding()
			? AssignmentWorkflow::isBiddingOpen($object, $assignments)
			: AssignmentWorkflow::isReservable($object, $assignments);
	}

	/**
	 * Can the user be added to the object's list?
	 */
	public static function canAdd(ObjectForReview $object, int $userId): bool {
		return !$object->getData('offerClosed') && !$object->getSubmissionId()
			&& !AssignmentWorkflow::dao()->getByObjectAndUser($object->getId(), $userId, self::BLOCKING_STATUSES);
	}

	/**
	 * Days invitees have to answer (setting ofrInvitationDays).
	 */
	public static function getInvitationDays($plugin, int $contextId): int {
		$days = $plugin->getSetting($contextId, 'ofrInvitationDays');
		return $days === null || $days === '' ? self::DEFAULT_INVITATION_DAYS : max(1, (int) $days);
	}

	/**
	 * Answer deadline of an invitation sent today (Y-m-d). In bidding mode, the
	 * bid deadline if there is one.
	 */
	public static function getDeadline($plugin, ObjectForReview $object): string {
		if ($object->isBidding() && $object->getData('bidDeadline')) {
			return (string) $object->getData('bidDeadline');
		}
		$days = self::getInvitationDays($plugin, (int) $object->getContextId());
		return (new \DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d');
	}

	public static function add(ObjectForReview $object, User $user, User $editor): ?Assignment {
		if (!self::canAdd($object, $user->getId())) {
			return null;
		}
		$list = self::getList($object);
		$assignment = AssignmentWorkflow::dao()->newDataObject();
		$assignment->setObjectId($object->getId());
		$assignment->setContextId((int) $object->getContextId());
		$assignment->setUserId($user->getId());
		$assignment->setStatus(Assignment::STATUS_LISTED);
		$assignment->setData('position', $list ? max(array_map(fn ($a) => (int) $a->getData('position'), $list)) + 1 : 1);
		AssignmentWorkflow::dao()->insertObject($assignment);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITEE_ADDED, $editor, $user);
		return $assignment;
	}

	/**
	 * Create an account for a new person (in the journal's Author group, so that
	 * they can submit the review) and add them to the list. No email is sent.
	 * An existing account with the same email address is added to the journal instead.
	 * @param array $data givenName, familyName, email, affiliation
	 */
	public static function createAndAdd($context, ObjectForReview $object, array $data, User $editor): ?Assignment {
		$locale = $context->getPrimaryLocale();
		$user = Repo::user()->getByEmail($data['email'], true);
		$created = false;
		if (!$user) {
			$user = Repo::user()->newDataObject();
			$user->setUsername(self::uniqueUsername($data['email']));
			$user->setEmail($data['email']);
			$user->setPassword(Validation::encryptCredentials($user->getUsername(), Validation::generatePassword()));
			$user->setGivenName($data['givenName'], $locale);
			$user->setFamilyName($data['familyName'], $locale);
			if ($data['affiliation'] !== '') {
				$user->setAffiliation($data['affiliation'], $locale);
			}
			$user->setDateRegistered(Core::getCurrentDate());
			$user->setMustChangePassword(true);
			Repo::user()->add($user);
			$user = Repo::user()->get($user->getId(), true);
			$created = true;
		}
		$authorGroup = UserGroup::withContextIds([$context->getId()])->withRoleIds([Role::ROLE_ID_AUTHOR])->isDefault(true)->first();
		if ($authorGroup && !UserUserGroup::withUserId($user->getId())->withUserGroupIds([$authorGroup->userGroupId])->exists()) {
			UserUserGroup::create(['userId' => $user->getId(), 'userGroupId' => $authorGroup->userGroupId,
				'dateStart' => Core::getCurrentDate(), 'dateEnd' => null, 'masthead' => null]);
		}
		if ($created) {
			ReservationLog::logEvent($object, ReservationLog::EVENT_INVITEE_CREATED, $editor, $user);
		}
		return self::add($object, $user, $editor);
	}

	protected static function uniqueUsername(string $email): string {
		$base = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($email, '@', true) ?: 'reviewer')) ?: 'reviewer';
		$base = substr($base, 0, 24);
		$username = $base;
		for ($i = 2; Repo::user()->getByUsername($username, true); $i++) {
			$username = $base . $i;
		}
		return $username;
	}

	/**
	 * Remove someone who hasn't been invited yet.
	 */
	public static function remove(ObjectForReview $object, Assignment $assignment, User $editor): bool {
		if ($assignment->getStatus() !== Assignment::STATUS_LISTED) {
			return false;
		}
		$user = Repo::user()->get($assignment->getUserId(), true);
		AssignmentWorkflow::dao()->deleteById($assignment->getId());
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITEE_REMOVED, $editor, $user);
		return true;
	}

	/**
	 * Put the list in a new order.
	 * @param int[] $assignmentIds All assignment IDs of the list, in the new order
	 */
	public static function reorder(ObjectForReview $object, array $assignmentIds, User $editor): bool {
		$list = self::getList($object);
		$byId = [];
		foreach ($list as $assignment) {
			$byId[$assignment->getId()] = $assignment;
		}
		$assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));
		if (count($assignmentIds) !== count($byId) || array_diff($assignmentIds, array_keys($byId))) {
			return false;
		}
		if ($assignmentIds === array_keys($byId)) {
			return true;
		}
		foreach ($assignmentIds as $index => $id) {
			$byId[$id]->setData('position', $index + 1);
			AssignmentWorkflow::dao()->updateObject($byId[$id]);
		}
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_LIST_REORDERED, $editor, null);
		return true;
	}

	/**
	 * Move an entry one place up (-1) or down (+1).
	 */
	public static function move(ObjectForReview $object, int $assignmentId, int $direction, User $editor): bool {
		$ids = array_map(fn ($a) => $a->getId(), self::getList($object));
		$index = array_search($assignmentId, $ids, true);
		$target = $index === false ? false : $index + ($direction < 0 ? -1 : 1);
		if ($target === false || $target < 0 || $target >= count($ids)) {
			return false;
		}
		[$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
		return self::reorder($object, $ids, $editor);
	}

	/**
	 * Send (or send again) an invitation: the assignment becomes "invited".
	 * @return string Key of the one-click link (valid until the deadline)
	 */
	public static function invite(ObjectForReview $object, Assignment $assignment, User $editor, string $deadline): string {
		if (!in_array($assignment->getStatus(), self::SENDABLE_STATUSES, true)) {
			throw new \InvalidArgumentException('Assignment ' . $assignment->getId() . ' has status ' . $assignment->getStatus());
		}
		ActionLink::invalidate($assignment->getId());
		$assignment->setData('invitedBy', $editor->getId());
		$assignment->setData('dateInvited', Core::getCurrentDate());
		$assignment->setData('invitationDeadline', $deadline);
		$assignment->unsetData('invitationDeclineReason');
		$assignment->unsetData('invitationReminderSent');
		$assignment->setStatus(Assignment::STATUS_INVITED);
		$assignment->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($assignment);
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_SENT, $editor, $invitee, ['reviewDueDate' => $deadline]);
		return ActionLink::create($assignment->getId(), ActionLink::ACTION_INVITATION, $deadline . ' 23:59:59');
	}

	/**
	 * Can the invitee still answer?
	 */
	public static function isAnswerable(ObjectForReview $object, Assignment $assignment): bool {
		return $assignment->getStatus() === Assignment::STATUS_INVITED
			&& (string) $assignment->getData('invitationDeadline') >= date('Y-m-d')
			&& self::canInvite($object);
	}

	/**
	 * The invitee reserves the object (direct reservation).
	 */
	public static function reserve($plugin, $context, ObjectForReview $object, Assignment $assignment, array $data): void {
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		AssignmentWorkflow::reserve($plugin, $context, $object, $invitee, $data, $assignment);
	}

	/**
	 * The invitee applies (bidding).
	 */
	public static function bid($plugin, $context, ObjectForReview $object, Assignment $assignment, string $statement): void {
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		AssignmentWorkflow::bid($plugin, $context, $object, $invitee, $statement, $assignment);
	}

	/**
	 * The invitee declines; the responsible editor is notified.
	 */
	public static function decline($plugin, $context, ObjectForReview $object, Assignment $assignment, string $reason): void {
		if ($assignment->getStatus() !== Assignment::STATUS_INVITED) {
			throw new \InvalidArgumentException('Assignment ' . $assignment->getId() . ' has status ' . $assignment->getStatus());
		}
		if ($reason !== '') {
			$assignment->setData('invitationDeclineReason', $reason);
		}
		$assignment->setStatus(Assignment::STATUS_INVITATION_DECLINED);
		$assignment->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($assignment);
		ActionLink::invalidate($assignment->getId());
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_DECLINED, $invitee, $invitee, ReservationLog::viaLink($invitee));
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::INVITATION_DECLINED, $object, $assignment, [
			'inviteeName' => htmlspecialchars($invitee?->getFullName() ?? ''),
			'invitationListUrl' => self::listUrl($context, $object),
			'declineReason' => $reason !== '' ? nl2br(htmlspecialchars($reason))
				: __('plugins.generic.objectsForReview.contract.declined.noReason', [], $context->getPrimaryLocale()),
		]);
	}

	/**
	 * The deadline passed without an answer (daily task); the responsible editor is notified.
	 */
	public static function expire($plugin, $context, ObjectForReview $object, Assignment $assignment): void {
		if ($assignment->getStatus() !== Assignment::STATUS_INVITED) {
			return;
		}
		$assignment->setStatus(Assignment::STATUS_INVITATION_EXPIRED);
		$assignment->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($assignment);
		ActionLink::invalidate($assignment->getId());
		$invitee = Repo::user()->get($assignment->getUserId(), true);
		ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATION_EXPIRED, null, $invitee);
		ReservationMailer::notifyEditor($plugin, $context, ReservationEmailEventType::INVITATION_DECLINED, $object, $assignment, [
			'inviteeName' => htmlspecialchars($invitee?->getFullName() ?? ''),
			'invitationListUrl' => self::listUrl($context, $object),
			'declineReason' => __('plugins.generic.objectsForReview.invitation.expired', [], $context->getPrimaryLocale()),
		]);
	}

	/**
	 * URL of the object's invitation page.
	 */
	public static function listUrl($context, ObjectForReview $object): string {
		$request = Application::get()->getRequest();
		return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(),
			ObjectsForReviewManagementHandler::PAGE, 'invite', [$object->getId()]);
	}

	/**
	 * The offer was closed or the review published: open invitations end, their links stop working.
	 */
	public static function endAll(ObjectForReview $object, ?User $editor = null): void {
		$open = AssignmentWorkflow::dao()->getByObjectId($object->getId(), Assignment::OPEN_INVITATION_STATUSES);
		foreach ($open as $assignment) {
			ActionLink::invalidate($assignment->getId());
			$assignment->setStatus(Assignment::STATUS_DISCARDED);
			$assignment->setDateStatus(Core::getCurrentDate());
			AssignmentWorkflow::dao()->updateObject($assignment);
		}
		if ($open) {
			ReservationLog::logEvent($object, ReservationLog::EVENT_INVITATIONS_ENDED, $editor, null, ['recipientCount' => count($open)]);
		}
	}
}
