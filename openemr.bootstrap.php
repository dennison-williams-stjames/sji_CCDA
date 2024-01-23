<?php

/**
 * Bootstrap SJI CCDA module.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 *
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Modules\SJICCDAModule

/**
 * @global EventDispatcher $eventDispatcher Injected by the OpenEMR module loader;
 */

$bootstrap = new Bootstrap($eventDispatcher, $GLOBALS['kernel']);
$bootstrap->subscribeToEvents();
