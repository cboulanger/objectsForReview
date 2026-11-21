<?php

/**
 * @file plugins/generic/objectsForReview/classes/Reservation.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class Reservation
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Reservation terms: contract text, due date limits, delivery
 *  preferences, validation of the contract form and the contract data
 *  stored with an Assignment.
 */

namespace APP\plugins\generic\objectsForReview\classes;

use PKP\core\Core;
use PKP\core\PKPString;
use PKP\facades\Locale;

class Reservation {

	const COPY_HAVE = 'have';
	const COPY_PHYSICAL = 'physical';
	const COPY_ELECTRONIC = 'electronic';
	const COPY_OTHER = 'other';
	const COPY_PREFERENCES = [self::COPY_HAVE, self::COPY_PHYSICAL, self::COPY_ELECTRONIC, self::COPY_OTHER];

	const DEFAULT_DUE_MONTHS = 3;
	const DEFAULT_MAX_DUE_MONTHS = 6;
	const MAX_DUE_MONTHS_LIMIT = 24;
	const DEFAULT_REMINDER_DAYS = 14;
	const MAX_REMINDER_DAYS = 90;

	/**
	 * Days before the due date on which the reminder is sent (0: no reminder).
	 */
	public static function getReminderDays($plugin, int $contextId): int {
		$days = $plugin->getSetting($contextId, 'ofrReminderDays');
		return $days === null ? self::DEFAULT_REMINDER_DAYS : (int) $days;
	}

	/** Contract data stored as assignment settings (see AssignmentDAO::getAdditionalFieldNames()) */
	const FIELDS = ['dateReserved', 'dueDate', 'copyPreference', 'shippingAddress', 'phone', 'copyNote', 'reminderSent',
		'copyPreferences'];

	/** Personal data that is erased once it is no longer needed */
	const PERSONAL_FIELDS = ['shippingAddress', 'phone'];

	/**
	 * @return array [defaultMonths, maxMonths]
	 */
	public static function getDueMonths($plugin, int $contextId): array {
		$max = (int) $plugin->getSetting($contextId, 'ofrDueMonthsMax') ?: self::DEFAULT_MAX_DUE_MONTHS;
		$default = (int) $plugin->getSetting($contextId, 'ofrDueMonthsDefault') ?: self::DEFAULT_DUE_MONTHS;
		return [min($default, $max), $max];
	}

	/**
	 * Allowed due date range and default, as Y-m-d strings.
	 * @return array ['min' => ..., 'max' => ..., 'default' => ...]
	 */
	public static function getDueDateRange($plugin, int $contextId): array {
		[$defaultMonths, $maxMonths] = self::getDueMonths($plugin, $contextId);
		$today = new \DateTimeImmutable('today');
		return [
			'min' => $today->modify('+1 day')->format('Y-m-d'),
			'max' => $today->modify("+{$maxMonths} months")->format('Y-m-d'),
			'default' => $today->modify("+{$defaultMonths} months")->format('Y-m-d'),
		];
	}

	/**
	 * The contract text in the current locale (falling back to the primary locale and the default text).
	 */
	public static function getContract($plugin, $context): string {
		$contract = (array) $plugin->getSetting($context->getId(), 'ofrContract');
		$text = ($contract[Locale::getLocale()] ?? null) ?: ($contract[$context->getPrimaryLocale()] ?? null);
		return $text ?: __('plugins.generic.objectsForReview.contract.default');
	}

