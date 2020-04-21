<?php

namespace Example\Blt\Plugin\Commands;

use Acquia\Blt\Robo\BltTasks;
use Acquia\Blt\Robo\Exceptions\BltException;
use Robo\ResultData;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class GryphonTestCommands.
 *
 * @package Example\Blt\Plugin\Commands
 */
class GryphonTestCommands extends BltTasks {

  /**
   * Runs all tests, including Behat, PHPUnit, and security updates check.
   *
   * @command tests
   *
   * @aliases ta test tests:all
   */
  public function tests() {
    $this->invokeCommands([
      'tests:behat:run',
      'tests:phpunit:run',
      'tests:codeception:run',
      'tests:drupal:run',
      'tests:security:check:updates',
      'tests:security:check:composer',
      'tests:frontend:run',
    ]);
  }

  /**
   * Run all the codeception tests defined in blt.yml.
   *
   * @command tests:codeception:run
   * @aliases tests:codeception codeception
   *
   * @param string $test_key
   *   Specify which test to run.
   *
   * @return \Robo\Result
   *   Result of the test.
   */
  public function runCodeceptionTests($test_key) {
    $failed_test = NULL;
    if ($test = $this->getConfigValue('tests.codeception.' . $test_key)) {
      foreach ($test['suites'] as $suite) {
        $this->say("Running <comment>$suite</comment> Tests.");
        $test_result = $this->runCodeceptionTestSuite($suite, $test['directory']);

        if (!$test_result->wasSuccessful()) {
          $failed_test = $test_result;
        }
      }
    }
    return $failed_test ?: $test_result;
  }

  /**
   * Execute codeception test suite.
   *
   * @param string $suite
   *   Codeception suite to run.
   * @param string $test_directory
   *   Directory to codeception tests.
   *
   * @return \Robo\Result|\Robo\ResultData
   *   Result of the test.
   */
  protected function runCodeceptionTestSuite($suite, $test_directory) {
    if (!file_exists("$test_directory/$suite/")) {
      return new ResultData(ResultData::EXITCODE_OK, 'No tests to execute for suite ' . $suite);
    }

    $root = $this->getConfigValue('repo.root');
    if (!file_exists("$root/tests/codeception.yml")) {
      $this->taskFilesystemStack()
        ->copy("$root/tests/codeception.dist.yml", "$root/tests/codeception.yml")
        ->run();
      $this->getConfig()
        ->expandFileProperties("$root/tests/codeception.yml");
    }

    $new_test_dir = "$root/tests/codeception/$suite/" . date('Ymd-Hi');
    $tasks[] = $this->taskFilesystemStack()->mkdir($new_test_dir);
    $tasks[] = $this->taskRsync()
      ->recursive()
      ->fromPath("$test_directory/$suite/")
      ->toPath($new_test_dir);

    $test = $this->taskExec('vendor/bin/codecept')
      ->arg('run')
      ->arg($suite)
      ->option('steps')
      ->option('config', 'tests', '=')
      ->option('override', "paths: output: ../artifacts/$suite", '=')
      ->option('html')
      ->option('xml');

    if ($this->input()->getOption('verbose')) {
      $test->option('debug');
      $test->option('verbose');
    }
    $tasks[] = $test;
    $test_result = $this->collectionBuilder()->addTaskList($tasks)->run();
    // Regardless if the test failed or succeeded, always clean up the temporary
    // test directory.
    $this->taskDeleteDir($new_test_dir)->run();

    // Delete the failed file because codeception will try to look for the file
    // that failed again on the next run. Since we have temporary test
    // directories we don't want to save that data.
    foreach (glob("$root/artifacts/*/failed") as $file) {
      $this->taskFilesystemStack()
        ->remove($file)
        ->run();
    }

    return $test_result;
  }

