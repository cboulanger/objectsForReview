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
		};
	}
}
