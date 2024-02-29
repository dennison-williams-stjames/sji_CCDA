<?php

/**
 * This module extends the C-CDA subsystem to include data specific to 
 * St. James Infirmary
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @author    Dennison Williams <dennison@dennisonwilliams.com>
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
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addSexualOrientation']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addTextSexAssignedAtBirth']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addSJIEncounters']
		);

		/*
		 * FIX: cvc-complex-type.2.4.a: Invalid content was found starting with 
		 * element 'sdtc:raceCode'. One of '{"urn:hl7-org:v3":ethnicGroupCode, 
		 * "urn:hl7-org:v3":guardian, "urn:hl7-org:v3":birthplace, 
		 * "urn:hl7-org:v3":languageCommunication}' is expected.
		 *
		 * This should not be needed if the xml is validated against
		 * CDA_SDTC (CDA XML Schema with SDTC Approved Extensions)
        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'fixSdtcRaceCode']
		);
		 */

		/*
		 * FIX: cvc-complex-type.2.4.b: The content of element 'tbody' is 
		 * not complete. One of '{"urn:hl7-org:v3":tr}' is expected.
		 */
        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'fixEmptyTbody']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'fixInvalidDate']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'fixBrokenAuthor']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'fixEmptyComponent']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'fixMissingProviderNPI']
		);

		/*
        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addGenderIdentity']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addScannedDocuments']
		);
		*/
	}

	/*
	 * FIX: cvc-complex-type.2.4.b: The content of element 'tbody' is 
	 * not complete. One of '{"urn:hl7-org:v3":tr}' is expected.
	 *
	 * There are some sections in the resulting xml with empty tbody
	 * tags which need to contain at least:
	 * <tbody><tr><td /></tr></tbody>
	 */
	public function fixEmptyTbody(ServiceSaveEvent $event) {
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		// There should only be one sdtc:raceCode
		foreach($xmlDom->getElementsByTagName('tbody') as $tbody) {
			if ($tbody->childNodes->length === 0) {
				$tr = $xmlDom->createElement('tr');
				$td = $xmlDom->createElement('td');
				$tr->appendChild($td);
				$tbody->appendChild($tr);
			}
		}

		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

	/*
	 * FIX: cvc-complex-type.2.4.a: Invalid content was found starting with 
	 * element 'sdtc:raceCode'. One of '{"urn:hl7-org:v3":ethnicGroupCode, 
	 * "urn:hl7-org:v3":guardian, "urn:hl7-org:v3":birthplace, 
	 * "urn:hl7-org:v3":languageCommunication}' is expected.
	 *
	 * OEMR is not building out the requirements for using the sdtc:racCode 
	 * tag as expected, so we are just going to remove it
	 */
	public function fixSdtcRaceCode(ServiceSaveEvent $event) {
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		// There should only be one sdtc:raceCode
		foreach($xmlDom->getElementsByTagNameNS('urn:hl7-org:sdtc', 'raceCode') as $sdtcRaceCode) {
			//$this->logger->errorLogCaller('Trying to remove racecode');
			$sdtcRaceCode->remove();
		}

		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

	/*
		cvc-pattern-valid: Value 'Invalid date' is not facet-valid with 
		respect to pattern 
		'[0-9]{1,8}|([0-9]{9,14}|[0-9]{14,14}\.[0-9]+)([+\-][0-9]{1,4})?' 
		for type 'ts'.

		cvc-attribute.3: The value 'Invalid date' of attribute 'value' on 
		element 'time' is not valid with respect to its type, 'ts'.

		This issue comes up in the Patient Care Teams section
	*/
	public function fixInvalidDate(ServiceSaveEvent $event) {
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		// There should only be one sdtc:raceCode
		foreach($xmlDom->getElementsByTagName('time') as $time) {
			if ($time->getAttribute('value') === 'Invalid date') {
				$time->remove();
			}
		}

		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

	/*
	 * cvc-complex-type.2.4.a: Invalid content was found starting 
	 * with element 'assignedAuthor'. One of '{"urn:hl7-org:v3":templateId, 
	 * "urn:hl7-org:v3":functionCode, "urn:hl7-org:v3":time}' is expected.
	 *
	 * It seems there are times when an invalid <author> entry is generated
	 */
	public function fixBrokenAuthor(ServiceSaveEvent $event) {
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		foreach($xmlDom->getElementsByTagName('author') as $author) {
			$elements = $author->getElementsByTagName('time');
			if ($elements->length == 0) {
				$author->remove();
			}
		}

		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

	/*
	 * cvc-minLength-valid: Value '' with length = '0' is not 
	 * facet-valid with respect to minLength '1' for type 'st'.
	 *
	 * cvc-attribute.3: The value '' of attribute 'extension' on 
	 * element 'id' is not valid with respect to its type, 'st'.
	 *
	 *  This seems to be happening in the encounters list
	 */

	/*
	 * cvc-complex-type.2.4.a: Invalid content was found starting with 
	 * element 'entryRelationship'. One of '{"urn:hl7-org:v3":entry, 
	 * "urn:hl7-org:v3":component}' is expected.
	 *
	 *  It seems we get empty components sometimes
	 */
	public function fixEmptyComponent(ServiceSaveEvent $event) {
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		foreach($xmlDom->getElementsByTagName('component') as $component) {
			if ($component->childNodes->length == 0) {
				$component->remove();
			}
		}

		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

	/*
	 * cvc-complex-type.2.4.a: Invalid content was found starting with 
	 * element 'entryRelationship'. One of '{"urn:hl7-org:v3":entry, 
	 * "urn:hl7-org:v3":component}' is expected.
	 *
	 * This happens whenever one of our providers without an NPI is
	 * referenced
	 */
	public function fixMissingProviderNPI(ServiceSaveEvent $event) {
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		foreach($xmlDom->getElementsByTagName('assignedEntity') as $ae) {
			$id = $ae->getElementsByTagName('id')[0];
			if (strlen($id->getAttribute('extension')) === 0) {
				$id->removeAttribute('extension');
			}
		}

		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

	/*
	 * cvc-complex-type.2.4.a: Invalid content was found starting with 
	 * element 'entryRelationship'. One of '{"urn:hl7-org:v3":entry, 
	 * "urn:hl7-org:v3":component}' is expected.
	 *
	 *
	 */

	// None of the custom forms are getting recorded as encounters in the C-CDA
    // we will have to add them here
	/*

	  <entry typeCode="DRIV">
		<encounter classCode="ENC" moodCode="EVN">
		  <templateId root="2.16.840.1.113883.10.20.22.4.49" extension="2015-08-01"/>
		  <templateId root="2.16.840.1.113883.10.20.22.4.49"/>
		  <id root="779b89a1-1376-0581-0482-7766f2f77e50" extension="ZGVmYXVsdDMzODM3Mw=="/>
		  <code code="185347001" displayName="Office Visit | Testing custom forms" codeSystem="2.16.840.1.113883.6.96" codeSystemName="SNOMED CT">
			<originalText>
			  <reference value="#Encounter43"/>
			</originalText>
			<translation code="AMB" displayName="Ambulatory" codeSystem="2.16.840.1.113883.5.4" codeSystemName="ActCode"/>
		  </code>
		  <effectiveTime value="202203070000-0800"/>
		  <performer> ...
		  <participant typeCode="LOC"> ...
		</encounter>
	  </entry>
	*/
	public function addSJIEncounters(ServiceSaveEvent $event) {
		// loop across the C-CDA encounters and for each record of a visit
		// find additional SJI encounters and add them

		// For each encounter that we parse there should be 1 effectiveDate
		// using that along with the pid should be able to get the codes from
		// SJI charting

		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		foreach($xmlDom->getElementsByTagName('component') as $component) {
		foreach($component->getElementsByTagName('section') as $section) {
		foreach($section->getElementsByTagName('title') as $title) {
			// $title is a DOMElement Object
			// We expect the Social History section to already be built

			if ($title->nodeValue === 'Encounters') {
				$elements = $section->getElementsByTagName('text');
				$text = $elements->item(0);

			
				foreach($section->getElementsByTagName('entry') as $entry) {
					$encounter = $entry->getElementsByTagName('encounter')[0];

					// Get the enounter reference value so that we can update the 
					// Diagnosis/Complaint column
					$originalText = $encounter->getElementsByTagName('originalText')[0];
					//$this->logger->errorLogCaller('originalText : '.  print_r($origionalText, 1));
					$reference = $originalText->getElementsByTagName('reference')[0];
					$reference = $reference->getAttribute('value');


					// get the td node we will be updating
					$td = '';
					$found = 0;
					foreach($text->getElementsByTagName('td') as $td) {
						if ('#'.$td->getAttribute('ID') === $reference) {
							$found = 1;
							$tr = $td->parentNode;
							$td = $tr->lastElementChild;
							break;
						}

					}

					if (!$found) {
						$td = '';
					} 

					// convert this to use as our lookup date
					$elements = $encounter->getElementsByTagName('effectiveTime');
					$effectiveTime = $elements->item(0)->getAttribute('value');
					$effectiveTime = date_parse($effectiveTime);
					$effectiveTime = $effectiveTime['year'] .'-'.
						str_pad($effectiveTime['month'], 2, 0, STR_PAD_LEFT) .'-'. 
						str_pad($effectiveTime['day'], 2, 0, STR_PAD_LEFT);

					$coding = $this->getCoding($pid, $effectiveTime);
					/*
					$this->logger->errorLogCaller(
						'Looking up coding info for pid: '.
						$pid .', date: '. print_r($effectiveTime,1));
					$this->logger->errorLogCaller(print_r($coding, 1));
					 */
					if (!$coding) { continue; }
					foreach($coding as $code) {
						//$this->logger->errorLogCaller(print_r($code, 1));

						// If we have cpt codes then generate the entryRelationship xml
						if(array_key_exists('cpt_codes', $code) && $code['cpt_codes']) {
							if(!preg_match('/([0-9A-Z]{5})/', $code['cpt_codes'], $matches)) {
								$this->logger->warning('Could not find CPT code: '.$code['cpt_codes']);
								continue;
							}
							$codeID = $matches[1];
							//$this->logger->errorLogCaller(print_r($codeID, 1) .' '. $pid .' '. $effectiveTime);

							$er = $xmlDom->createElement('entryRelationship');
							$er->setAttribute('typeCode', 'REFR');
							$er->setAttribute('inversionInd', 'false');

							// definition for act element: 
							// https://terminology.hl7.org/5.2.0/ValueSet-v3-xDocumentActMood.html
							$act = $xmlDom->createElement('act');
							$act->setAttribute('classCode', 'ACT');
							$act->setAttribute('moodCode', 'EVN');

							$er->appendChild($act);

							$codeEl = $xmlDom->createElement('code');
							$codeEl->setAttribute('code', $codeID);
							$codeEl->setAttribute('codeSystemName', 'CPT-4');
							$codeEl->setAttribute('codeSystem', '2.16.840.1.113883.6.12');
							$codeEl->setAttribute('displayName', $code['cpt_codes']);

							if ($td->nodeValue === 'No Data Available') {
								$td->nodeValue = '';
							}

							if ($td->nodeValue) {
								$td->nodeValue .= ', ';
							}

							if ($found && !is_string($td)) {
								$td->nodeValue .= $code['cpt_codes'];
							}

							$act->appendChild($codeEl);

							$encounter->appendChild($er);
						}
					}

				}
			}
		}
		}
		}


		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

	// https://cdasearch.hl7.org/sections/Unstructured
	/*
	 * If the PDF is greater the 5M, let's add it as a reference
	 * https://cdasearch.hl7.org/examples/view/Unstructured/CDA%20reference%20PDF
	 *
	 * Otherwise liet's have it be embedded
	 * https://cdasearch.hl7.org/examples/view/Unstructured/CDA%20with%20Embedded%20PDF%201
	 *
	 * There may be functionality in the ccdaservice that already handles this
	*/
    public function addScannedDocuments(ServiceSaveEvent $event)
    {
		$this->logger->debug(__FUNCTION__);
		return $event;
	}

    public function addSexualOrientation(ServiceSaveEvent $event)
    {
		//$this->logger->debug(__FUNCTION__);
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];
		$pd = array_merge(
			$this->getPatientData($pid),
			$this->getSexualOrientation($pid)
		);
		$dob = array_key_exists('DOB', $pd) ? ' '.$pd['DOB'] : '';
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid)$dob";

		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		foreach($xmlDom->getElementsByTagName('component') as $component) {
		foreach($component->getElementsByTagName('section') as $section) {
		foreach($section->getElementsByTagName('title') as $title) {
			// $title is a DOMElement Object
			// We expect the Social History section to already be built
			if ($title->nodeValue === 'Social History') {
				// Insert the text section for Sexual orientation
				$stext = $this->getSexualOrientationText($pid);
				if (!strlen($stext)) {
					// there is no sexual orientation to report on
					break 3;
				}
				$fragment = $xmlDom->createDocumentFragment();
				$fragment->appendXml($stext);

				$elements = $section->getElementsByTagName('text');
				$text = $elements->item(0);

				$elements = $text->getElementsByTagName('table');
				if (!$elements->count()) {
					$sh_text = $this->getNewSocialHistoryText();
					//$this->logger->debug(print_r($sh_text, 1));
					$fragment = $xmlDom->createDocumentFragment();
					$fragment->appendXml($sh_text);
					$section->replaceChild($fragment, $text);
					$elements = $section->getElementsByTagName('text');
					$text = $elements->item(0);
					$elements = $text->getElementsByTagName('table');
					if (!$elements->count()) {
						$this->logger->errorLogCaller('Social History not buildable: '.
							$name_string);
						return $event;
					}
				}

				// Insert the entry section for secual orientation
				$entry = $this->getSexualOrientationEntry($pid);
				//$this->logger->errorLogCaller(print_r($entry, 1));
				$fragment = $xmlDom->createDocumentFragment();
				$fragment->appendXml($entry);
				$section->appendChild($fragment);
				break 3;
			}
		}
		}
		}

		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);
		return $event;
	}

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

		// TODO: FIXME: this is wrong
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

				//$this->logger->errorLogCaller(print_r($text->nodeValue, 1));
				$elements = $text->getElementsByTagName('table');
				if (!$elements->count() || 
					$text->nodeValue === 'Not Available') {
					$sh_text = $this->getNewSocialHistoryText();
					//$this->logger->errorLogCaller($sh_text);
					$fragment = $xmlDom->createDocumentFragment();
					$fragment->appendXml($sh_text);
					$section->replaceChild($fragment, $text);
					//$this->logger->errorLogCaller(print_r($result->nodeValue, 1));
					$elements = $section->getElementsByTagName('text');
					$text = $elements->item(0);
					$elements = $text->getElementsByTagName('table');
					if (!$elements->count()) {
						$this->logger->errorLogCaller('Social History not buildable: '.
							$name_string);
						return $event;
					}
				}

				$table = $elements->item(0);
				//$this->logger->errorLogCaller(print_r(get_class_methods($tbody), 1)); return $event;

				//determine the most recent id
				$socialId = $this->getSocialId($table);

				// Insert sex assigned at birth
				$tbody = $xmlDom->createElement('tbody');
				$tr = $xmlDom->createElement('tr');
				$td = $xmlDom->createElement('td');
				$td->setAttribute('ID', $socialId);
				$td->nodeValue = 'Birth Sex';
				$tr->appendChild($td);

				$td = $xmlDom->createElement('td');
				$td->nodeValue = $sex;
				$tr->appendChild($td);

				$td = $xmlDom->createElement('td');
				$td->nodeValue = $dob;
				$tr->appendChild($td);

				$tbody->appendChild($tr);
				$table->appendChild($tbody);

				break 3;
			}
			//exit;
		} // title
		} // section
		} // component
		
		$save = Array(
			'pid' => $pid, 
			'CCDA' => str_replace(PHP_EOL, '', $xmlDom->saveXML())
		);
		$event->setSaveData($save);

	    return $event;
	}

	/*
	 * Given ___ pull the provider name and NPI
     * return a string representing a performer section

	 <performer>
		<assignedEntity>
		  <id root="2.16.840.1.113883.4.6" extension="<NPI>"/>
		  <code nullFlavor="UNK"/>
		  <assignedPerson>
			<name>
			  <family><PROVIDER_LNAME></family>
			  <given><PROVIDER_FNAME></given>
			</name>
		  </assignedPerson>
		</assignedEntity>
	  </performer>
	*/
	private function getPerformer() {
		$npi = 1;
        $fname = 'Charles';
        $lname = 'Cloniger';
	    $return = '<performer>
		<assignedEntity>
		  <id root="2.16.840.1.113883.4.6" extension="'. $npi .'"/>
		  <code nullFlavor="UNK"/>
		  <assignedPerson>
			<name>
			  <family>'. $lname .'</family>
			  <given>'. $fname .'</given>
			</name>
		  </assignedPerson>
		</assignedEntity>
	    </performer>';
		return $return;
	}

	/*
     * Not to be confused with participant that receives care, this
	 * is a vocabulary word from C-CDA

	  <participant typeCode="LOC">
		<participantRole classCode="SDLOC">
		  <templateId root="2.16.840.1.113883.10.20.22.4.32"/>
		  <code code="1160-1" displayName=",730 Polk St,San Francisco,CA 94109" codeSystem="2.16.840.1.113883.6.259" codeSystemName="HealthcareServiceLocation"/>
		  <addr>
			<streetAddressLine>730 Polk St</streetAddressLine>
			<city>San Francisco</city>
			<state>CA</state>
			<postalCode>94109</postalCode>
			<country>USA</country>
		  </addr>
		  <telecom value="tel:4155548494" use="WP"/>
		  <playingEntity classCode="PLC">
			<name>Polk St</name>
		  </playingEntity>
		</participantRole>
	  </participant>
	*/
	private function getParticipant() {
		return '';
	}

	/*
	  <text>
		<table width="100%" border="1">
		  <thead>
			<tr>
			  <th>Social History Element</th>
			  <th>Description</th>
			  <th>Effective Dates</th>
			</tr>
		  </thead>
		  <tbody>
		    <tr />
		  </tbody>
		</table>
	  </text>
	*/
	private function getNewSocialHistoryText() {
		return '<text>
		<table width="100%" border="1">
		  <thead>
			<tr>
			  <th>Social History Element</th>
			  <th>Description</th>
			  <th>Effective Dates</th>
			</tr>
		  </thead>
		  <tbody>
            <tr><td></td><td></td><td></td></tr>
		  </tbody>
		</table>
	  </text>';
	}

	private function getSocialId($node) {
		$ct = 1;
		$socialId = 'social'.$ct;
		if (!$node) {
			return $socialId;
		}

		foreach($node->getElementsByTagName('td') as $td) {
			$value = $td->getAttribute('ID');
			if ($value) {
				//$this->logger->errorLogCaller('ID: '. print_r($value, 1));
				$ct = $ct + 1;
				$socialId = 'social'.$ct;
			}
		} 
		return $socialId;
	}

	private function getSexualOrientation($pid) {
		$sql = "Select * from form_sji_intake_core_variables ".
			"where pid = ? ". 
			"AND id = (".
			"select max(id) from form_sji_intake_core_variables ".
			"where pid=?)";
		$result = sqlQuery($sql, array($pid, $pid));
		return $result?$result:array();
	}

	/*
	*/
	private function getSexualOrientationText($pid) {
		$pd = array_merge(
			$this->getPatientData($pid),
			$this->getSexualOrientation($pid)
		);

		// If there is no sexual identity we do not need to build a structure
        if (!array_key_exists('sexual_identity', $pd)) {
		    return '';
		}	
		$so = $pd['sexual_identity'];

		$effectiveDate = $pd['date'];
		$effectiveDate = strtotime($effectiveDate);
		$effectiveDate = getDate($effectiveDate);
		$effectiveDate = $effectiveDate['year'] .'/'.
			str_pad($effectiveDate['mon'], 2, 0, STR_PAD_LEFT) .'/'. 
			str_pad($effectiveDate['mday'], 2, 0, STR_PAD_LEFT);

		$dob = array_key_exists('DOB', $pd) ? $pd['DOB'] : '';
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid) $dob";
		$sexualities = $this->getHL7SexualOrientation($so);

	    $return = '<tbody styleCode="xRowGroup">
			<tr ID="_a1305452-cddd-4654-980f-bba6745588c8">
				<td>
					<content ID="_3b8a5051-3968-4cfe-ab70-8b69f84bd378">Sexual orientation</content>
				</td>
				<td>
					<content ID="_12d30bb3-f589-4d3b-a011-4b463aafa093">';

		if (
			$sexualities && 
			is_array($sexualities) && 
			array_key_exists(0, $sexualities)
		   ) 
		{
			$return .= $sexualities[0];
		} else {
			$this->logger->errorLogCaller('Did not get expected sexuality -'.
					$so .'- => '.
					print_r($sexualities, 1)
			);
			return '';
		}

        $return .= '</content>
				</td>
				<td>
					<content>'. $effectiveDate .' - </content>
				</td>
			</tr>
		</tbody>';

		return $return;
	}

	private function getPatientData($pid)
    {
        $query = "SELECT          
                        p.lname,
                        p.fname,
                        p.mname,
                        DATE_FORMAT(p.dob, '%Y-%m-%d') as dob,
                        p.ss,
                        p.sex,
                        p.pid,
                        p.pubpid,
                        p.providerID,
                        p.email,
                        p.street,
                        p.city,
                        p.state,
                        p.postal_code,
                        f.id facility_id                    
                    FROM patient_data AS p
                    LEFT JOIN users AS d on 
                        p.providerID = d.id
                    LEFT JOIN facility AS f on 
                        f.id = d.facility_id
                    WHERE p.pid = ?
                    LIMIT 1";

        $ary = array($pid);
        $result = sqlStatement($query, $ary);

        if (sqlNumRows($result) == 1) {
            foreach ($result as $row) {
                return $row;
            }
        }

        return null;
	}

	/*
	<text>
		<table>
			<thead>
				<tr>
					<th>Social History</th>
					<th>Observation</th>
					<th>Date</th>
				</tr>
			</thead>
			<tbody styleCode="xRowGroup">
				<tr ID="_6e185602-f807-40c6-8003-41c589ae62af">
					<td>
						<content ID="_4503c511-8e06-41a0-8f6b-3f5d23b131e2">Gender identity</content>
					</td>
					<td>
						<content ID="_71d1b8e1-b3dd-483f-80f3-49656e518994">Female-to-male transsexual</content>						
					</td>
					<td/>
					<content>2001 - </content>
					<td/>
				</tr>
			</tbody>
		</table>
	</text>
	<entry>
			<observation classCode="OBS" moodCode="EVN">
				<templateId root="2.16.840.1.113883.10.20.22.4.38" extension="2015-08-01"/>
				<templateId root="2.16.840.1.113883.10.20.22.4.38"/>
				<id root="2.16.840.1.113883.19" extension="123456789"/>
				<!-- Per the C-CDA 2.1 IG - (CONF:1198-8558) - social history type value set is a SHOULD - therefore this value is not in the value set however, it is allowed-->
				<code code="76691-5" codeSystem="2.16.840.1.113883.6.1" displayName="Gender identity" codeSystemName="LOINC">
					<originalText>
						<reference value="#_4503c511-8e06-41a0-8f6b-3f5d23b131e2"/>
					</originalText>
				</code>
				<text>
					<reference value="#_6e185602-f807-40c6-8003-41c589ae62af"/>
				</text>
				<statusCode code="completed"/>
				<!-- interval start with no end to indicate from 2001 -->
				<effectiveTime xsi:type="IVL_TS">
					<low value="2001"/> 
				</effectiveTime>
				<!-- Selected from this value set - https://phinvads.cdc.gov/vads/ViewValueSet.action?id=B0155EA6-45BB-E711-ACE2-0017A477041A -->
				<value xsi:type="CD" code="407377005" codeSystem="2.16.840.1.113883.6.96" displayName="Female-to-male transsexual" codeSystemName="SNOMED CT">
					<originalText>
						<reference value="#_71d1b8e1-b3dd-483f-80f3-49656e518994"/>
					</originalText>
				</value>
				<author>
					<templateId root="2.16.840.1.113883.10.20.22.4.119"/>
					<time value="201406061032-0500"/>
					<assignedAuthor>
						<id extension="99999999" root="2.16.840.1.113883.4.6"/>
						<code code="200000000X" codeSystem="2.16.840.1.113883.6.101" displayName="Allopathic and Osteopathic Physicians" codeSystemName="Healthcare Provider Taxonomy (HIPAA)"/>
						<telecom use="WP" value="tel:+1(555)555-1002"/>
						<assignedPerson>
							<name>
								<given>Henry</given>
								<family>Seven</family>
							</name>
						</assignedPerson>
					</assignedAuthor>
				</author>
			</observation>
		</entry>	
	 
	 */


	private function getGenderIdentityEntry($pid) {
        global $srcdir;
		//$this->logger->errorLogCaller(__FUNCTION__); return;
		$pd = array_merge(
			$this->getPatientData($pid),
	        $this->getSexualOrientation($pid)
		);
		//$this->logger->errorLogCaller($pid);
		//$this->logger->errorLogCaller(print_r(getPatientData($pid), 1));
		//$this->logger->errorLogCaller(print_r($this->getSexualOrientation($pid), 1));
		//$this->logger->errorLogCaller(print_r($pd, 1));
		$gender = $pd['gender'];

		// If there is no sexual identity we do not need to build a structure
		if (!$gender) {
			return '';
		}

		$dob = $pd['DOB'];
		$gender = $pd['gender'];
		$gender_identity = $pd['gender_identity'];
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid) $dob";
		$date = $pd['date'];
		$this->logger->errorLogCaller($date);
		//$this->logger->errorLogCaller(print_r($so, 1));

		// Convert the gender we received
		// https://phinvads.cdc.gov/vads/ViewValueSet.action?id=B0155EA6-45BB-E711-ACE2-0017A477041A
		// Not all of these are LOINC!
		$genders = array(
			"NULL" => "",
				array("unknown","UKN"), 
			"Cisgender Male" => 
				// PHIN VS (CDC Local Coding System)
				array("Cisgender/Not transgender (finding)","PHC1490"), 
			"Cisgender Female" => 
				// PHIN VS (CDC Local Coding System)
				array("Cisgender/Not transgender (finding)","PHC1490"), 
			"Trans Male" => 
				// SNOMED-CT
				array("Female-to-male transsexual","407377005"), 
			"Unknown" => 
				// NullFlavor
				array("unknown","UKN"), 
			"Transgender Female" => 
				// SNOMED-CT
				array("Male-to-female transsexual","407376001"),
			"NB AFAB" => 
				// SNOMED-CT
				array("Transgender unspecified","12271241000119109"),
			"NB AMAB" => 
				// SNOMED-CT
				array("Transgender unspecified","12271241000119109"),
			"" => 
				array("unknown","UKN"), 
			"Transgender Male" => 
				// SNOMED-CT
				array("Female-to-male transsexual","407377005"), 
			"Intersex Female" => 
				// SNOMED-CT
				array("Transgender unspecified","12271241000119109"),
			"Other Male" => 
				// SNOMED-CT
				array("Transgender unspecified","12271241000119109")
		);

		//$this->logger->errorLogCaller(print_r($so, 1));
		//$this->logger->errorLogCaller(print_r($sexualities[$so], 1));

		$entry = '<entry>
				<!-- Sexual Orientation Observation -->
				<observation classCode="OBS" moodCode="EVN">
					<templateId root="2.16.840.1.113883.10.20.22.4.38"
						extension="2022-06-01"/>
					<templateId root="2.16.840.1.113883.10.20.22.4.501"
						extension="2023-05-01"/>
					<id root="7919e027-592e-4f22-9344-12460ec8c368"/>
					<code code="76690-7" displayName="Sexual Orientation"
						codeSystem="2.16.840.1.113883.6.1" codeSystemName="LOINC"/>
					<statusCode code="completed"/>
					<effectiveTime>
						<low value="'. $date .'"/>
					</effectiveTime>
					<value xsi:type="CD" code="'. $sexualities[$so][1] .'" displayName="'. $sexualities[$so][0] .'"
						codeSystem="2.16.840.1.113883.6.96" codeSystemName="SNOMED CT"/>
				</observation>
			</entry>';

