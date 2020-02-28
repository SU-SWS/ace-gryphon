<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use StanfordCaravan\Robo\Tasks\AcquiaApi;

/**
 * Class GryphonCommands.
 */
class GryphonCommands extends BltTasks {

  /**
   * Renew LE Certs for all environments.
   *
   * @command gryphon:renew-certs:all
   */
  public function renewAllCerts() {
    $api = $this->getAcquiaApi();
    $environments = $api->getEnvironments();

    foreach ($environments['_embedded']['items'] as $environment_data) {
      // Skip RA Environment.
      if ($environment_data['name'] == 'ra') {
        continue;
      }
      $this->say(sprintf('Renewing certs for %s', $environment_data['name']));
      $this->invokeCommand('gryphon:renew-certs', ['environment' => $environment_data['name']]);
    }
  }

  /**
   * Renew LE Certs for a single environment.
   *
   * @command gryphon:renew-certs
   *
   * @param string $environment
   *   Environment name like `dev`, `test`, or `ode123`.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   * @throws \Robo\Exception\TaskException
   */
  public function renewCerts($environment) {
    $api = $this->getAcquiaApi();
    $this->invokeCommand('gryphon:enable-modules', [
      'environment' => $environment,
      'modules' => 'letsencrypt_challenge',
    ]);

    // The names of the cert are different each environment.
    switch ($environment) {
      case 'test':
        $cert_name = "stanfordgryphonstg.prod.acquia-sites.com";
        break;

      case 'prod':
        $cert_name = "stanfordgryphon.prod.acquia-sites.com";
        break;

      default:
        $cert_name = "stanfordgryphon$environment.prod.acquia-sites.com";
    }

    // Download the certs to local file system.
    $this->taskDeleteDir($this->getConfigValue('repo.root') . '/certs')->run();
    $this->taskDrush()
      ->drush("rsync --mode=rltDkz @default.$environment:/home/stanfordgryphon/.acme.sh/$cert_name/ @self:../certs")
      ->run();

    $local_cert_dir = $this->getConfigValue('repo.root') . '/certs';

    $cert = file_get_contents("$local_cert_dir/$cert_name.cer");
    $key = file_get_contents("$local_cert_dir/$cert_name.key");
    $intermediate = file_get_contents("$local_cert_dir/ca.cer");

    // Upload the cert information to acquia.
    $label = 'LE ' . date('Y-m-d G:i');
    $this->say(var_export($api->addCert($environment, $cert, $key, $intermediate, $label), TRUE));

    $certs = $api->getCerts($environment);
    foreach ($certs['_embedded']['items'] as $cert) {
      // Find the cert we just created and activate it.
      if ($cert['label'] == $label) {
        $this->say(var_export($api->activateCert($environment, $cert['id']), TRUE));
        continue;
      }

      // Remove any certs that are outdated.
      if (strtotime($cert['expires_at']) < time()) {
        $this->say(var_export($api->removeCert($environment, $cert['id']), TRUE));
      }
    }
    // Cleanup the local certs files.
    $this->taskDeleteDir($local_cert_dir)->run();
  }

  /**
   * Enable a list of modules for all sites on a given environment.
   *
   * @param string $environment
   *   Environment name like `dev`, `test`, or `ode123`.
   * @param string $modules
   *   Comma separated list of modules to enable.
   *
   * @example blt gryphon:enable-modules dev views_ui,field
   *
   * @command gryphon:enable-modules
   *
   */
  public function enableModules($environment, $modules) {
    $commands = $this->collectionBuilder();

    $docroot = $this->getConfigValue('docroot');
    $sites = glob("$docroot/sites/*/settings.php");

    foreach ($sites as $path) {
      $path = str_replace('/settings.php', '', $path);
      $site = substr($path, strrpos($path, '/') + 1);

      $commands->addTask($this->taskDrush()
        ->alias("$site.$environment")
        ->drush('en ' . $modules));
    }

    $commands->run();
  }

  /**
   * Get initialized Acquia api object.
   *
   * @return \StanfordCaravan\Robo\Tasks\AcquiaApi
   *   Acquia API.
   */
  protected function getAcquiaApi() {
    $key = $this->getConfigValue('cloud.key') ?: $_ENV['ACP_KEY'];
    $secret = $this->getConfigValue('cloud.secret') ?: $_ENV['ACP_SECRET'];
    return new AcquiaApi($this->getConfigValue('cloud.appId'), $key, $secret);
  }

}