	/**
	 * Validate the reservation form.
	 * @param array $input Raw request values
	 * @return array [errors (field => locale key), data (normalized reservation fields)]
	 */
	public static function validate(array $input, array $dueDateRange): array {
		$errors = [];
		$data = array_fill_keys(self::FIELDS, null);

		if (empty($input['acceptTerms'])) {
			$errors['acceptTerms'] = 'plugins.generic.objectsForReview.reserve.error.acceptTerms';
		}

		$dueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['dueDate'] ?? ''));
		if (!$dueDate || $dueDate->format('Y-m-d') !== ($input['dueDate'] ?? '')) {
			$errors['dueDate'] = 'plugins.generic.objectsForReview.reserve.error.dueDate';
		} elseif ($input['dueDate'] < $dueDateRange['min'] || $input['dueDate'] > $dueDateRange['max']) {
			$errors['dueDate'] = 'plugins.generic.objectsForReview.reserve.error.dueDateRange';
		} else {
			$data['dueDate'] = $input['dueDate'];
		}

		$preference = (string) ($input['copyPreference'] ?? '');
		if (!in_array($preference, self::COPY_PREFERENCES, true)) {
			$errors['copyPreference'] = 'plugins.generic.objectsForReview.reserve.error.copyPreference';
		} else {
			$data['copyPreference'] = $preference;
		}

		if ($preference === self::COPY_PHYSICAL) {
			$data['shippingAddress'] = trim((string) ($input['shippingAddress'] ?? ''));
			$data['phone'] = trim((string) ($input['phone'] ?? ''));
			if ($data['shippingAddress'] === '') {
				$errors['shippingAddress'] = 'plugins.generic.objectsForReview.reserve.error.shippingAddress';
			}
			if (!preg_match('/^\+?[0-9 ()\/.\-]{6,30}$/', $data['phone'])) {
				$errors['phone'] = 'plugins.generic.objectsForReview.reserve.error.phone';
			}
		} elseif ($preference === self::COPY_OTHER) {
			$data['copyNote'] = trim((string) ($input['copyNote'] ?? ''));
			if ($data['copyNote'] === '') {
				$errors['copyNote'] = 'plugins.generic.objectsForReview.reserve.error.copyNote';
			}
		}

		$data['dateReserved'] = Core::getCurrentDate();
		return [$errors, $data];
	}

	/**
	 * Store the contract data with the assignment.
	 */
	public static function apply(Assignment $object, array $data): void {
		foreach (self::FIELDS as $field) {
			if (($data[$field] ?? '') === '') {
				$object->unsetData($field); // unset fields are deleted from the settings table
			} else {
				$object->setData($field, $data[$field]);
			}
		}
	}

	/**
	 * Erase personal data that is no longer needed (when the review is attached
	 * to a submission, or the assignment ends).
	 */
	public static function erasePersonalData(Assignment $object): void {
		foreach (self::PERSONAL_FIELDS as $field) {
			$object->unsetData($field);
		}
	}

	/**
	 * Contract data of an assignment for display.
	 */
	public static function getDisplayData(Assignment $object): array {
		$preference = $object->getData('copyPreference');
		return [
			'dateReserved' => $object->getData('dateReserved'),
			'dueDate' => $object->getData('dueDate'),
			'copyPreference' => $preference,
			'copyPreferenceLabel' => $preference ? __('plugins.generic.objectsForReview.reserve.copy.' . $preference) : null,
			'shippingAddress' => $object->getData('shippingAddress'),
			'phone' => $object->getData('phone'),
			'copyNote' => $object->getData('copyNote'),
			'reminderSent' => $object->getData('reminderSent'),
		];
	}

	/**
	 * Reservation details as HTML for the notification email, in the email's locale.
	 */
	public static function getEmailDetails(Assignment $object, $context, ?string $locale = null): string {
		$locale = $locale ?? $context->getPrimaryLocale();
		$t = fn ($key) => __('plugins.generic.objectsForReview.' . $key, [], $locale);
		$dateFormat = PKPString::convertStrftimeFormat($context->getLocalizedDateFormatShort($locale));
		$preference = $object->getData('copyPreference');
		$rows = [];
		if ($object->getData('dueDate')) {
			$rows[] = [$t('reserve.dueDate'), date($dateFormat, strtotime($object->getData('dueDate')))];
		}
		if ($preference) {
			$rows[] = [$t('reserve.copy'), $t('reserve.copy.' . $preference)];
		}
		foreach (['shippingAddress' => 'reserve.shippingAddress', 'phone' => 'reserve.phone', 'copyNote' => 'reserve.copyNote'] as $field => $labelKey) {
			if ($object->getData($field)) {
				$rows[] = [$t($labelKey), $object->getData($field)];
			}
		}
		return implode('', array_map(
			fn ($row) => htmlspecialchars($row[0]) . ': ' . nl2br(htmlspecialchars($row[1])) . '<br />',
			$rows
		));
	}
}
