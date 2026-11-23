<?php

/**
 * CMI payment module for PrestaShop.
 *
 * @author    CMI
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * From 1.x to 2.0.0: settings renamed (values kept), transaction tables, order states,
 * new hooks, and the files of 1.x removed.
 *
 * @param Cmi $module
 */
function upgrade_module_2_0_0($module)
{
    return (new Cmi\PrestaShop\Install\Installer($module))->upgradeFromLegacy();
}
