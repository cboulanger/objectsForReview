<?php

/**
 * @file plugins/generic/objectsForReview/classes/ReservationLog.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationLog
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Audit trail of reservations, stored in OJS's event log and email log
 *  (associated with the object for review).
 */

namespace APP\plugins\generic\objectsForReview\classes;

use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\log\EmailLogEntry;
use PKP\user\User;

class ReservationLog {

	/** Association type for log entries about an object for review (plugin-specific) */
	const ASSOC_TYPE = 0x1F0F001;

	const EVENT_RESERVED = 0x1F0F201;
	const EVENT_CANCELLED_BY_REVIEWER = 0x1F0F202;
	const EVENT_CANCELLED_BY_EDITOR = 0x1F0F203;
	const EVENT_ATTACHED = 0x1F0F204;
	const EVENT_BID = 0x1F0F205;
	const EVENT_BID_WITHDRAWN = 0x1F0F206;
	const EVENT_CONTRACT_OFFERED = 0x1F0F207;
	const EVENT_CONTRACT_ACCEPTED = 0x1F0F208;
	const EVENT_CONTRACT_DECLINED = 0x1F0F209;
	const EVENT_CONTRACT_EXPIRED = 0x1F0F20A;
	const EVENT_CONTRACT_WITHDRAWN = 0x1F0F20B;
	const EVENT_BIDDER_UNSUCCESSFUL = 0x1F0F20C;
	const EVENT_BIDDING_REOPENED = 0x1F0F20D;
	const EVENT_MODE_DIRECT = 0x1F0F20E;
	const EVENT_OFFER_CLOSED = 0x1F0F20F;
	const EVENT_OFFER_REOPENED = 0x1F0F210;
	const EVENT_LIST_DISCARDED = 0x1F0F211;
	const EVENT_COMPLETED = 0x1F0F212;
	const EVENT_COPY_REQUESTED = 0x1F0F213;
	const EVENT_COPY_SENT_JOURNAL = 0x1F0F214;
	const EVENT_COPY_SENT_PUBLISHER = 0x1F0F215;
	const EVENT_COPY_RECEIVED = 0x1F0F216;
	const EVENT_COPY_REQUEST_WITHDRAWN = 0x1F0F217;
	const EVENT_RETURN_REQUESTED_JOURNAL = 0x1F0F218;
	const EVENT_RETURN_REQUESTED_PUBLISHER = 0x1F0F219;
	const EVENT_RETURN_SENT = 0x1F0F21A;
	const EVENT_RETURNED_JOURNAL = 0x1F0F21B;
	const EVENT_RETURNED_PUBLISHER = 0x1F0F21C;
	const EVENT_PUBLISHER_NOTIFIED = 0x1F0F21D;

	const EVENT_MESSAGES = [
		self::EVENT_RESERVED => 'plugins.generic.objectsForReview.history.event.reserved',
		self::EVENT_CANCELLED_BY_REVIEWER => 'plugins.generic.objectsForReview.history.event.cancelledByReviewer',
		self::EVENT_CANCELLED_BY_EDITOR => 'plugins.generic.objectsForReview.history.event.cancelledByEditor',
		self::EVENT_ATTACHED => 'plugins.generic.objectsForReview.history.event.attached',
		self::EVENT_BID => 'plugins.generic.objectsForReview.history.event.bid',
		self::EVENT_BID_WITHDRAWN => 'plugins.generic.objectsForReview.history.event.bidWithdrawn',
		self::EVENT_CONTRACT_OFFERED => 'plugins.generic.objectsForReview.history.event.contractOffered',
		self::EVENT_CONTRACT_ACCEPTED => 'plugins.generic.objectsForReview.history.event.contractAccepted',
		self::EVENT_CONTRACT_DECLINED => 'plugins.generic.objectsForReview.history.event.contractDeclined',
		self::EVENT_CONTRACT_EXPIRED => 'plugins.generic.objectsForReview.history.event.contractExpired',
		self::EVENT_CONTRACT_WITHDRAWN => 'plugins.generic.objectsForReview.history.event.contractWithdrawn',
		self::EVENT_BIDDER_UNSUCCESSFUL => 'plugins.generic.objectsForReview.history.event.bidderUnsuccessful',
		self::EVENT_BIDDING_REOPENED => 'plugins.generic.objectsForReview.history.event.biddingReopened',
		self::EVENT_MODE_DIRECT => 'plugins.generic.objectsForReview.history.event.modeDirect',
		self::EVENT_OFFER_CLOSED => 'plugins.generic.objectsForReview.history.event.offerClosed',
		self::EVENT_OFFER_REOPENED => 'plugins.generic.objectsForReview.history.event.offerReopened',
		self::EVENT_LIST_DISCARDED => 'plugins.generic.objectsForReview.history.event.listDiscarded',
		self::EVENT_COMPLETED => 'plugins.generic.objectsForReview.history.event.completed',
		self::EVENT_COPY_REQUESTED => 'plugins.generic.objectsForReview.history.event.copyRequested',
		self::EVENT_COPY_SENT_JOURNAL => 'plugins.generic.objectsForReview.history.event.copySentJournal',
		self::EVENT_COPY_SENT_PUBLISHER => 'plugins.generic.objectsForReview.history.event.copySentPublisher',
		self::EVENT_COPY_RECEIVED => 'plugins.generic.objectsForReview.history.event.copyReceived',
		self::EVENT_COPY_REQUEST_WITHDRAWN => 'plugins.generic.objectsForReview.history.event.copyRequestWithdrawn',
		self::EVENT_RETURN_REQUESTED_JOURNAL => 'plugins.generic.objectsForReview.history.event.returnRequestedJournal',
		self::EVENT_RETURN_REQUESTED_PUBLISHER => 'plugins.generic.objectsForReview.history.event.returnRequestedPublisher',
		self::EVENT_RETURN_SENT => 'plugins.generic.objectsForReview.history.event.returnSent',
		self::EVENT_RETURNED_JOURNAL => 'plugins.generic.objectsForReview.history.event.returnedJournal',
		self::EVENT_RETURNED_PUBLISHER => 'plugins.generic.objectsForReview.history.event.returnedPublisher',
		self::EVENT_PUBLISHER_NOTIFIED => 'plugins.generic.objectsForReview.history.event.publisherNotified',
	];

