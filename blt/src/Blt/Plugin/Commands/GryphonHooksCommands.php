<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Common\EnvironmentDetector;
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

}
