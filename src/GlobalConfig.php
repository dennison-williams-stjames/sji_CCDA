<?php

/**
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\SJICCDAModule;

use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Services\Globals\GlobalSetting;

class GlobalConfig
{
    const CONFIG_OPTION_TEXT = 'oe_skeleton_config_option_text';
    const CONFIG_OPTION_ENCRYPTED = 'oe_skeleton_config_option_encrypted';
    const CONFIG_OVERRIDE_TEMPLATES = "oe_skeleton_override_twig_templates";
    const CONFIG_ENABLE_MENU = "oe_skeleton_add_menu_button";
    const CONFIG_ENABLE_BODY_FOOTER = "oe_skeleton_add_body_footer";
    const CONFIG_ENABLE_FHIR_API = "oe_skeleton_enable_fhir_api";

    private $globalsArray;

    /**
     * @var CryptoGen
     */
    private $cryptoGen;

    public function __construct(array $globalsArray)
    {
        $this->globalsArray = $globalsArray;
        $this->cryptoGen = new CryptoGen();
    }

    /**
     * Returns true if all of the settings have been configured.  Otherwise it returns false.
     * @return bool
     */
    public function isConfigured()
    {
        $keys = [self::CONFIG_OPTION_TEXT, self::CONFIG_OPTION_ENCRYPTED];
        foreach ($keys as $key) {
            $value = $this->getGlobalSetting($key);
            if (empty($value)) {
                return false;
            }
        }
        return true;
    }

    public function getTextOption()
    {
        return $this->getGlobalSetting(self::CONFIG_OPTION_TEXT);
    }

    /**
     * Returns our decrypted value if we have one, or false if the value could not be decrypted or is empty.
     * @return bool|string
     */
    public function getEncryptedOption()
    {
        $encryptedValue = $this->getGlobalSetting(self::CONFIG_OPTION_ENCRYPTED);
        return $this->cryptoGen->decryptStandard($encryptedValue);
    }

    public function getGlobalSetting($settingKey)
    {
        return $this->globalsArray[$settingKey] ?? null;
    }

    public function getGlobalSettingSectionConfiguration()
    {
        return [];
    }
}
