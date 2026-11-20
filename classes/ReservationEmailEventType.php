<?php

/**
 * @file plugins/generic/objectsForReview/classes/ReservationEmailEventType.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Email log event types for emails sent about a reservation.
 */

namespace APP\plugins\generic\objectsForReview\classes;

use PKP\log\core\EmailLogEventType;

enum ReservationEmailEventType: int implements EmailLogEventType
{
	case MESSAGE = 0x1F0F101;
	case CANCELLED_BY_EDITOR = 0x1F0F102;
	case REMINDER = 0x1F0F103;
	case CONTRACT_OFFER = 0x1F0F104;
	case CONTRACT_OFFER_AGAIN = 0x1F0F105;
	case CONTRACT_REMINDER = 0x1F0F106;
	case BID_UNSUCCESSFUL = 0x1F0F107;
	case BID_NEW = 0x1F0F108;
	case CONTRACT_DECLINED = 0x1F0F109;
	case COPY_REQUEST = 0x1F0F10A;
	case COPY_SENT = 0x1F0F10B;
	case RETURN_REQUEST = 0x1F0F10C;
	case RETURN_SENT = 0x1F0F10D;
	case PUBLISHER_NOTIFICATION = 0x1F0F10E;
	case COPY_REQUEST_REMINDER = 0x1F0F10F;
	case CONFIRMATION = 0x1F0F110;
	case INVITATION_RESERVE = 0x1F0F111;
	case INVITATION_BID = 0x1F0F112;
	case INVITATION_DECLINED = 0x1F0F113;

	public function labelKey(): string
	{
		return match ($this) {
			self::MESSAGE => 'plugins.generic.objectsForReview.history.email.message',
			self::CANCELLED_BY_EDITOR => 'plugins.generic.objectsForReview.history.email.cancelledByEditor',
			self::REMINDER => 'plugins.generic.objectsForReview.history.email.reminder',
			self::CONTRACT_OFFER => 'plugins.generic.objectsForReview.history.email.contractOffer',
			self::CONTRACT_OFFER_AGAIN => 'plugins.generic.objectsForReview.history.email.contractOfferAgain',
			self::CONTRACT_REMINDER => 'plugins.generic.objectsForReview.history.email.contractReminder',
			self::BID_UNSUCCESSFUL => 'plugins.generic.objectsForReview.history.email.bidUnsuccessful',
			self::BID_NEW => 'plugins.generic.objectsForReview.history.email.bidNew',
			self::CONTRACT_DECLINED => 'plugins.generic.objectsForReview.history.email.contractDeclined',
			self::COPY_REQUEST => 'plugins.generic.objectsForReview.history.email.copyRequest',
			self::COPY_SENT => 'plugins.generic.objectsForReview.history.email.copySent',
			self::RETURN_REQUEST => 'plugins.generic.objectsForReview.history.email.returnRequest',
			self::RETURN_SENT => 'plugins.generic.objectsForReview.history.email.returnSent',
			self::PUBLISHER_NOTIFICATION => 'plugins.generic.objectsForReview.history.email.publisherNotification',
			self::COPY_REQUEST_REMINDER => 'plugins.generic.objectsForReview.history.email.copyRequestReminder',
			self::CONFIRMATION => 'plugins.generic.objectsForReview.history.email.confirmation',
			self::INVITATION_RESERVE => 'plugins.generic.objectsForReview.history.email.invitationReserve',
			self::INVITATION_BID => 'plugins.generic.objectsForReview.history.email.invitationBid',
			self::INVITATION_DECLINED => 'plugins.generic.objectsForReview.history.email.invitationDeclined',
		};
	}
}
