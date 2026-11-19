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

	public function labelKey(): string
	{
		return match ($this) {
			self::MESSAGE => 'plugins.generic.objectsForReview.history.email.message',
			self::CANCELLED_BY_EDITOR => 'plugins.generic.objectsForReview.history.email.cancelledByEditor',
			self::REMINDER => 'plugins.generic.objectsForReview.history.email.reminder',
		};
	}
}
