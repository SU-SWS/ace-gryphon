<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;

/**
 * Class GryphonTestCommands.
 *
 * @package Example\Blt\Plugin\Commands
 */
class GryphonTestCommands extends BltTasks {

  /**
   * @command tests:codeception:acceptance
   */
  public function runCodeceptionAcceptanceTests() {
    $this->runCodeceptionTestSuite('acceptance');
  }

  /**
   * @command tests:codeception:functional
   */
  public function runCodeceptionFunctionalTests() {
    $this->runCodeceptionTestSuite('functional');
  }

  /**
   * @command tests:codeception:unit
   */
  public function runCodeceptionUnitTests() {
    $this->runCodeceptionTestSuite('unit');
  }

  /**
   * Execute codeception test suite.
   *
   * @param string $suite
   *   Codeception suite to run.
   */
  protected function runCodeceptionTestSuite($suite) {
    $root = $this->getConfigValue('repo.root');
    if (!file_exists("$root/tests/codeception/$suite.suite.yml")) {
      $this->taskFilesystemStack()
        ->copy("$root/tests/codeception/$suite.suite.dist.yml", "$root/tests/codeception/$suite.suite.yml")
        ->run();
      $this->getConfig()
        ->expandFileProperties("$root/tests/codeception/$suite.suite.yml");
    }
    $executable = $this->taskExec('vendor/bin/codecept')
      ->arg('run')
      ->arg($suite)
      ->option('config', 'tests', '=')
      ->option('html')
      ->option('xml')
      ->option('phpunit-xml');

    if ($this->input()->getOption('verbose')) {
      $executable->option('debug');
      $executable->option('verbose');
    }

    $executable->run();
  }

}
