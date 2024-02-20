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
        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addGenderIdentity']
		);

        $this->eventDispatcher->addListener( 
			ServiceSaveEvent::EVENT_PRE_SAVE, [$this, 'addScannedDocuments']
		);
*/
    }

	// None of the custom forms are getting recorded as encounters in the C-CDA
    // we will have to add them here
    public function addSJIEncounters(ServiceSaveEvent $event)
    {
		$this->logger->debug(__FUNCTION__);
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

				$text = $section->getElementsByTagName('text')[0];
				if (!$text) {
					$this->logger->errorLogCaller(print_r($xmlDom->saveXML($section), 1));
					break 3;
				}

				$table = $text->getElementsByTagName('table')[0];
				if (!$table) {
					$this->logger->errorLogCaller(print_r($xmlDom->saveXML($text), 1));
					break 3;
				}

				//$this->logger->errorLogCaller(print_r(get_class_methods($table), 1));
				$table->appendChild($fragment);

				// Insert the entry section for secual orientation
				$entry = $this->getSexualOrientationEntry($pid);
				$fragment = $xmlDom->createDocumentFragment();
				$fragment->appendXml($entry);
				$section->appendChild($fragment);
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

		$dob = $pd['DOB'];
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid) $dob";
		$sexualities = $this->getHL7SexualOrientation($so);

	    $return = '<tbody styleCode="xRowGroup">
			<tr ID="_a1305452-cddd-4654-980f-bba6745588c8">
				<td>
					<content ID="_3b8a5051-3968-4cfe-ab70-8b69f84bd378">Sexual orientation</content>
				</td>
				<td>
					<content ID="_12d30bb3-f589-4d3b-a011-4b463aafa093">'.
					$sexualities[0]
                    .'</content>
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

		$dob = $pd['DOB'];
		$name_string = $pd['fname'] .' '. $pd['lname'] ." ($pid) $dob";
		$sexualities = $this->getHL7SexualOrientation($so);

		//$this->logger->errorLogCaller($name_string);
		//$this->logger->errorLogCaller(print_r($so, 1));
		//$this->logger->errorLogCaller(print_r($sexualities[$so], 1));

		// https://cdasearch.hl7.org/examples/view/Guide%20Examples/Sexual%20Orientation%20Observation_2.16.840.1.113883.10.20.22.4.501
		// According to the implementation guide this could be recorded differently 
		// over time with an observation for each new "state".  The effectiveTime
		// here is the date the participants sexuality was recorded.  For 
		// records that were imported from the previouse medicaldb system,
		// this value may not be correct
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
