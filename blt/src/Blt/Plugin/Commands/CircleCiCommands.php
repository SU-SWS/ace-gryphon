<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;

/**
 * Class GryphonCommands.
 */
class CircleCiCommands extends BltTasks {

  /**
   * @command circleci:drupal:setup
   */
  public function setupDrupal() {
    $root = $this->getConfigValue('repo.root');
    $tasks[] = $this->taskExec('dockerize -wait tcp://localhost:3306 -timeout 1m');
    $tasks[] = $this->taskExec('apachectl stop; apachectl start');

    $files = glob("$root/docroot/sites/*/local.*");
    $tasks[] = $this->taskFilesystemStack()->remove($files);
    $tasks[] = $this->blt()->arg('blt:telemetry:disable');
    $tasks[] = $this->blt()->arg('blt:init:setting');
    $tasks[] = $this->taskDrush()->drush('si minimal');

    return $this->collectionBuilder()->addTaskList($tasks)->run();
  }

  /**
   * @command circleci:drupal:install
   */
  public function installDrupal() {
    $this->invokeCommand('circleci:drupal:setup');
    return $this->blt()->arg('drupal:install')->run();
  }

  /**
   * Return BLT.
   *
   * @return \Robo\Task\Base\Exec
   *   A drush exec command.
   */
  protected function blt() {
    return $this->taskExec('vendor/bin/blt')
      ->option('verbose')
      ->option('no-interaction');
  }

}
