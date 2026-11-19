<?php

/**
 * @file plugins/generic/objectsForReview/classes/ReservationReminder.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReservationReminder
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Daily task: remind reviewers a configurable number of days before
 *  their review is due (once per reservation; recorded in the audit trail).
 */

namespace APP\plugins\generic\objectsForReview\classes;

use APP\core\Application;
use APP\facades\Repo;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTask;

class ReservationReminder extends ScheduledTask {

	/**
	 * @copydoc ScheduledTask::getName()
	 */
	public function getName(): string {
		return __('plugins.generic.objectsForReview.reminder.taskName');
	}

	/**
	 * @copydoc ScheduledTask::executeActions()
	 */
	protected function executeActions(): bool {
		$plugin = PluginRegistry::getPlugin('generic', 'ObjectsForReviewPlugin');
		$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
		$objectDao = new ObjectForReviewDAO();
		$today = new \DateTimeImmutable('today');

		$contexts = Application::getContextDAO()->getAll(true);
		while ($context = $contexts->next()) {
			if (!$pluginSettingsDao->getSetting($context->getId(), 'objectsforreviewplugin', 'enabled')) {
				continue;
			}
			$days = Reservation::getReminderDays($plugin, $context->getId());
			if ($days <= 0) {
				continue;
			}
			$latestDueDate = $today->modify("+{$days} days")->format('Y-m-d');
			$sent = 0;

			foreach ($objectDao->getAll($context->getId())->toArray() as $object) {
				$dueDate = $object->getData('dueDate');
				if (!$object->getUserId() || $object->getSubmissionId() || !$dueDate || $object->getData('reminderSent')
					|| $dueDate < $today->format('Y-m-d') || $dueDate > $latestDueDate) {
					continue;
				}
				$reviewer = Repo::user()->get((int) $object->getUserId(), true);
				if (!$reviewer) {
					continue;
				}
				ReservationMailer::ensureTemplates($plugin, $context->getId());
				[$subject, $body] = ReservationMailer::compose($context, ReservationEmailEventType::REMINDER, $object, $reviewer, null);
				ReservationMailer::send($context, ReservationEmailEventType::REMINDER, $object, $reviewer, null, $subject, $body);
				$object->setData('reminderSent', Core::getCurrentDate());
				$objectDao->updateObject($object);
				$sent++;
			}
			if ($sent) {
				$this->addExecutionLogEntry(__('plugins.generic.objectsForReview.reminder.sent', ['count' => $sent, 'context' => $context->getLocalizedName()]));
			}
		}
		return true;
	}
}
