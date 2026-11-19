<?php

/**
 * @file plugins/generic/objectsForReview/controllers/form/ReservationEmailForm.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationEmailForm
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Email the reviewer of a reservation, or cancel the reservation with an
 *  optional notification (like unassigning a reviewer). The message is
 *  prefilled from an email template and recorded in the audit trail.
 */

namespace APP\plugins\generic\objectsForReview\controllers\form;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\objectsForReview\classes\Reservation;
use APP\plugins\generic\objectsForReview\classes\ReservationEmailEventType;
use APP\plugins\generic\objectsForReview\classes\ReservationLog;
use APP\plugins\generic\objectsForReview\classes\ReservationMailer;
use APP\plugins\generic\objectsForReview\controllers\ReservationActionsHandler;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class ReservationEmailForm extends \PKP\form\Form {

	const MODE_MESSAGE = 'message';
	const MODE_CANCEL = 'cancel';

	protected $plugin;
	protected $context;
	protected $object;
	protected string $mode;
	protected $reviewer;

	public function __construct($plugin, $context, $object, string $mode) {
		parent::__construct($plugin->getTemplateResource('reservationEmailForm.tpl'));
		$this->plugin = $plugin;
		$this->context = $context;
		$this->object = $object;
		$this->mode = $mode;
		$this->reviewer = Repo::user()->get((int) $object->getUserId(), true);

		// Subject and body are needed whenever an email is sent
		$needsEmail = fn () => $this->mode === self::MODE_MESSAGE || $this->getData('sendEmail');
		$this->addCheck(new FormValidatorCustom($this, 'subject', 'required', 'plugins.generic.objectsForReview.reservations.subjectRequired',
			fn ($subject) => !$needsEmail() || trim((string) $subject) !== ''));
		$this->addCheck(new FormValidatorCustom($this, 'body', 'required', 'plugins.generic.objectsForReview.reservations.bodyRequired',
			fn ($body) => !$needsEmail() || trim(strip_tags((string) $body)) !== ''));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	protected function emailType(): ReservationEmailEventType {
		return $this->mode === self::MODE_CANCEL ? ReservationEmailEventType::CANCELLED_BY_EDITOR : ReservationEmailEventType::MESSAGE;
	}

	public function initData() {
		$sender = Application::get()->getRequest()->getUser();
		[$subject, $body] = ReservationMailer::compose($this->context, $this->emailType(), $this->object, $this->reviewer, $sender);
		$this->setData('subject', $subject);
		$this->setData('body', $body);
		$this->setData('sendEmail', true);
	}

	public function readInputData() {
		$this->readUserVars(['subject', 'body', 'sendEmail']);
	}

	public function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign([
			'mode' => $this->mode,
			'objectId' => $this->object->getId(),
			'objectDescription' => $this->object->getDescription(),
			'reviewerName' => $this->reviewer->getFullName(),
			'reviewerEmail' => $this->reviewer->getEmail(),
			'actionUrl' => $request->getDispatcher()->url($request, Application::ROUTE_COMPONENT, null,
				ReservationActionsHandler::COMPONENT, $this->mode === self::MODE_CANCEL ? 'cancel' : 'sendEmail'),
		]);
		return parent::fetch($request, $template, $display);
	}

	public function execute(...$functionArgs) {
		$sender = Application::get()->getRequest()->getUser();
		if ($this->mode === self::MODE_MESSAGE || $this->getData('sendEmail')) {
			ReservationMailer::send($this->context, $this->emailType(), $this->object, $this->reviewer, $sender,
				trim($this->getData('subject')), $this->getData('body'));
		}
		if ($this->mode === self::MODE_CANCEL) {
			ReservationLog::logEvent($this->object, ReservationLog::EVENT_CANCELLED_BY_EDITOR, $sender, $this->reviewer);
			Reservation::clear($this->object);
			DAORegistry::getDAO('ObjectForReviewDAO')->updateObject($this->object);
		}
		parent::execute(...$functionArgs);
	}
}
