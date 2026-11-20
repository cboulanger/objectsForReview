<?php

/**
 * @file plugins/generic/objectsForReview/classes/Assignment.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Assignment
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief One bidder or reviewer of an object for review. In direct reservation
 *  mode an assignment starts as "accepted"; in bidding mode it starts as a bid.
 *  The contract data (due date, copy preference, ...) is stored with the
 *  assignment (see Reservation::FIELDS).
 */

namespace APP\plugins\generic\objectsForReview\classes;

use PKP\core\DataObject;

class Assignment extends DataObject {

	const STATUS_BID = 'bid';
	const STATUS_UNSUCCESSFUL = 'unsuccessful';
	const STATUS_OFFERED = 'offered';
	const STATUS_ACCEPTED = 'accepted';
	const STATUS_SUBMITTED = 'submitted';
	const STATUS_COMPLETED = 'completed';
	const STATUS_WITHDRAWN = 'withdrawn';
	const STATUS_DECLINED = 'declined';
	const STATUS_EXPIRED = 'expired';
	const STATUS_RETRACTED = 'retracted';
	const STATUS_CANCELLED = 'cancelled';
	const STATUS_DISCARDED = 'discarded';

	/** Bidders still on the list */
	const BIDDER_STATUSES = [self::STATUS_BID, self::STATUS_UNSUCCESSFUL];

	/** The reviewer of the object (at most one per object) */
	const REVIEWER_STATUSES = [self::STATUS_ACCEPTED, self::STATUS_SUBMITTED, self::STATUS_COMPLETED];

	/** Assignments that ended without a review (after the contract was offered or accepted) */
	const DROPOUT_STATUSES = [self::STATUS_DECLINED, self::STATUS_EXPIRED, self::STATUS_RETRACTED, self::STATUS_CANCELLED];

	/**
	 * Data stored as settings, in addition to Reservation::FIELDS: bid and
	 * contract offer, and the review copy (see CopyWorkflow).
	 */
	const FIELDS = ['statement', 'dateOffered', 'contractDeadline', 'contractReminderSent', 'declineReason',
		'copyStatus', 'copyStatusDate', 'copySentBy', 'returnTarget', 'copyReminderSent', 'copyReminderCount'];

	function getObjectId(): int {
		return (int) $this->getData('objectId');
	}

	function setObjectId($objectId) {
		$this->setData('objectId', (int) $objectId);
	}

	function getContextId(): int {
		return (int) $this->getData('contextId');
	}

	function setContextId($contextId) {
		$this->setData('contextId', (int) $contextId);
	}

	function getUserId(): int {
		return (int) $this->getData('userId');
	}

	function setUserId($userId) {
		$this->setData('userId', (int) $userId);
	}

	function getStatus(): string {
		return (string) $this->getData('status');
	}

	function setStatus(string $status) {
		$this->setData('status', $status);
	}

	function getDateCreated() {
		return $this->getData('dateCreated');
	}

	function setDateCreated($date) {
		$this->setData('dateCreated', $date);
	}

	function getDateStatus() {
		return $this->getData('dateStatus');
	}

	function setDateStatus($date) {
		$this->setData('dateStatus', $date);
	}

	function isBidder(): bool {
		return in_array($this->getStatus(), self::BIDDER_STATUSES, true);
	}

	function isReviewer(): bool {
		return in_array($this->getStatus(), self::REVIEWER_STATUSES, true);
	}
}
