<?php

/**
 * @file plugins/generic/objectsForReview/ObjectsForReviewPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ObjectsForReviewPlugin
 * @ingroup plugins_generic_objectsForReview
 * @brief Add objectsForReview data to the submission metadata and display them on the submission view page.
 *
 */

namespace APP\plugins\generic\objectsForReview;

use APP\core\Application;
use APP\pages\submission\SubmissionHandler;
use APP\plugins\generic\objectsForReview\classes\ObjectForReview;
use APP\plugins\generic\objectsForReview\classes\ObjectForReviewDAO;

use APP\plugins\generic\objectsForReview\controllers\grid\ObjectsForReviewGridHandler;
use APP\plugins\generic\objectsForReview\controllers\grid\ObjectsForReviewManagementGridHandler;
use APP\plugins\generic\objectsForReview\ObjectsForReviewSchemaMigration;
use APP\plugins\generic\objectsForReview\pages\ObjectsForReviewHandler;
use APP\plugins\generic\objectsForReview\pages\ObjectsForReviewManagementHandler;

use APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewNew;
use APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewCancel;

use APP\facades\Repo;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\LinkAction;
use PKP\core\JSONMessage;
use APP\notification\NotificationManager;
use PKP\notification\Notification;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use APP\plugins\generic\objectsForReview\classes\ReservationReminder;
use PKP\security\Role;
use Illuminate\Support\Facades\Mail;


define('OBJECTSFORREVIEW_NMI_TYPE', 'OBJECTSFORREVIEW_NMI');

class ObjectsForReviewPlugin extends GenericPlugin implements HasTaskScheduler {

	/**
	 * @copydoc Plugin::getName()
	 */
	function getName() {
		return 'ObjectsForReviewPlugin';
    }

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
    function getDisplayName() {
		return __('plugins.generic.objectsForReview.displayName');
    }

	/**
	 * @copydoc Plugin::getDescription()
	 */
    function getDescription() {
		return __('plugins.generic.objectsForReview.description');
    }

	/**
	 * @see PKPPlugin::getInstallEmailTemplatesFile()
	 */
	function getInstallEmailTemplatesFile() {
		return ($this->getPluginPath() . '/emailTemplates.xml');
	}


