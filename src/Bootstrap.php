<?php

/**
 * Bootstrap custom module skeleton.  This file is an example custom module that can be used
 * to create modules that can be utilized inside the OpenEMR system.  It is NOT intended for
 * production and is intended to serve as the barebone requirements you need to get started
 * writing modules that can be installed and used in OpenEMR.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\SJICCDAModule;

/**
 * Note the below use statements are importing classes from the OpenEMR core codebase
 */
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Kernel;
use OpenEMR\Events\Core\TwigEnvironmentEvent;
use OpenEMR\Events\Globals\GlobalsInitializedEvent;
use OpenEMR\Events\Main\Tabs\RenderEvent;
use OpenEMR\Events\RestApiExtend\RestApiResourceServiceEvent;
use OpenEMR\Events\PatientDocuments\PatientDocumentCreateCCDAEvent;
use OpenEMR\Events\RestApiExtend\RestApiScopeEvent;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Menu\MenuEvent;
use OpenEMR\Events\RestApiExtend\RestApiCreateEvent;
use OpenEMR\Events\Services\ServiceSaveEvent;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

// This is for processing xml
use DOMDocument;
use XSLTProcessor;

// we import our own classes here.. although this use statement is unnecessary it forces the autoloader to be tested.
use OpenEMR\Modules\SJICCDAModule\CustomSkeletonRestController;


class Bootstrap
{
    const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    const MODULE_NAME = "sji-ccda";
    /**
     * @var EventDispatcherInterface The object responsible for sending and subscribing to events through the OpenEMR system
     */
    private $eventDispatcher;

    /**
     * @var string The folder name of the module.  Set dynamically from searching the filesystem.
     */
    private $moduleDirectoryName;

    /**
     * @var SystemLogger
     */
    private $logger;

    public function __construct(EventDispatcherInterface $eventDispatcher, ?Kernel $kernel = null)
    {
        global $GLOBALS;

        if (empty($kernel)) {
            $kernel = new Kernel();
        }

        $this->moduleDirectoryName = basename(dirname(__DIR__));
        $this->eventDispatcher = $eventDispatcher;

		$this->logger = new SystemLogger();
    }

    public function subscribeToEvents()
    {
        $this->eventDispatcher->addListener( 
            //PatientDocumentCreateCCDAEvent::EVENT_NAME_CCDA_CREATE, [$this, 'addSJICCDA'], -1
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addTextSexAssignedAtBirth']
		);
    }

    // Since this is deprioritized above the care coordination handler 
    // will have already requested the CCDA file from the ccdaservice
    // and written it to disk.  We should be able to pick it up of 
    // the filesystem and modify it with our custom data then write
    // it back to disk
    public function addTextSexAssignedAtBirth(ServiceSaveEvent $event)
    {
		//$this->logger->debug(__FUNCTION__);
        global $srcdir;
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];

		require_once("$srcdir/patient.inc.php");
		$data = getPatientData($pid);
		$gender = $data['gender'];
		$gender_identity = $data['gender_identity'];
		$so = $data['sexual_orientation'];
		$sex = $data['sex'];
		$dob = $data['DOB'];
		$name_string = $data['fname'] .' '. $data['lname'] ." ($pid) $dob";
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		foreach($xmlDom->getElementsByTagName('component') as $component) {
		foreach($component->getElementsByTagName('section') as $section) {
		foreach($section->getElementsByTagName('title') as $title) {
			// $title is a DOMElement Object
			// We expect the Social History section to already be built
			if ($title->nodeValue === 'Social History') {
				$elements = $section->getElementsByTagName('text');
				$text = $elements->item(0);

				$elements = $text->getElementsByTagName('tbody');
				if (!$elements->count()) {
					$this->logger->warning('Social History not built for: '.$name_string);
					return $event;
				}

				$tbody = $elements->item(0);
				//$this->logger->errorLogCaller(print_r(get_class_methods($tbody), 1)); return $event;

				//determine the most recent id
				$ct = 1;
				$socialId = 'social'.$ct;
				foreach($tbody->getElementsByTagName('td') as $td) {
					$value = $td->getAttribute('ID');
					if ($value) {
						//$this->logger->errorLogCaller('ID: '. print_r($value, 1));
						$ct = $ct + 1;
						$socialId = 'social'.$ct;
					}
				} 
				//$this->logger->errorLogCaller('Social ID: '. print_r($socialId, 1));

				// Insert sex assigned at birth
				$tr = $xmlDom->createElement('TR');
				$td = $xmlDom->createElement('TD');
				$td->setAttribute('ID', $socialId);
				$td->nodeValue = 'Birth Sex';
				$tr->appendChild($td);

				$td = $xmlDom->createElement('TD');
				$td->nodeValue = $sex;
				$tr->appendChild($td);

				$td = $xmlDom->createElement('TD');
				$td->nodeValue = $dob;
				$tr->appendChild($td);

				$tbody->appendChild($tr);
				//$this->logger->errorLogCaller('TBODY: '. print_r($xmlDom->saveXML($tbody), 1));

				// TODO: Insert sexual orientation
				// TODO: Insert gender identity
				break 3;
			}
			//exit;
		} // title
		} // section
		} // component
		
		// This is an example of how we can embed ourselves in the xml
		/*
		if (substr_count($content, '</ClinicalDocument>') == 2) {
            $d = explode('</ClinicalDocument>', $content);
            $content = $d[0] . '</ClinicalDocument>';
            $unstructured = $d[1] . '</ClinicalDocument>';
        }
		*/
		$save = Array('pid' => $pid, 'CCDA' => $xmlDom->saveXML());
		$event->setSaveData($save);

		// TODO: sexual preference
		// TODO: gender identity
		// TODO: all visits and encounters
		// TODO: all groups

	    return $event;
    }

    private function getPublicPath()
    {
        return self::MODULE_INSTALLATION_PATH . ($this->moduleDirectoryName ?? '') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
    }

    private function getAssetPath()
    {
        return $this->getPublicPath() . 'assets' . DIRECTORY_SEPARATOR;
    }

    public function getTemplatePath()
    {
        return \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
    }
}
