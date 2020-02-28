<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Common\EnvironmentDetector;
use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Core\Serialization\Yaml;
use Robo\Contract\VerbosityThresholdInterface;

/**
 * Class GryphonHooksCommands for any pre or post command hooks.
 */
class GryphonHooksCommands extends BltTasks {

  /**
   * @hook pre-command source:build:simplesamlphp-config
   */
  public function preSamlConfigCopy() {
    $task = $this->taskFilesystemStack()
      ->stopOnFail()
      ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE);
    $repo_root = $this->getConfigValue('repo.root');
    $copy_map = [
      $repo_root . '/simplesamlphp/config/default.local.config.php' => $repo_root . '/simplesamlphp/config/local.config.php',
      $repo_root . '/simplesamlphp/config/default.local.authsources.php' => $repo_root . '/simplesamlphp/config/local.authsources.php',
    ];
    foreach ($copy_map as $from => $to) {
      if (!file_exists($to)) {
        $task->copy($from, $to);
      }
    }
    $task->run();
    foreach ($copy_map as $to) {
      $this->getConfig()->expandFileProperties($to);
    }
  }

  /**
   * After a multisite is created, modify the drush alias with default values.
   *
   * @hook post-command recipes:multisite:init
   */
  public function postMultiSiteInit() {
    $root = $this->getConfigValue('repo.root');

    $default_alias = Yaml::decode(file_get_contents("$root/drush/sites/default.site.yml"));
    $sites = glob("$root/drush/sites/*.site.yml");
    foreach ($sites as $site_file) {
      $alias = Yaml::decode(file_get_contents($site_file));
      preg_match('/sites\/(.*)\.site\.yml/', $site_file, $matches);
      $site_name = $matches[1];

      if (count($alias) != count($default_alias)) {
        foreach ($default_alias as $environment => $env_alias) {
          $env_alias['uri'] = "$site_name.sites-pro.stanford.edu";
          $alias[$environment] = $env_alias;
        }
      }

      file_put_contents($site_file, Yaml::encode($alias));
    }
  }

}
