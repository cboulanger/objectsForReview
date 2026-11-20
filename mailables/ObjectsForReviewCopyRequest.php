<?php

/**
 * @file plugins/generic/objectsForReview/mailables/ObjectsForReviewCopyRequest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewCopyRequest
 *
 * @brief Request for a review copy, sent to the publisher contact with the delivery details.
 */

namespace APP\plugins\generic\objectsForReview\mailables;

use APP\journal\Journal;
use PKP\mail\Mailable;
use PKP\mail\traits\Configurable;
use PKP\mail\traits\Recipient;
use PKP\mail\traits\Sender;
use PKP\security\Role;

class ObjectsForReviewCopyRequest extends Mailable
{
    use Configurable;
    use Recipient;
    use Sender;

    protected static ?string $name = 'emails.ofrCopyRequest.name';
    protected static ?string $description = 'emails.ofrCopyRequest.description';
    protected static ?string $emailTemplateKey = 'OFR_COPY_REQUEST';
    protected static array $fromRoleIds = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR];
    protected static array $toRoleIds = [Role::ROLE_ID_READER];

    public function __construct(Journal $context, array $emailParams)
    {
        parent::__construct([$context]);
        $this->addData($emailParams);
    }
}
