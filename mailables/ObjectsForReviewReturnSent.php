<?php

/**
 * @file plugins/generic/objectsForReview/mailables/ObjectsForReviewReturnSent.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewReturnSent
 *
 * @brief Notice to the publisher contact that a review copy is on its way back.
 */

namespace APP\plugins\generic\objectsForReview\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\security\Role;

class ObjectsForReviewReturnSent extends Mailable
{
    use Configurable;
    use Recipient;

    protected static ?string $name = 'emails.ofrReturnSent.name';
    protected static ?string $description = 'emails.ofrReturnSent.description';
    protected static ?string $emailTemplateKey = 'OFR_RETURN_SENT';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, self::FROM_SYSTEM];
    protected static array $toRoleIds = [Role::ROLE_ID_READER];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
