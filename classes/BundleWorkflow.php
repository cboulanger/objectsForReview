<?php

/**
 * @file plugins/generic/objectsForReview/classes/BundleWorkflow.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BundleWorkflow
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Review essays: one review of several works.
 *
 *  - The container is an object for review with the resource type
 *    "reviewEssay". It owns the offer, the bids, the invitations and the
 *    reviewer's contract (the container assignment).
 *  - The members are the works reviewed in the essay. Each points to the
 *    container with the offer setting bundleId (bundlePosition orders them).
 *    A member is not offered on its own.
 *  - On each member, a mirror assignment (setting bundleAssignmentId = the
 *    container assignment's id) follows the reviewer's status and carries the
 *    review copy status of that work (copy preference, shipping, return).
 *
 *  AssignmentWorkflow calls syncMembers() after every transition of a
 *  container; running it again changes nothing.
 */

namespace APP\plugins\generic\objectsForReview\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\user\User;

class BundleWorkflow {

	/** Container assignment statuses that the mirrors follow (the newest such assignment is the reviewer) */
	const MIRRORED_STATUSES = [Assignment::STATUS_ACCEPTED, Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED,
		Assignment::STATUS_RETRACTED, Assignment::STATUS_CANCELLED];

	/**
	 * The review essay a work belongs to, if any.
	 */
	public static function getContainer(ObjectForReview $work): ?ObjectForReview {
		$bundleId = $work->getBundleId();
		if (!$bundleId) {
			return null;
		}
		$container = (new ObjectForReviewDAO())->getById($bundleId);
		return $container && $container->isReviewEssay() ? $container : null;
	}

	/**
	 * Bring the members of a review essay in line with its reviewer: create
	 * or update the mirror assignments, and copy the submission and the
	 * reviewer to the works.
	 */
	public static function syncMembers(ObjectForReview $container): void {
		DB::transaction(function () use ($container) {
			$dao = AssignmentWorkflow::dao();
			$reviewerAssignment = self::getReviewerAssignment($container);
			if (!$reviewerAssignment) {
				return;
			}
			$members = (new ObjectForReviewDAO())->getMembers($container->getId());
			$reviewer = Repo::user()->get($reviewerAssignment->getUserId(), true);
			$status = $reviewerAssignment->getStatus();

			$mirrors = [];
			foreach ($dao->getMirrors($reviewerAssignment->getId()) as $mirror) {
				$mirrors[$mirror->getObjectId()] = $mirror;
			}

			foreach ($members as $member) {
				$mirror = $mirrors[$member->getId()] ?? null;
				if ($status === Assignment::STATUS_ACCEPTED) {
					self::syncAccepted($container, $reviewerAssignment, $member, $mirror, $reviewer);
				} elseif ($mirror && in_array($status, [Assignment::STATUS_SUBMITTED, Assignment::STATUS_COMPLETED], true)) {
					if ($mirror->isReviewer() && $mirror->getStatus() !== $status) {
						if ($status === Assignment::STATUS_SUBMITTED) {
							Reservation::erasePersonalData($mirror);
						}
						self::setStatus($container, $member, $mirror, $status, $reviewer);
					}
				} elseif ($mirror && $mirror->getStatus() === Assignment::STATUS_ACCEPTED) {
					// Retracted or cancelled: copies already sent stay returnable, so only the retract link ends
					Reservation::erasePersonalData($mirror);
					CopyWorkflow::onAssignmentEnded($mirror);
					self::setStatus($container, $member, $mirror, $status, $reviewer);
					ActionLink::invalidate($mirror->getId(), ActionLink::ACTION_RETRACT);
				}
			}

			if ($status === Assignment::STATUS_ACCEPTED) {
				// The delivery details now live on the mirrors
				$changed = false;
				foreach (['shippingAddress', 'phone', 'copyPreferences'] as $field) {
					if ($reviewerAssignment->getData($field) !== null) {
						$reviewerAssignment->unsetData($field);
						$changed = true;
					}
				}
				if ($changed) {
					$dao->updateObject($reviewerAssignment);
				}
			}

			$submissionId = $container->getSubmissionId() ?: null;
			foreach ($members as $member) {
				if ((int) $member->getSubmissionId() !== (int) $submissionId) {
					$member->setSubmissionId($submissionId);
					(new ObjectForReviewDAO())->updateObject($member);
				}
				AssignmentWorkflow::syncReviewer($member);
			}
		});
	}