  /**
   * Setup and run PHPUnit tests with code coverage.
   *
   * @command tests:phpunit:coverage:run
   * @aliases tprc phpunit:coverage tests:phpunit:coverage
   * @description Executes all PHPUnit "Unit" and "Kernel" tests with coverage
   *   report.
   *
   * @throws \Exception
   *   Throws an exception if any test fails.
   */
  public function runPhpUnitTestsCoverage() {
    $this->taskExec('vendor/bin/pcov clobber')->run();
    $report_directory = $this->getConfigValue('tests.reports.localDir') . '/phpunit';

    $config = $this->getConfigValue('tests.phpunit');
    try {
      $this->executeUnitCoverageTests($config, $report_directory);
    }
    catch (\Exception $e) {
      throw $e;
    }
  }

  /**
   * Executes all PHPUnit tests.
   *
   * This method is copied from Acquia BLT command for running phpunit tests.
   * But it adds the coverage options and filters for only Unit and Kernel
   * tests. Running Functional tests takes far too long since XDebug has to
   * listen to the entire bootstrap process.
   *
   * @param array|null $config
   *   Blt phpunit configuration.
   * @param string $report_directory
   *   Local reports directory.
   *
   * @throws \Acquia\Blt\Robo\Exceptions\BltException
   *
   * @see \Acquia\Blt\Robo\Commands\Tests\PhpUnitCommand::executeUnitCoverageTests()
   */
  public function executeUnitCoverageTests($config, $report_directory) {
    if (is_array($config)) {
      foreach ($config as $test) {
        $task = $this->taskPhpUnitTask()
          ->xml($report_directory . '/coverage/results.xml')
          ->printOutput(TRUE)
          ->printMetadata(FALSE);

        // Add coverage report output.
        $task->option('coverage-html', $report_directory . '/coverage/html', '=');
        $task->option('coverage-xml', $report_directory . '/coverage/xml', '=');

        if (isset($test['path'])) {
          $task->dir($test['path']);
        }

        if ($this->output()
            ->getVerbosity() >= OutputInterface::VERBOSITY_NORMAL) {
          $task->printMetadata(TRUE);
          $task->verbose();
        }

        if (isset($this->testingEnvString)) {
          $task->testEnvVars($this->testingEnvString);
        }

        if (isset($this->apacheRunUser)) {
          $task->user($this->apacheRunUser);
        }

        if (isset($this->sudoRunTests) && ($this->sudoRunTests)) {
          $task->sudo();
        }

        if (isset($test['bootstrap'])) {
          $task->bootstrap($test['bootstrap']);
        }

        if (isset($test['config'])) {
          $task->configFile($test['config']);
        }

        if (isset($test['debug']) && ($test['debug'])) {
          $task->debug();
        }

        if (isset($test['exclude'])) {
          $task->excludeGroup($test['exclude']);
        }

        // Only run Unit and Kernel tests.
        $task->filter('/(Unit|Kernel)/');

        if (isset($test['group'])) {
          $task->group($test['group']);
        }

        if (isset($test['printer'])) {
          $task->printer($test['printer']);
        }

        if (isset($test['stop-on-error']) && ($test['stop-on-error'])) {
          $task->stopOnError();
        }

        if (isset($test['stop-on-failure']) && ($test['stop-on-failure'])) {
          $task->stopOnFailure();
        }

        if (isset($test['testdox']) && ($test['testdox'])) {
          $task->testdox();
        }

        if (isset($test['class'])) {
          $task->arg($test['class']);
          if (isset($test['file'])) {
            $task->arg($test['file']);
          }
        }
        else {
          if (isset($test['directory'])) {
            $task->arg($test['directory']);
          }
        }

        if ((isset($test['testsuites']) && is_array($test['testsuites'])) || isset($test['testsuite'])) {
          if (isset($test['testsuites'])) {
            $task->testsuite(implode(',', $test['testsuites']));
          }
          elseif (isset($test['testsuite'])) {
            $task->testsuite($test['testsuite']);
          }
        }

        $result = $task->run();
        if (!$result->wasSuccessful()) {
          throw new BltException("PHPUnit tests failed.");
        }
      }
    }
  }

}