	/**
	 * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
	 */
	public function registerSchedules(PKPScheduler $scheduler): void {
		$scheduler
			->addSchedule(new ReservationReminder())
			->daily()
			->name(ReservationReminder::class)
			->withoutOverlapping();
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() 
                ? [
                    new LinkAction(
                        'settings',
                        new AjaxModal(
                            $router->url(
                                $request,
                                null,
                                null,
                                'manage',
                                null,
                                [
                                    'verb'      => 'settings',
                                    'plugin'    => $this->getName(),
                                    'category'  => 'generic'
                                ]
                            ),
                            $this->getDisplayName()
                        ),
                        __('manager.plugins.settings'),
                        null
                    ),
                ] : [],
            parent::getActions($request, $verb)
        );
    }

	/**
	 * @copydoc Plugin::manage()
	 */
    public function manage($args, $request) 
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);
                // Standalone: embedded in the backend settings page instead of a modal
                $templateMgr->assign('standalone', (bool) $request->getUserVar('standalone'));
                $form = new ObjectsForReviewSettingsForm($this, $context->getId());

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        (new NotificationManager())->createTrivialNotification(
                            $request->getUser()->getId(),
                            Notification::NOTIFICATION_TYPE_SUCCESS,
                            ['contents' => __('common.changesSaved')]
                        );
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }

                return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }

	/**
	 * @copydoc Plugin::register()
	 */
    function register($category, $path, $mainContextId = null) {

		$success = parent::register($category, $path, $mainContextId);
		if ($success && $this->getEnabled($mainContextId)) {

			$request = Application::get()->getRequest();
			$context = $request->getContext();

			$objectForReviewDao = new ObjectForReviewDAO();
			DAORegistry::registerDAO('ObjectForReviewDAO', $objectForReviewDao);

            Hook::add('TemplateManager::display', $this->addToSubmissionWizardSteps(...));
            Hook::add('Template::SubmissionWizard::Section', $this->addToSubmissionWizardTemplate(...));
            Hook::add('Template::SubmissionWizard::Section::Review', $this->addToSubmissionWizardReviewTemplate(...));

			// OJS 3.5: the workflow page is Vue-based; the publication tab is added in js/Workflow.js
			// (the Template::Workflow::Publication hook was removed, see pkp/pkp-lib#10766)

			Hook::Add('LoadComponentHandler', array($this, 'setupGridHandler'));
			Hook::Add('TemplateManager::display',array($this, 'addJs'));

			Hook::add('TemplateManager::setupBackendPage', [$this, 'addBackendMenu']);

			// if list display is enabled
			if ($this->getSetting($context->getId(), 'displayAsList')) {
				Hook::Add('Templates::Article::Main', array($this, 'addSubmissionDisplay'));
				Hook::Add('Templates::Catalog::Book::Main', array($this, 'addSubmissionDisplay'));
			}

			// Handler for public objects for review page
			Hook::Add('LoadHandler', array($this, 'loadPageHandler'));
			Hook::Add('NavigationMenus::itemTypes', array($this, 'addMenuItemTypes'));
			Hook::Add('NavigationMenus::displaySettings', array($this, 'setMenuItemDisplayDetails'));
			Hook::Add('SitemapHandler::createJournalSitemap', array($this, 'addSitemapURLs'));

			Hook::add('Mailer::Mailables', [$this, 'addMailable']);



		}
		return $success;
	}

	/**
	 * Add the "Objects for Review" section to the backend's left menu.
	 * Hooked to TemplateManager::setupBackendPage.
	 */
	public function addBackendMenu($hookName, $args) {
		$request = Application::get()->getRequest();
		$router = $request->getRouter();
		if (!$request->getContext() || !$request->getUser() || !$router || !$router->getHandler()) {
			return false;
		}
		$userRoles = (array) $router->getHandler()->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
		if (!array_intersect([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN], $userRoles)) {
			return false;
		}

		$page = ObjectsForReviewManagementHandler::PAGE;
		$onPage = $request->getRequestedPage() === $page;
		$requestedOp = $request->getRequestedOp() ?: 'index';
		$submenu = [];
		foreach (['index' => 'available', 'reservations' => 'reservations', 'reviewedWorks' => 'reviewedWorks', 'settings' => 'settings'] as $op => $key) {
			$submenu[$key] = [
				'name' => __('plugins.generic.objectsForReview.menu.' . $key),
				'url' => $router->url($request, null, $page, $op),
				'isCurrent' => $onPage && $requestedOp === $op,
			];
		}
		$submenu['publicPage'] = [
			'name' => __('plugins.generic.objectsForReview.menu.publicPage'),
			'url' => $router->url($request, null, 'for-review'),
		];
		$entry = [
			'name' => __('plugins.generic.objectsForReview.managementLink'),
			'icon' => 'Book',
			'submenu' => $submenu,
		];

		// Place the section before "Settings" (or at the end)
		$templateMgr = TemplateManager::getManager($request);
		$menu = (array) $templateMgr->getState('menu');
		$pos = array_search('settings', array_keys($menu), true);
		$menu = $pos === false
			? $menu + ['objectsForReview' => $entry]
			: array_slice($menu, 0, $pos, true) + ['objectsForReview' => $entry] + array_slice($menu, $pos, null, true);
		$templateMgr->setState(['menu' => $menu]);
		return false;
	}

	/**
	 * Permit requests to the ObjectsForReview grid handler
	 * @param $hookName string The name of the hook being invoked
	 * @param $args array The parameters to the invoked hook
	 */
	function setupGridHandler($hookName, $params) {
		$component =& $params[0];
		$componentInstance =& $params[2];

		if ($component === 'plugins.generic.objectsForReview.controllers.grid.ObjectsForReviewGridHandler') {
			$componentInstance = new controllers\grid\ObjectsForReviewGridHandler($this);
			return true;
		}

		if ($component === 'plugins.generic.objectsForReview.controllers.grid.ObjectsForReviewManagementGridHandler') {
			$componentInstance = new controllers\grid\ObjectsForReviewManagementGridHandler($this);
			return true;
		}

		if ($component === controllers\ReservationActionsHandler::COMPONENT) {
			$componentInstance = new controllers\ReservationActionsHandler($this);
			return true;
		}
		
		return false;
	}

    /**
     * Inject a object for review section into the submission wizard steps UI.
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool Hook return value
     */
    function addToSubmissionWizardSteps($hookName, $params) {
        $request = Application::get()->getRequest();

        if ($request->getRequestedPage() !== 'submission') {
            return;
        }

        if ($request->getRequestedOp() === 'saved') {
            return;
        }

        $submission = $request
            ->getRouter()
            ->getHandler()
            ->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);

        if (!$submission || !$submission->getData('submissionProgress')) {
            return;
        }

        /** @var ObjectForReviewDAO $objectForReviewDao */
        $objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
        $objectForReviewResult = $objectForReviewDao->getBySubmissionId($submission->getId());

        $objectsForReview = [];
        while ($objectForReview = $objectForReviewResult->next()) {
            $objectsForReview[] = $this->getObjectForReviewData($objectForReview);
        }

        /** @var TemplateManager $templateMgr */
        $templateMgr = $params[0];

        $steps = $templateMgr->getState('steps');
        $steps = array_map(function($step) {
            if ($step['id'] === 'editors') {
                $step['sections'][] = [
                    'id' => 'objectsForReview',
                    'name' => __('plugins.generic.objectsForReview.submissionWizard.name'),
                    'type' => SubmissionHandler::SECTION_TYPE_TEMPLATE,
                ];
            }
            return $step;
        }, $steps);

        $templateMgr->setState([
            'objectsForReview' => $objectsForReview,
            'steps' => $steps,
        ]);

        $templateMgr->addJavaScript(
            'plugin-objectForReview-submission-wizard',
            $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/SubmissionWizard.js',
            [
                'contexts' => 'backend',
                'priority' => TemplateManager::STYLE_SEQUENCE_LATE,
            ]
        );

        return false;
    }

    /**
     * Render the object for review section content inside the submission wizard.
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool Hook return value
     */
    function addToSubmissionWizardTemplate($hookName, $params) {
        $smarty = $params[1];
        $output =& $params[2];

        $output .= sprintf(
            '<template v-else-if="section.id === \'objectsForReview\'">%s</template>',
            $smarty->fetch($this->getTemplateResource('objectsForReviewGrid.tpl'))
        );

        return false;
    }

    /**
     * Format object for review entity as an associative array for frontend or API use.
     *
     * @param ObjectForReview $objectForReview
     *
     * @return array
     */
	public function getObjectForReviewData(ObjectForReview $objectForReview): array
	{
		return [
			'id' => $objectForReview->getId(),
			'identifier' => $objectForReview->getIdentifier(),
			'identifierType' => $objectForReview->getIdentifierType(),
			'resourceType' => $objectForReview->getResourceType(),
			'authors' => $objectForReview->getAuthors(),
			'title' => $objectForReview->getTitle(),
			'publisher' => $objectForReview->getPublisher(),
			'year' => $objectForReview->getYear(),
		];
	}

    /**
     * Add a review panel for the object for review data in the final step of the wizard.
     *
     * @param string $hookName
     * @param array $params
     *
     * @return bool Hook return value
     */
    function addToSubmissionWizardReviewTemplate($hookName, $params) {
        $submission = $params[0]['submission']; /** @var Submission $submission */
        $step = $params[0]['step']; /** @var string $step */
        $templateMgr = $params[1]; /** @var TemplateManager $templateMgr */
        $output =& $params[2];

        if ($step === 'editors') {
            $output .= $templateMgr->fetch($this->getTemplateResource('reviewObjectsForReview.tpl'));
        }

        return false;
    }

	/**
	* Hook to Templates::Article::Details and Templates::Catalog::Book::Details and list object for review information
	* @param $hookName string
	* @param $params array
	*/
	function addSubmissionDisplay($hookName, $params) {
		$templateMgr = $params[1];
		$output =& $params[2];

		$submission = $templateMgr->getTemplateVars('monograph') ? $templateMgr->getTemplateVars('monograph') : $templateMgr->getTemplateVars('article');

		$objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
		$objectsForReview = $objectForReviewDao->getBySubmissionId($submission->getId());

		if ($objectsForReview){
			$templateData = array();

			while ($objectForReview = $objectsForReview->next()) {
				$objectId = $objectForReview->getId();
				$templateData[$objectId] = array(
					'identifierType' => $objectForReview->getIdentifierType(),
					'identifier' => $objectForReview->getIdentifier(),
					'identifierUrl' => $objectForReview->getIdentifierUrl(),
					'description' => $objectForReview->getDescription()
				);
			}

			if ($objectsForReview){
				$templateMgr->assign('objectsForReview', $templateData);
				$output .= $templateMgr->fetch($this->getTemplateResource('listReviews.tpl'));
			}

		}

		return false;
	}

	/**
	* Hook to ArticleDAO::_fromRow and MonographDAO::_fromRow and display objectForReview as subtitle
	* @param $hookName string
	* @param $params array
	*/
	function addSubtitleDisplay($hookName, $params) {
		// NOTE Not working in 3.2, need to rewrite this
		$submission =& $params[0];

		$objectForReviewDao = DAORegistry::getDAO('ObjectForReviewDAO');
		$objectsForReview = $objectForReviewDao->getBySubmissionId($submission->getId());

		if ($objectsForReview){
			$objects = array();
			while ($objectForReview = $objectsForReview->next()) {
				$objects[] = $objectForReview->getDescription();
			}

			if ($objects){
				$publication->setSubtitle(implode(" ▪ ", $objects), $publication->getLocale());
			}
		}

		return false;
	}

	/**
	 * @copydoc Plugin::getInstallMigration()
	 */
	function getInstallMigration() {
		return new ObjectsForReviewSchemaMigration();
	}

	/**
	 * Add custom js for backend and frontend
	 */
	function addJs($hookName, $params) {
		$templateMgr = $params[0];
		$template =& $params[1];
		$request = Application::get()->getRequest();

		$gridHandlerJs = $this->getJavaScriptURL($request, false) . '/ObjectsForReviewGridHandler.js';
		$templateMgr->addJavaScript(
			'ObjectsForReviewGridHandlerJs',
			$gridHandlerJs,
			array('contexts' => 'backend')
		);
		// Configuration and registry extensions for the OJS 3.5 workflow page
		$gridUrl = $request->getDispatcher()->url(
			$request,
			Application::ROUTE_COMPONENT,
			null,
			'plugins.generic.objectsForReview.controllers.grid.ObjectsForReviewGridHandler',
			'fetchGrid',
			null,
			['submissionId' => '__submissionId__']
		);
		$templateMgr->addJavaScript(
			'ObjectsForReviewWorkflowConfig',
			'window.pkpObjectsForReview = ' . json_encode([
				'gridUrl' => $gridUrl,
				'label' => __('plugins.generic.objectsForReview.management.gridTitle'),
			]) . ';',
			[
				'inline' => true,
				'contexts' => 'backend',
			]
		);
		// The backend side menu ignores link targets: open the "Public page" entry in a new tab
		if ($request->getContext()) {
			$publicUrl = $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'for-review');
			$templateMgr->addJavaScript(
				'ObjectsForReviewPublicPageLink',
				'document.addEventListener("click", function (e) {'
					. ' var a = e.target.closest && e.target.closest("a[href]");'
					. ' if (!a || a.getAttribute("href") !== ' . json_encode($publicUrl) . ' || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;'
					. ' e.preventDefault(); e.stopPropagation();'
					. ' window.open(a.href, "_blank", "noopener");'
					. '}, true);',
				[
					'inline' => true,
					'contexts' => 'backend',
				]
			);
		}
		$templateMgr->addJavaScript(
			'ObjectsForReviewWorkflowJs',
			$this->getJavaScriptURL($request, false) . '/Workflow.js',
			[
				'contexts' => 'backend',
				'priority' => TemplateManager::STYLE_SEQUENCE_LAST,
			]
		);
		$templateMgr->addStylesheet(
			'ObjectsForReviewGridHandlerStyles',
			'.objectsForReviewWorkflowGrid { margin: 1rem; } .orIdLookup .orIdLookupButton, .orIdLookup .orIdLookupStatus { margin-left: 0.5em; }'
				. ' .objectsForReviewManagement .ofrTable { width: 100%; border-collapse: collapse; }'
				. ' .objectsForReviewManagement .ofrTable th, .objectsForReviewManagement .ofrTable td { text-align: left; vertical-align: top; padding: 0.75rem 1rem; border-bottom: 1px solid #ddd; }'
				. ' .objectsForReviewManagement .ofrIdentifier { margin-top: 0.25rem; font-size: 0.875rem; }'
				. ' .objectsForReviewManagement .ofrFilter { margin-bottom: 1rem; }'
				. ' .objectsForReviewManagement .ofrNoWrap { white-space: nowrap; }'
				. ' .objectsForReviewManagement .ofrActions a { display: block; white-space: nowrap; margin-bottom: 0.25rem; }'
				. ' .objectsForReviewManagement .ofrDetail { margin-top: 0.25rem; font-size: 0.875rem; }'
				. ' .objectsForReviewManagement .ofrFilter label { margin-right: 0.5rem; font-weight: bold; }',
			[
				'inline' => true,
				'contexts' => 'backend',
			]
		);

		if (strpos($template, 'frontend/pages/forReview.tpl')) {
			$tablesortJs = $this->getJavaScriptURL($request, false) . '/tablesort/src/tablesort.js';
			$templateMgr->addJavaScript(
				'TableSortJs',
				$tablesortJs,
				array('contexts' => 'frontend')
			);
			$tablesortCss = $request->getBaseUrl() . '/plugins/generic/objectsForReview/style.css';
			$templateMgr->addStyleSheet('tablesortCss', $tablesortCss);
		}

		return false;
	}

	/**
	 * Get the JavaScript URL for this plugin.
	 */
	function getJavaScriptURL() {
		$request = Application::get()->getRequest();
		return $request->getBaseUrl() . '/' . $this->getPluginPath() . '/js';
	}

	/**
	 * Load the handler to deal with browse by section page requests
	 *
	 * @param $hookName string `LoadHandler`
	 * @param $args array [
	 * 		@option string page
	 * 		@option string op
	 * 		@option string sourceFile
	 * ]
	 * @return bool
	 */
	public function loadPageHandler($hookName, $args) {
        $page = $args[0];
        if ($this->getEnabled() && $page === 'for-review') {
			$handler =& $args[3];
            $handler = new ObjectsForReviewHandler($this);
            return true;
        }
        if ($this->getEnabled() && $page === ObjectsForReviewManagementHandler::PAGE) {
			$handler =& $args[3];
            $handler = new ObjectsForReviewManagementHandler($this);
            return true;
        }
        return false;
	}

	/**
	 * Add Navigation Menu Item types for linking to objects for review page
	 *
	 * @param $hookName string
	 * @param $args array [
	 *		@option array Existing menu item types
	 * ]
	 */
	public function addMenuItemTypes($hookName, $args) {
		$types =& $args[0];
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$contextId = $context ? $context->getId() : Application::CONTEXT_ID_NONE;
		$types[OBJECTSFORREVIEW_NMI_TYPE] = array(
			'title' => __('plugins.generic.objectsForReview.navMenuItem'),
			'description' => __('plugins.generic.objectsForReview.navMenuItem.description'),
		);
	}

	/**
	 * Set the display details for the custom menu item types
	 *
	 * @param $hookName string
	 * @param $args array [
	 *		@option NavigationMenuItem
	 * ]
	 */
	public function setMenuItemDisplayDetails($hookName, $args) {
		$navigationMenuItem =& $args[0];
		if ($navigationMenuItem->getType() == OBJECTSFORREVIEW_NMI_TYPE) {
			$request = Application::get()->getRequest();
			$context = $request->getContext();
			if ($context){
				$dispatcher = $request->getDispatcher();
				$navigationMenuItem->setUrl($dispatcher->url(
					$request,
					Application::ROUTE_PAGE,
					null,
					'for-review'
				));
			}
		}
	}

	/**
	 * Add the objects for review page URL to the sitemap
	 *
	 * @param $hookName string
	 * @param $args array
	 * @return boolean
	 */
	function addSitemapURLs($hookName, $args) {
		$doc = $args[0];
		$rootNode = $doc->documentElement;
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if ($context) {
			// Create and append sitemap XML "url" element
			$url = $doc->createElement('url');
			$url->appendChild($doc->createElement('loc', htmlspecialchars($request->url($context->getPath(), 'for-review'), ENT_COMPAT, 'UTF-8')));
			$rootNode->appendChild($url);
		}
		return false;
	}

    /**
     * Add mailable to the list of mailables in the application
     */
    public function addMailable(string $hookName, array $args): void
    {
        $args[0]->push(ObjectsForReviewNew::class);
		$args[0]->push(ObjectsForReviewCancel::class);
		$args[0]->push(\APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewMessage::class);
		$args[0]->push(\APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewCancelByEditor::class);
		$args[0]->push(\APP\plugins\generic\objectsForReview\mailables\ObjectsForReviewReminder::class);
    }
	
	/**
	 * Send mail to editor when object is reserved or cancelled
	 *
	 * @param User $user
	 * @param $object
	 * @param $template Send either the reserve or cancel mail
	 */
	public function notifyEditor($user, $objectDescription, $mailTemplate, $reservationDetails = '') {

		$request = Application::get()->getRequest();
		$context = $request->getContext();

		// This should only ever happen within a context, never site-wide.
		assert($context != null);
		$contextId = $context->getId();

        $emailParams = [
            'objectDescription' => htmlspecialchars($objectDescription),
            'senderUsername' => htmlspecialchars($user->getFullName()),
            'senderEmail' => htmlspecialchars($user->getEmail()),
            // variable names used by the original en/de templates
            'userName' => htmlspecialchars($user->getFullName()),
            'userEmail' => htmlspecialchars($user->getEmail()),
            // already escaped HTML (see Reservation::getEmailDetails())
            'reservationDetails' => $reservationDetails,
        ];

		if ($mailTemplate == 'OFR_NEW_RESERVATION'){
			$mailable = new ObjectsForReviewNew($context, $emailParams);
		} elseif ($mailTemplate == 'OFR_CANCEL_RESERVATION'){
			$mailable = new ObjectsForReviewCancel($context, $emailParams);
		} 

		if ($this->getSetting($contextId, 'ofrNotifyEmail')){
			$mailTo = $this->getSetting($contextId, 'ofrNotifyEmail');
		} else{
			$mailTo = $context->getData('contactEmail');
		}

		$template = Repo::emailTemplate()->getByKey($context->getId(), $mailable::getEmailTemplateKey());
		$locale = $context->getPrimaryLocale();
		$body = (string) $template->getLocalizedData('body', $locale);
		// Templates installed before the reservation details existed have no placeholder for them
		if ($reservationDetails !== '' && strpos($body, '{$reservationDetails}') === false) {
			$body .= '<br />{$reservationDetails}';
		}
		$mailable
			->sender($user)
			->to($mailTo)
			->subject($template->getLocalizedData('subject', $locale))
			->body($body);

		Mail::send($mailable);

	}
}
?>
