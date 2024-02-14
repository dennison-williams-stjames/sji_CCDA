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
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addSJICCDA']
		);
    }

    // Since this is deprioritized above the care coordination handler 
    // will have already requested the CCDA file from the ccdaservice
    // and written it to disk.  We should be able to pick it up of 
    // the filesystem and modify it with our custom data then write
    // it back to disk
    public function addSJICCDA(PatientDocumentCreateCCDAEvent $event)
    {
        global $srcdir;
	    $this->logger->errorLogCaller(__FUNCTION__); 
		return;

	    //$this->logger->errorLogCaller('PatientDocumentCreateCCDAEvent received');
	    //$this->logger->errorLogCaller(print_r($event->getSections(), 1));
	    //$this->logger->errorLogCaller(print_r($event->getFileUrl(), 1));
	    //$this->logger->errorLogCaller(print_r($event->getCcdaId(), 1));
	    //$this->logger->errorLogCaller(print_r($event->getRecipient(), 1));
	    //$this->logger->errorLogCaller(print_r($event->getFormat(), 1));
	    //$this->logger->errorLogCaller(print_r($event->getComponents(), 1));
	    $this->logger->errorLogCaller(print_r($event->getContent(), 1)); exit;
	    //$this->logger->errorLogCaller(print_r($event->getPid(), 1)); exit;
		//$this->logger->errorLogCaller(print_r(get_class_methods($event), 1)); exit;
		//$this->logger->errorLogCaller(print_r($GLOBALS, 1)); exit;
		//$this->logger->errorLogCaller(print_r(array_keys(get_defined_vars()), 1)); exit;
		//$this->logger->errorLogCaller(print_r($srcdir, 1)); exit;
		require_once("$srcdir/patient.inc.php");
		$pid = $event->getPid();
		$data = getPatientData($pid);
		//print_r(array_keys($data));
		$gender = $data['gender'];
		$gender_identity = $data['gender_identity'];
		$so = $data['sexual_orientation'];
		$sex = $data['sex'];
		$dob = $data['DOB'];
		//$this->logger->errorLogCaller($so);
		//$this->logger->errorLogCaller($gender);
		//$this->logger->errorLogCaller($gender_identity);
		//$this->logger->errorLogCaller(print_r($data, 1)); exit;

		// should only be one
        $docs = \Document::getDocumentsForForeignReferenceId('ccda', $event->getCcdaId());
        if (!empty($docs)) {
            $doc = $docs[0];
        } else {
            throw new \Exception("Document did not exist for ccda table with id " . $createdEvent->getCcdaId());
        }

	    //$this->logger->errorLogCaller(print_r($doc, 1));
	    //$this->logger->errorLogCaller(print_r(get_class_methods($doc), 1));
	    //$this->logger->errorLogCaller(print_r($doc->toString(), 1));
	    //$this->logger->errorLogCaller(print_r($doc->get_data(), 1));
	    //$this->logger->errorLogCaller(print_r($doc->get_data(), 1));
		$sXML = $doc->get_data();
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($sXML);

		foreach($xmlDom->getElementsByTagName('component') as $component) {
		foreach($component->getElementsByTagName('section') as $section) {
		foreach($section->getElementsByTagName('title') as $title) {
			// $title is a DOMElement Object
			// We know that the Social History section we receive is 
			// empty but more secure programming would test this
			if ($title->nodeValue === 'Social History') {
				//$this->logger->errorLogCaller(print_r(get_class_methods($title), 1));
				//$this->logger->errorLogCaller(print_r($title, 1));
				//$this->logger->errorLogCaller(print_r($title->nodeValue, 1));
				$this->logger->errorLogCaller(print_r($xmlDom->saveXML($component), 1));
				$tr = $xmlDom->createElement('TR');
				$tr->appendChild('TH', 'Social History');
				$tr->appendChild('TH', 'Observation');
				$tr->appendChild('TH', 'Date');
				$thead = $xmlDom->createElement('THEAD');
				$thead->apendChild($tr);
				$table = $xmlDom->createElement('TABLE');
				$table->appendChild($thead);

				// Insert sex assigned at birth
				$tbody = $xmlDom->createElement('TBODY');
				$tr = $xmlDom->createElement('TR');
				$tr->appendChild('TD', 'Sex');
				$tr->appendChild('TD', $sex);
				$tr->appendChild('TD', $dob);
				$tbody->appedChild($tr);

				$text = $xmlDom->createElement('text');
				$text->appendChild($table);

				// TODO: Insert sexual orientation
				// TODO: Insert gender identity
				//

				$entry = $xmlDom->createElement('ENTRY');
				$observation = $xmlDom->createElement('OBSERVATION');
				$entry->setAttribute('typeCode', 'DRIV');
				$observation->setAttribute('classCode', 'OBS');
				$observation->setAttribute('moodCode', 'EVN');
				/*
				<templateId root="2.16.840.1.113883.10.20.22.4.200"
						extension="2016-06-01"/>
					<code code="76689-9" codeSystem="2.16.840.1.113883.6.1"
						displayName="Sex Assigned At Birth"/>
					<statusCode code="completed"/>
					<!-- effectiveTime if present should match birthTime -->
					<effectiveTime value="19750501"/>
					<value xsi:type="CD" codeSystem="2.16.840.1.113883.5.1"
						codeSystemName="AdministrativeGender" code="F" displayName="Female">
						<originalText>
							<reference value="#BSex_value"/>
						</originalText>
					</value>
				*/
				exit;
			}
			//exit;
		} // title
		} // section
		$section->appendChild($text);
		} // component
		
		// This is an example of how we can embed ourselves in the xml
		/*
		if (substr_count($content, '</ClinicalDocument>') == 2) {
            $d = explode('</ClinicalDocument>', $content);
            $content = $d[0] . '</ClinicalDocument>';
            $unstructured = $d[1] . '</ClinicalDocument>';
        }
		*/

		// TODO: sex assigned at birth
		// TODO: sexual preference
		// TODO: gender identity
		// TODO: all visits and encounters
		// TODO: all groups

		// TODO: write the document back to disk

	    exit;
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
