<?php

/**
 * @file
 * Contains any config overrides.
 */

use Acquia\Blt\Robo\Common\EnvironmentDetector;

$config['simple_oauth.settings']['public_key'] = DRUPAL_ROOT . '/../keys/oauth.pub';
$config['simple_oauth.settings']['private_key'] = DRUPAL_ROOT . '/../keys/oauth.key';

if (EnvironmentDetector::isAhEnv()) {
  $config['simple_oauth.settings']['public_key'] = EnvironmentDetector::getAhFilesRoot() . '/nobackup/oauth.pub';
  $config['simple_oauth.settings']['private_key'] = EnvironmentDetector::getAhFilesRoot() . '/nobackup/oauth.key';
}

if (!EnvironmentDetector::isProdEnv()) {
  $config['domain_301_redirect.settings']['enabled'] = FALSE;
}
