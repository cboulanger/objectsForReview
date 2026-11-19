<?php

/**
 * @file plugins/generic/objectsForReview/ObjectsForReviewSettingsForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewSettingsForm
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief Form for journal managers to modify Objects for Review plugin settings
 */

namespace APP\plugins\generic\objectsForReview;

use APP\core\Application;
use APP\template\TemplateManager;
use APP\plugins\generic\objectsForReview\classes\Reservation;


class ObjectsForReviewSettingsForm extends \PKP\form\Form {

	/** @var int */
	var $_journalId;

	/** @var object */
	var $_plugin;

	/**
	 * Constructor
	 * @param $plugin ObjectsForReviewPlugin
	 * @param $journalId int
	 */
	function __construct($plugin, $journalId) {
		$this->_journalId = $journalId;
		$this->_plugin = $plugin;

		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrDueMonthsMax', 'required',
			'plugins.generic.objectsForReview.settings.dueMonths.invalid',
			fn ($max) => ctype_digit((string) $max) && $max >= 1 && $max <= Reservation::MAX_DUE_MONTHS_LIMIT));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrDueMonthsDefault', 'required',
			'plugins.generic.objectsForReview.settings.dueMonths.invalid',
			fn ($default) => ctype_digit((string) $default) && $default >= 1 && $default <= (int) $this->getData('ofrDueMonthsMax')));
		$this->addCheck(new \PKP\form\validation\FormValidatorCustom($this, 'ofrReminderDays', 'required',
			'plugins.generic.objectsForReview.settings.reminderDays.invalid',
			fn ($days) => ctype_digit((string) $days) && $days <= Reservation::MAX_REMINDER_DAYS));
		$this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
		$this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
	}

	/**
	 * Initialize form data.
	 */
	function initData() {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$this->_data = array(
			'displayAsSubtitle' => $this->_plugin->getSetting($this->_journalId, 'displayAsSubtitle'),
			'displayAsList' => $this->_plugin->getSetting($this->_journalId, 'displayAsList'),
			'onlyReserved' => $this->_plugin->getSetting($this->_journalId, 'onlyReserved'),
			'ofrNotifyEmail' => $this->_plugin->getSetting($this->_journalId, 'ofrNotifyEmail'),
			'ofrInstructions' => $this->_plugin->getSetting($this->_journalId, 'ofrInstructions'),
			'ofrContract' => $this->_plugin->getSetting($this->_journalId, 'ofrContract'),
		);
		[$this->_data['ofrDueMonthsDefault'], $this->_data['ofrDueMonthsMax']] = Reservation::getDueMonths($this->_plugin, $this->_journalId);
		$this->_data['ofrReminderDays'] = Reservation::getReminderDays($this->_plugin, $this->_journalId);
		// Prefill the contract with the default text
		if (empty(array_filter((array) $this->_data['ofrContract']))) {
			$contract = [];
			foreach ($context->getSupportedFormLocales() as $locale) {
				$contract[$locale] = __('plugins.generic.objectsForReview.contract.default', [], $locale);
			}
			$this->_data['ofrContract'] = $contract;
		}
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(array('displayAsSubtitle', 'displayAsList', 'onlyReserved', 'ofrNotifyEmail', 'ofrInstructions', 'ofrContract', 'ofrDueMonthsDefault', 'ofrDueMonthsMax', 'ofrReminderDays'));
	}

	/**
	 * @copydoc Form::fetch()
	 */
	function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request, $template, $display);
	}

	/**
	 * Save settings.
	 */
	function execute(...$functionArgs) {
		$request = Application::get()->getRequest();
		$router = $request->getRouter();
		$context = $router->getContext($request);

		$this->_plugin->updateSetting($this->_journalId, 'displayAsSubtitle', $this->getData('displayAsSubtitle'), 'bool');
		$this->_plugin->updateSetting($this->_journalId, 'displayAsList', $this->getData('displayAsList'), 'bool');
		$this->_plugin->updateSetting($this->_journalId, 'onlyReserved', $this->getData('onlyReserved'), 'bool');
		$this->_plugin->updateSetting($this->_journalId, 'ofrNotifyEmail', $this->getData('ofrNotifyEmail'), 'string');
		$this->_plugin->updateSetting($this->_journalId, 'ofrInstructions', $this->getData('ofrInstructions'));
		$this->_plugin->updateSetting($this->_journalId, 'ofrContract', $this->getData('ofrContract'));
		$this->_plugin->updateSetting($this->_journalId, 'ofrDueMonthsDefault', (int) $this->getData('ofrDueMonthsDefault'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrDueMonthsMax', (int) $this->getData('ofrDueMonthsMax'), 'int');
		$this->_plugin->updateSetting($this->_journalId, 'ofrReminderDays', (int) $this->getData('ofrReminderDays'), 'int');

	}
}

?>
