<?php

/**
 * @file plugins/generic/objectsForReview/classes/ReservationMailer.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationMailer
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Emails to the reviewer who reserved an object (message from the
 *  editors, cancellation by the editors, reminder), based on editable email
 *  templates and recorded in the reservation's audit trail.
 */

namespace APP\plugins\generic\objectsForReview\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewCancelByEditor;
use APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewMessage;
use APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewReminder;
use Illuminate\Support\Facades\Mail;
use PKP\core\PKPString;
use PKP\user\User;

class ReservationMailer {

	const MAILABLES = [
		ReservationEmailEventType::MESSAGE->value => ObjectsForReviewMessage::class,
		ReservationEmailEventType::CANCELLED_BY_EDITOR->value => ObjectsForReviewCancelByEditor::class,
		ReservationEmailEventType::REMINDER->value => ObjectsForReviewReminder::class,
	];

	/**
	 * Install email templates added in later plugin versions (installation only adds them once).
	 */
	public static function ensureTemplates($plugin, int $contextId): void {
		$missing = false;
		foreach (self::MAILABLES as $mailableClass) {
			if (!Repo::emailTemplate()->dao->getByKey($contextId, $mailableClass::getEmailTemplateKey())) {
				$missing = true;
			}
		}
		if ($missing) {
			$locales = Application::get()->getRequest()->getSite()->getInstalledLocales();
			Repo::emailTemplate()->dao->installEmailTemplates($plugin->getInstallEmailTemplatesFile(), $locales, null, true);
		}
	}

	/**
	 * Values for the template variables.
	 */
	public static function getParams($context, ObjectForReview $object, User $reviewer, ?User $sender, string $locale): array {
		$request = Application::get()->getRequest();
		$dueDate = $object->getData('dueDate');
		$dateFormat = PKPString::convertStrftimeFormat($context->getLocalizedDateFormatShort($locale));
		return [
			'recipientName' => htmlspecialchars($reviewer->getFullName()),
			'objectDescription' => htmlspecialchars(strip_tags($object->getDescription())),
			'dueDate' => $dueDate ? date($dateFormat, strtotime($dueDate)) : '',
			'contextName' => htmlspecialchars($context->getLocalizedName($locale)),
			'senderName' => htmlspecialchars($sender ? $sender->getFullName() : $context->getData('contactName')),
			'forReviewUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'for-review'),
		];
	}

	/**
	 * Subject and body of a template with the variables filled in.
	 * @return array [subject, body]
	 */
	public static function compose($context, ReservationEmailEventType $type, ObjectForReview $object, User $reviewer, ?User $sender): array {
		$locale = $context->getPrimaryLocale();
		$template = Repo::emailTemplate()->getByKey($context->getId(), self::MAILABLES[$type->value]::getEmailTemplateKey());
		$params = self::getParams($context, $object, $reviewer, $sender, $locale);
		return [
			Mail::compileParams((string) $template?->getLocalizedData('subject', $locale), $params),
			Mail::compileParams((string) $template?->getLocalizedData('body', $locale), $params),
		];
	}

	/**
	 * Send an email to the reviewer and record it in the audit trail.
	 * @param ?User $sender null for automatic emails (sent from the journal contact)
	 */
	public static function send($context, ReservationEmailEventType $type, ObjectForReview $object, User $reviewer, ?User $sender,
			string $subject, string $body): void {
		$mailableClass = self::MAILABLES[$type->value];
		$mailable = new $mailableClass($context, []);
		if ($sender) {
			$mailable->sender($sender);
			$from = '"' . $sender->getFullName() . '" <' . $sender->getEmail() . '>';
		} else {
			$mailable->from($context->getData('contactEmail'), $context->getData('contactName'));
			$from = '"' . $context->getData('contactName') . '" <' . $context->getData('contactEmail') . '>';
		}
		$mailable->recipients([$reviewer])->subject($subject)->body($body);
		Mail::send($mailable);
		ReservationLog::logEmail($object, $type, $sender, $reviewer, $from, $subject, $body);
	}
}
