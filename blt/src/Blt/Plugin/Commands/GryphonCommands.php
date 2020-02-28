<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use StanfordCaravan\Robo\Tasks\AcquiaApi;
use Symfony\Component\Console\Question\Question;

/**
 * Class GryphonCommands.
 */
class GryphonCommands extends BltTasks {

  /**
   * Add a new domain to the site and LE Cert.
   *
   * @command gryphon:add-domain
   * @aliases gad
   */
  public function addDomain() {
    $this->say('Getting environment information...');
    $api = $this->getAcquiaApi();
    $environments = $api->getEnvironments();
    $environment_options = [];
    foreach ($environments['_embedded']['items'] as $environment_data) {
      // Skip RA Environment.
      if ($environment_data['name'] == 'ra') {
        continue;
      }
      $environment_options[$environment_data['name']] = $environment_data['name'];
    }
    $environment_options = array_values($environment_options);
    $environment = $this->askChoice('Which environment would you like to add a new domain to', $environment_options);
    $this->say($environment);
    $new_domain = $this->getNewDomain('What is the new url?');
    $api->addDomain($environment, $new_domain);
  }

  /**
   * Add a new domain to the LE Cert.
   *
   * @command gryphon:add-cert-domain
   * @aliases gacd
   */
  public function addDomainToCert($environment, $domain) {
    $ssh_env = $environment == 'prod' ? '': $environment;
    exec(sprintf('ssh stanfordgryphon.%s@stanfordgryphon%s.ssh.prod.acquia-sites.com "~/.acme.sh/acme.sh --list --listraw"', $environment, $ssh_env), $certs);

    $header = NULL;
    $cert_data = [];
    foreach ($certs as $line) {
      $line = str_getcsv($line, '|');
      if (!$header) {
        $header = $line;
        continue;
      }

      $data = array_combine($header, $line);
      preg_match('/gryphon(.*)\.prod/', $data['Main_Domain'], $cert_env);
      $cert_data[$cert_env[1] ?: 'prod'] = $data;
    }
    if (isset($cert_data[$environment])) {
      $domains = explode(',', $cert_data[$environment]['SAN_Domains']);
    }
    $domains[] = $domain;
    asort($domains);
    if (isset($cert_data[$environment])) {
      array_unshift($domains, $cert_data[$environment]['Main_Domain']);
    }
    $domains = '-d ' . implode(' -d ', $domains);

    exec(sprintf('ssh stanfordgryphon.%s@stanfordgryphon%s.ssh.prod.acquia-sites.com "~/.acme.sh/acme.sh --issue %s -w /mnt/gfs/stanfordgryphon.%s/tmp"', $environment, $ssh_env, $domains, $environment), $output);
    $this->say($output);
  }

  /**
   * Renew LE Certs for all environments.
   *
   * @command gryphon:renew-certs:all
   * @aliases grca
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
   * @aliases grc
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
   * @aliases gem
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
   * Get encryption keys from acquia.
   *
   * @command gryphon:keys
   * @aliases gkey
   */
  public function cardinalsitesKeys() {
    $keys_dir = $this->getConfigValue('repo.root') . '/keys';
    if (!is_dir($keys_dir)) {
      mkdir($keys_dir, 0777, TRUE);
    }
    $this->taskRsync()
      ->fromPath('stanfordgryphon.prod@stanfordgryphon.ssh.prod.acquia-sites.com:/mnt/gfs/stanfordgryphon.prod/nobackup/simplesamlphp/')
      ->toPath($keys_dir)
      ->recursive()
      ->excludeVcs()
      ->verbose()
      ->progress()
      ->humanReadable()
      ->stats()
      ->run();
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

  /**
   * Ask the user for a new stanford url and validate the entry.
   *
   * @param string $message
   *   Prompt for the user.
   *
   * @return string
   *   User entered value.
   */
  protected function getNewDomain($message) {
    $question = new Question($this->formatQuestion($message));
    $question->setValidator(function ($answer) {
      if (empty($answer) || !preg_match('/\.stanford\.edu/', $answer) || preg_match('/^http/', $answer)) {
        throw new \RuntimeException(
          'You must provide a stanford.edu url. ie gryphon.stanford.edu'
        );
      }

      return $answer;
    });
    return $this->doAsk($question);
  }

}
