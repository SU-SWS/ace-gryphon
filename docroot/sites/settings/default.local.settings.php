<?php

/**
 * @file
 * Local development override configuration feature.
 *
 * The settings in this file will be applied to every multisites.
 */

/**
 * SimpleSaml Workgroup configuration
 */
$config['stanford_ssp.settings'] = [
  'workgroup_api_url' => 'https://workgroupsvc.stanford.edu/v1/workgroups',
  'use_workgroup_api' => TRUE,
  'workgroup_api_cert' => DRUPAL_ROOT . '/../keys/workgroup_api.cert',
  'workgroup_api_key' => DRUPAL_ROOT . '/../keys/workgroup_api.key',
];

/**
 * SAMLAuth configuration
 */
$config['samlauth.authentication']['sp_x509_certificate'] = 'file:' . DRUPAL_ROOT . '/../keys/saml.crt';
$config['samlauth.authentication']['sp_private_key'] = 'file:' . DRUPAL_ROOT . '/../keys/saml.pem';
$config['samlauth.authentication']['idp_certs'] = [
  'file:' . DRUPAL_ROOT . '/../keys/signing.crt',
];
$config['stanford_samlauth.settings']['role_mapping']['workgroup_api'] = [
  'cert' => DRUPAL_ROOT . '/../keys/workgroup_api.cert',
  'key' => DRUPAL_ROOT . '/../keys/workgroup_api.key',
];

error_reporting(E_ALL & ~E_DEPRECATED);

// Saml login doesn't work on gitpod or tugboat, don't set config values.
if (getenv('GITPOD_WORKSPACE_URL') || getenv('TUGBOAT_REPO')) {
  unset($config['samlauth.authentication']);

  $config['stanford_samlauth.settings'] = [
    'hide_local_login' => FALSE,
    'local_login_fieldset_open' => true,
  ];
}
