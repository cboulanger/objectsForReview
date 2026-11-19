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

	const EVENT_MESSAGES = [
		self::EVENT_RESERVED => 'plugins.generic.objectsForReview.history.event.reserved',
		self::EVENT_CANCELLED_BY_REVIEWER => 'plugins.generic.objectsForReview.history.event.cancelledByReviewer',
		self::EVENT_CANCELLED_BY_EDITOR => 'plugins.generic.objectsForReview.history.event.cancelledByEditor',
		self::EVENT_ATTACHED => 'plugins.generic.objectsForReview.history.event.attached',
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
	public static function logEmail($object, ReservationEmailEventType $eventType, ?User $sender, User $recipient,
			string $from, string $subject, string $body): void {
		$entry = new EmailLogEntry();
		$entry->assocType = self::ASSOC_TYPE;
		$entry->assocId = $object->getId();
		$entry->eventType = $eventType->value;
		$entry->senderId = $sender?->getId();
		$entry->dateSent = Core::getCurrentDate();
		$entry->fromAddress = $from;
		$entry->recipients = '"' . $recipient->getFullName() . '" <' . $recipient->getEmail() . '>';
		$entry->ccRecipients = '';
		$entry->bccRecipients = '';
		$entry->subject = $subject;
		$entry->body = $body;
		$entry->save();
		DB::table('email_log_users')->insert(['email_log_id' => $entry->id, 'user_id' => $recipient->getId()]);
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
				'type' => 'event',
				'label' => $event->getTranslatedMessage(),
				'actor' => $event->getData('userFullName'),
			];
		}
		$emails = EmailLogEntry::withAssocType(self::ASSOC_TYPE)->withAssocId($object->getId())->get();
		foreach ($emails as $email) {
			$type = ReservationEmailEventType::tryFrom((int) $email->eventType);
			$history[] = [
				'date' => $email->dateSent,
				'type' => 'email',
				'label' => $type ? __($type->labelKey()) : __('plugins.generic.objectsForReview.history.email'),
				'actor' => $email->senderId ? $email->senderFullName : __('plugins.generic.objectsForReview.history.system'),
				'recipients' => $email->recipients,
				'subject' => $email->subject,
				'body' => $email->body,
			];
		}
		usort($history, fn ($a, $b) => strcmp($b['date'], $a['date']));
		return $history;
	}
}
