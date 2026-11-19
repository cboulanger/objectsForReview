<?php

/**
 * @file plugins/generic/objectsForReview/mailables/ObjectsForReviewReminder.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewReminder
 *
 * @brief Automatic reminder to the reviewer before the review is due.
 *  Sent from the journal contact (no Sender trait, so from() can be used).
 */

namespace APP\plugins\generic\objectsForReview\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewReminder extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.ofrReminder.name';
    protected static ?string $description = 'emails.ofrReminder.description';
    protected static ?string $emailTemplateKey = 'OFR_REMINDER';
    protected static array $fromRoleIds = [self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_READER, Role::ROLE_ID_AUTHOR];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