	/**
	 * The container assignment the mirrors follow: the newest one with a mirrored status.
	 */
	protected static function getReviewerAssignment(ObjectForReview $container): ?Assignment {
		$assignments = AssignmentWorkflow::dao()->getByObjectId($container->getId(), self::MIRRORED_STATUSES);
		return $assignments ? end($assignments) : null;
	}

	/**
	 * The reviewer's agreement is accepted: create the member's mirror, or bring it back to "accepted" (detach).
	 */
	protected static function syncAccepted(ObjectForReview $container, Assignment $reviewerAssignment, ObjectForReview $member,
			?Assignment $mirror, ?User $reviewer): void {
		$dao = AssignmentWorkflow::dao();
		if (!$mirror) {
			$preferences = (array) ($reviewerAssignment->getData('copyPreferences') ?? []);
			$preference = $preferences[$member->getId()] ?? Reservation::COPY_HAVE;
			$mirror = $dao->newDataObject();
			$mirror->setObjectId($member->getId());
			$mirror->setContextId($member->getContextId());
			$mirror->setUserId($reviewerAssignment->getUserId());
			$mirror->setStatus(Assignment::STATUS_ACCEPTED);
			$mirror->setData('bundleAssignmentId', $reviewerAssignment->getId());
			$mirror->setData('dueDate', $reviewerAssignment->getData('dueDate'));
			$mirror->setData('dateReserved', $reviewerAssignment->getData('dateReserved'));
			$mirror->setData('copyPreference', $preference);
			if ($preference === Reservation::COPY_PHYSICAL) {
				$mirror->setData('shippingAddress', $reviewerAssignment->getData('shippingAddress'));
				$mirror->setData('phone', $reviewerAssignment->getData('phone'));
			} elseif ($preference === Reservation::COPY_OTHER) {
				$mirror->setData('copyNote', $reviewerAssignment->getData('copyNote'));
			}
			CopyWorkflow::init($mirror);
			$dao->insertObject($mirror);
			ReservationLog::logEvent($member, ReservationLog::EVENT_ESSAY_MIRRORED, null, $reviewer, self::logData($container));
			return;
		}
		$changed = false;
		if ($mirror->getData('dueDate') !== $reviewerAssignment->getData('dueDate')) {
			$mirror->setData('dueDate', $reviewerAssignment->getData('dueDate'));
			$changed = true;
		}
		if ($mirror->getStatus() === Assignment::STATUS_SUBMITTED) {
			self::setStatus($container, $member, $mirror, Assignment::STATUS_ACCEPTED, $reviewer);
		} elseif ($changed) {
			$dao->updateObject($mirror);
		}
	}

	/**
	 * Change a mirror's status and log it on the member.
	 */
	protected static function setStatus(ObjectForReview $container, ObjectForReview $member, Assignment $mirror, string $status,
			?User $reviewer): void {
		$mirror->setStatus($status);
		$mirror->setDateStatus(Core::getCurrentDate());
		AssignmentWorkflow::dao()->updateObject($mirror);
		ReservationLog::logEvent($member, ReservationLog::EVENT_ESSAY_STATUS, null, $reviewer,
			self::logData($container) + ['message' => ReservationLog::EVENT_MESSAGES[ReservationLog::EVENT_ESSAY_STATUS] . '.' . $status]);
	}

	/**
	 * Log data of a mirror event. Only the event log schema's fields are stored, so the
	 * essay's title goes into recipientName (the reviewer is not named in these messages).
	 */
	protected static function logData(ObjectForReview $container): array {
		return ['recipientName' => $container->getTitle()];
	}
}