/*
		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($entry);
		$elements = $xmlDom->getElementsByTagName('entry');
		return $elements->item(0);
*/
		return $entry;
	}

	private function getHL7SexualOrientation($orientation) {
		// Convert the sexual orientation we received
		// LOINC sexual orientation https://loinc.org/LL3323-4
		$sexualities = array(
		    "Lesbian/Dyke/Gay Female" =>
				array("Gay or lesbian","38628009"), 
			"Straight/Heterosexual" => 
				array("Straight (not gay or lesbian)","20430005"), 
			"Bisexual" => 
				array("Bisexual","20430005"), 
			"Other - unspecified" => 
				array("other","OTH"), 
			"Gay Male" => 
				array("Gay or lesbian","38628009"), 
			"Queer" => 
				array("other","OTH"), 
			"Do Not Know" => 
				array("unknown","UKN"), 
			"Other (specify)" => 
				array("other","OTH"), 
			"Refused" => 
				array("unknown","UKN"), 
			"Questioning" => 
				array("other","OTH"), 
			"Trans" => 
				array("unknown","UKN"), 
			"Same-Gender Loving" => 
				array("Gay or lesbian","38628009"), 
			"Declined to state" => 
				array("unknown","UKN"), 
			"do not identify" => 
				array("unknown","UKN"), 
			"Hetero" => 
				array("Straight (not gay or lesbian)","20430005"), 
			"NULL" => 
				array("unknown","UKN"), 
			"Heterosexual" => 
				array("Straight (not gay or lesbian)","20430005"), 
			"Gay" => 
				array("Gay or lesbian","38628009"), 
			"Pansexual" => 
				array("other","OTH"), 
			"Lesbian" => 
				array("Gay or lesbian","38628009"), 
		);

		if(array_key_exists($orientation, $sexualities)) {
			return $sexualities[$orientation];
		} 

		return array("unknown","UKN");
	}

	private function getSexualOrientationEntry($pid) {
		//$this->logger->errorLogCaller(__FUNCTION__); return;
		$pd = array_merge(
			$this->getPatientData($pid),
			$this->getSexualOrientation($pid)
		);
		//$this->logger->errorLogCaller($pid);
		//$this->logger->errorLogCaller(print_r(getPatientData($pid), 1));
		//$this->logger->errorLogCaller(print_r($this->getSexualOrientation($pid), 1));
		//$this->logger->errorLogCaller(print_r($pd, 1));
		$so = $pd['sexual_identity'];
		$effectiveDate = $pd['date'];
		$effectiveDate = strtotime($effectiveDate);
		$effectiveDate = getDate($effectiveDate);
		$effectiveDate = $effectiveDate['year'] . 
			str_pad($effectiveDate['mon'], 2, 0, STR_PAD_LEFT) . 
			str_pad($effectiveDate['mday'], 2, 0, STR_PAD_LEFT);
		//$this->logger->errorLogCaller(print_r($effectiveDate, 1));

		// If there is no sexual identity we do not need to build a structure
		if (!$so) {
			return '';
		}

		$dob = array_key_exists('DOB', $pd) ? $pd['DOB'] : '';
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid) $dob";
		$sexualities = $this->getHL7SexualOrientation($so);

		//$this->logger->errorLogCaller($name_string);
		//$this->logger->errorLogCaller(print_r($so, 1));
		//$this->logger->errorLogCaller(print_r($sexualities[0], 1));

		// https://cdasearch.hl7.org/examples/view/Guide%20Examples/Sexual%20Orientation%20Observation_2.16.840.1.113883.10.20.22.4.501
		// According to the implementation guide this could be recorded differently 
		// over time with an observation for each new "state".  The effectiveTime
		// here is the date the participants sexuality was recorded.  For 
		// records that were imported from the previouse medicaldb system,
		// this value may not be correct


		// We need to add in a new xsi type to support this
		// https://stackoverflow.com/questions/9533034
		// xsi:type="CD"
		$entry = '<entry>
				<!-- Sexual Orientation Observation -->
				<observation classCode="OBS" moodCode="EVN">
					<templateId root="2.16.840.1.113883.10.20.22.4.38"
						extension="2022-06-01"/>
					<templateId root="2.16.840.1.113883.10.20.22.4.501"
						extension="2023-05-01"/>
					<id root="7919e027-592e-4f22-9344-12460ec8c368"/>
					<code code="76690-7" displayName="Sexual Orientation"
						codeSystem="2.16.840.1.113883.6.1" codeSystemName="LOINC"/>
					<statusCode code="completed"/>
					<effectiveTime>
						<low value="'. $effectiveDate .'"/>
					</effectiveTime>
					<value xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="urn:hl7-org:v3" xmlns:voc="urn:hl7-org:v3/voc" xmlns:sdtc="urn:hl7-org:sdtc" xsi:type="CD" code="'.  $sexualities[1] .'" '
					.'displayName="'.  $sexualities[0] .'"
						codeSystem="2.16.840.1.113883.6.96" codeSystemName="SNOMED CT"/>
				</observation>
			</entry>';

		return $entry;
	}

    private function getPublicPath()
    {
        return self::MODULE_INSTALLATION_PATH . ($this->moduleDirectoryName ?? '') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR;
    }

    private function getAssetPath()
    {
        return $this->getPublicPath() . 'assets' . DIRECTORY_SEPARATOR;
    }

	// Lookup the misc sji forms that assign coding to a visit
	// There is a mapping for this table: form_sji_counseling
	// from provider type, counseling type, and duration

	// This table would have it's own mapping too: form_sji_holistic

	// Not all forms have codes associated with them, but these do
	/*
	form_sji_medical_psychiatric_cpt_codes 
	form_sji_medical_psychiatric_icd10_primary
	form_sji_medical_psychiatric_icd9_primary
	form_sji_medical_psychiatric_icd9_secondary
	TODO: form_sji_medical_psychiatric_method_codes
	TODO: form_sji_medical_psychiatric_provider_type
	TODO: form_sji_medical_psychiatric_range_codes


select 
	form_sji_medical_psychiatric.id,
	form_sji_medical_psychiatric.date,
	form_sji_medical_psychiatric.pid,
	user,provider_type,duration, 
	cpt_codes, 
	form_sji_medical_psychiatric_icd10_primary.icd_primary as icd10_primary, 
	form_sji_medical_psychiatric_icd9_primary.icd_primary as icd9_primary, 
	form_sji_medical_psychiatric_icd9_secondary.icd_secondary as icd9_secondary, 
	from form_sji_medical_psychiatric 

	left join form_sji_medical_psychiatric_cpt_codes on 
		(form_sji_medical_psychiatric_cpt_codes.pid = 
		form_sji_medical_psychiatric.id) 

	left join form_sji_medical_psychiatric_icd10_primary on (
		form_sji_medical_psychiatric_icd10_primary.pid = 
		form_sji_medical_psychiatric.id) 

	left join form_sji_medical_psychiatric_icd9_primary on (
		form_sji_medical_psychiatric_icd9_primary.pid = 
		form_sji_medical_psychiatric.id) 

	left join form_sji_medical_psychiatric_icd9_secondary on (
		form_sji_medical_psychiatric_icd9_secondary.pid = 
		form_sji_medical_psychiatric.id) 

	where form_sji_medical_psychiatric.pid=? 
	and datediff(form_sji_medical_psychiatric.date,?)=0


	select 
		form_sji_medical_psychiatric_icd9_primary.icd_primary as icd9_primary,
		form_sji_medical_psychiatric.id,date,form_sji_medical_psychiatric.pid,
		user,provider_type,duration,
		cpt_codes,
		form_sji_medical_psychiatric_icd10_primary.icd_primary 

		from form_sji_medical_psychiatric 

		left join form_sji_medical_psychiatric_cpt_codes on (
			form_sji_medical_psychiatric_cpt_codes.pid = 
			form_sji_medical_psychiatric.id) 

		left join form_sji_medical_psychiatric_icd10_primary on (
			form_sji_medical_psychiatric_icd10_primary.pid = 
			form_sji_medical_psychiatric.id) 

		left join form_sji_medical_psychiatric_icd9_primary on (
			form_sji_medical_psychiatric_icd9_primary.pid = 
			form_sji_medical_psychiatric.id) limit 10;

	*/
	// TODO: code the intake forms
	private function getCoding($pid, $date) {
		$query = 'select '.
			'form_sji_medical_psychiatric.id,'.
			'form_sji_medical_psychiatric.date,'.
			'form_sji_medical_psychiatric.pid,'.
			'user,provider_type,duration, '.
			'cpt_codes, '.
			'form_sji_medical_psychiatric_icd10_primary.icd_primary as '.
			'icd10_primary, '.
			'form_sji_medical_psychiatric_icd9_primary.icd_primary as '.
			'icd9_primary, '.
			'form_sji_medical_psychiatric_icd9_secondary.icd_secondary as '.
			'icd9_secondary '.
			'from form_sji_medical_psychiatric '.
			'left join form_sji_medical_psychiatric_cpt_codes on '.
			'(form_sji_medical_psychiatric_cpt_codes.pid = '.
			'form_sji_medical_psychiatric.id) '.
			'left join form_sji_medical_psychiatric_icd10_primary '.
			'on (form_sji_medical_psychiatric_icd10_primary.pid = '.
			'form_sji_medical_psychiatric.id) '.
			'left join form_sji_medical_psychiatric_icd9_primary '.
			'on (form_sji_medical_psychiatric_icd9_primary.pid = '.
			'form_sji_medical_psychiatric.id) '.
			'left join form_sji_medical_psychiatric_icd9_secondary '.
			'on (form_sji_medical_psychiatric_icd9_secondary.pid = '.
			'form_sji_medical_psychiatric.id) '.
			'where form_sji_medical_psychiatric.pid=? '.
			'and datediff(form_sji_medical_psychiatric.date,?)=0';

		//$this->logger->errorLogCaller(print_r($query, 1));
        $ary = array($pid, $date);
		$return = array();
        $result = sqlStatement($query, $ary);
		foreach ($result as $row) {
			$return[] = $row;
			//$this->logger->errorLogCaller(print_r($row, 1));
		}
		return $return;
	}

    public function getTemplatePath()
    {
        return \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
    }
}
