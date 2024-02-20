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
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addSexualOrientation']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addTextSexAssignedAtBirth']
		);
    }

    public function addSexualOrientation(ServiceSaveEvent $event)
    {
		//$this->logger->debug(__FUNCTION__);
		$CCDA = $event->getSaveData()['CCDA'];
		$pid = $event->getSaveData()['pid'];

		$xmlDom = new DOMDocument();
		$xmlDom->loadXML($CCDA);

		foreach($xmlDom->getElementsByTagName('component') as $component) {
		foreach($component->getElementsByTagName('section') as $section) {
		foreach($section->getElementsByTagName('title') as $title) {
			// $title is a DOMElement Object
			// We expect the Social History section to already be built
			if ($title->nodeValue === 'Social History') {
				// Insert the text section for Sexual orientation

				// Insert the entry section for secual orientation
				$entry_text = $this->getSexualOrientationEntry($pid);
				$fragment = $xmlDom->createDocumentFragment();
				$fragment->appendXml($entry_text);
				$section->appendChild($fragment);
				//$this->logger->errorLogCaller($entry_text);
				break 3;
			}
		}
		}
		}

		$save = Array('pid' => $pid, 'CCDA' => $xmlDom->saveXML());
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

				$elements = $text->getElementsByTagName('tbody');
				if (!$elements->count()) {
					$this->logger->warning('Social History not built for: '.$name_string);
					return $event;
				}

				$tbody = $elements->item(0);
				//$this->logger->errorLogCaller(print_r(get_class_methods($tbody), 1)); return $event;

				//determine the most recent id
				$socialId = $this->getSocialId($tbody);

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

	private function getSocialId($node) {
		$ct = 1;
		$socialId = 'social'.$ct;
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
		return $result;
	}

	private function getSexualOrientationText($pid) {
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
		$so = $pd['sexual_identity'];

		// If there is no sexual identity we do not need to build a structure
		if (!$so) {
			return '';
		}

		$dob = $pd['DOB'];
		$gender = $pd['gender'];
		$gender_identity = $pd['gender_identity'];
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid) $dob";
		$date = $pd['date'];
		$this->logger->errorLogCaller($date);
		//$this->logger->errorLogCaller(print_r($so, 1));

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
			"Gay Male" => "",
				array("Gay or lesbian","38628009"), 
			"Queer" => 
				array("other","OTH"), 
			"Do Not Know" => "",
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
			"NULL" => "",
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
	private function getSexualOrientationEntry($pid) {
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
		$so = $pd['sexual_identity'];

		// If there is no sexual identity we do not need to build a structure
		if (!$so) {
			return '';
		}

		$dob = $pd['DOB'];
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid) $dob";
		//$this->logger->errorLogCaller($name_string);
		//$this->logger->errorLogCaller(print_r($so, 1));

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
			"Gay Male" => "",
				array("Gay or lesbian","38628009"), 
			"Queer" => 
				array("other","OTH"), 
			"Do Not Know" => "",
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
			"NULL" => "",
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
						<low value="201211"/>
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
