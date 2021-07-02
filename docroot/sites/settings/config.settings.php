<?php

/**
 * @file
 * Contains any config overrides.
 */

use Acquia\Blt\Robo\Common\EnvironmentDetector;

if (!EnvironmentDetector::isProdEnv()) {
  $config['domain_301_redirect.settings']['enabled'] = FALSE;
}

$site_dir = substr($site_path, strrpos($site_path, '/') + 1);
$config['stage_file_proxy.settings']['origin'] = "https://$site_dir-prod.stanford.edu";
$config['stage_file_proxy.settings']['origin_dir'] = "sites/$site_dir/files";

