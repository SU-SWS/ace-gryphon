<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use StanfordCaravan\Robo\Tasks\AcquiaApi;

/**
 * Class GryphonCommands.
 */
class GryphonCommands extends BltTasks {

  /**
   * @command gryphon:renew-certs:all
   */
  public function renewAllCerts() {
    $api = $this->getAcquiaApi();
    $environments = $api->getEnvironments();
    foreach ($environments['_embedded']['items'] as $environment_data) {
      if ($environment_data['name'] == 'ra') {
        continue;
      }
      $this->invokeCommand('gryphon:renew-certs', ['environment' => $environment_data['name']]);
    }
  }

  /**
   * @command gryphon:renew-certs
   */
  public function renewCerts($environment) {
    $api = $this->getAcquiaApi();

    return;
    $cert_name = $environment;

    $this->taskDeleteDir($this->getConfigValue('repo.root') . '/certs')->run();
    $this->taskDrush()
      ->alias("stanfordgryphon.$environment")
      ->drush("rsync --mode=rltDkz @default.prod:/home/stanfordgryphon/.acme.sh/$cert_name/ @self:../certs")
      ->run();

    $local_cert_dir = $this->getConfigValue('repo.root') . '/certs';

    $cert = file_get_contents("$local_cert_dir/$cert_name.cer");
    $key = file_get_contents("$local_cert_dir/$cert_name.key");
    $intermediate = file_get_contents("$local_cert_dir/ca.cer");

    $label = 'LE ' . date('Y-m-d G:i');
    $this->say($api->addCert($environment, $cert, $key, $intermediate, $label));

    $certs = $api->getCerts($environment);
    foreach ($certs['_embedded']['items'] as $cert) {
      if ($cert['label'] == $label) {
        $this->say($api->activateCert($environment, $cert['id']));
      }

      if (strtotime($cert['expires_at']) < time()) {
        $this->say($api->removeCert($environment, $cert['id']));
      }
    }

    $this->taskDeleteDir($local_cert_dir)->run();
  }

  /**
   * @return \StanfordCaravan\Robo\Tasks\AcquiaApi
   */
  protected function getAcquiaApi() {
    return new AcquiaApi($this->getConfigValue('cloud'), $this->getConfigValue('cloud.key'), $this->getConfigValue('cloud.secret'));
  }

}