	/**
	 * Log a reservation event.
	 * @param ObjectForReview $object
	 * @param int $eventType One of the EVENT_* constants
	 * @param ?User $actor The user who caused the event
	 * @param ?User $reviewer The reviewer the reservation belongs to
	 * @param array $data Additional data (e.g. ['reviewDueDate' => ...])
	 */
	public static function logEvent($object, int $eventType, ?User $actor, ?User $reviewer, array $data = []): void {
		$entry = Repo::eventLog()->newDataObject(array_merge([
			'assocType' => self::ASSOC_TYPE,
			'assocId' => $object->getId(),
			'eventType' => $eventType,
			'userId' => $actor?->getId(),
			'dateLogged' => Core::getCurrentDate(),
			'message' => self::EVENT_MESSAGES[$eventType],
			'isTranslated' => false,
			'userFullName' => $actor?->getFullName(),
			'recipientName' => $reviewer?->getFullName(),
		], $data));
		Repo::eventLog()->add($entry);
	}

	/**
	 * Log a sent email (like Repo::emailLogEntry()->logMailable(), but for an object for review).
	 */
	public static function logEmail($object, ReservationEmailEventType $eventType, ?User $sender, User|string $recipient,
			string $from, string $subject, string $body): void {
		$entry = new EmailLogEntry();
		$entry->assocType = self::ASSOC_TYPE;
		$entry->assocId = $object->getId();
		$entry->eventType = $eventType->value;
		$entry->senderId = $sender?->getId();
		$entry->dateSent = Core::getCurrentDate();
		$entry->fromAddress = $from;
		$entry->recipients = $recipient instanceof User
			? '"' . $recipient->getFullName() . '" <' . $recipient->getEmail() . '>'
			: $recipient;
		$entry->ccRecipients = '';
		$entry->bccRecipients = '';
		$entry->subject = $subject;
		$entry->body = $body;
		$entry->save();
		if ($recipient instanceof User) {
			DB::table('email_log_users')->insert(['email_log_id' => $entry->id, 'user_id' => $recipient->getId()]);
		}
	}

	/**
	 * All log entries of an object, newest first.
	 * @return array[] Each with: date, type ('event'|'email'), label, actor, and for emails: recipients, subject, body
	 */
	public static function getHistory($object): array {
		$history = [];
		$events = Repo::eventLog()->getCollector()->filterByAssoc(self::ASSOC_TYPE, [$object->getId()])->getMany();
		foreach ($events as $event) {
			$history[] = [
				'date' => $event->getDateLogged(),
				'id' => (int) $event->getId(),
				'type' => 'event',
				'label' => $event->getTranslatedMessage(),
				'actor' => $event->getData('userFullName'),
			];
		}
		$emails = EmailLogEntry::withAssocType(self::ASSOC_TYPE)->withAssocId($object->getId())->get();
		foreach ($emails as $email) {
			$type = ReservationEmailEventType::tryFrom((int) $email->eventType);
			$history[] = [
				'date' => (string) $email->dateSent,
				'id' => (int) $email->id,
				'type' => 'email',
				'label' => $type ? __($type->labelKey()) : __('plugins.generic.objectsForReview.history.email'),
				'actor' => $email->senderId ? $email->senderFullName : __('plugins.generic.objectsForReview.history.system'),
				'recipients' => $email->recipients,
				'subject' => $email->subject,
				'body' => $email->body,
			];
		}
		// Newest first; within the same second by log order (a step's email is sent after its event is logged)
		usort($history, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date'])
			?: (($b['type'] === 'email') <=> ($a['type'] === 'email'))
			?: $b['id'] <=> $a['id']);
		return $history;
	}
}
