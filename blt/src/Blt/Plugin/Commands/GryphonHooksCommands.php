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
   * @hook pre-command tests:phpunit:run
   */
  public function prePhpUnitRun() {
    $root = $this->getConfigValue('repo.root');
    $docroot = $this->getConfigValue('docroot');

    $task = $this->taskFilesystemStack();
    if (!file_exists("$docroot/core/phpunit.xml")) {
      $task->copy("$root/tests/phpunit/example.phpunit.xml", "$docroot/core/phpunit.xml")
        ->run();
      if (empty($this->getConfigValue('drupal.db.password'))) {
        // If the password is empty, remove the colon between the username &
        // password. This prevents the system from thinking its supposed to
        // use a password.
        $file_contents = file_get_contents("$docroot/core/phpunit.xml");
        str_replace(':${drupal.db.password}', '', $file_contents);
        file_put_contents("$docroot/core/phpunit.xml", $file_contents);
      }
      $this->getConfig()->expandFileProperties("$docroot/core/phpunit.xml");
    }
  }

  /**
   * @hook pre-command tests:phpunit:coverage:run
   */
  public function prePhpUnitCoverageRun() {
    $this->prePhpUnitRun();
  }

  /**
   * @hook post-command tests:phpunit:coverage:run
   */
  public function phpUnitCoverCheck() {
    $report = $this->getConfigValue('tests.reports.localDir') . '/phpunit/coverage/xml/index.xml';
    if (!file_exists($report)) {
      throw new \Exception('Coverage report not found at ' . $report);
    }

    libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHtml(file_get_contents($report));
    $xpath = new \DOMXPath($dom);

    $coverage_percent = $xpath->query("//directory[@name='/']/totals/lines/@percent");
    $percent = (float) $coverage_percent->item(0)->nodeValue;
    $pass = $this->getConfigValue('tests.reports.coveragePass');
    if ($pass > $percent) {
      throw new \Exception("Test coverage is only at $percent%. $pass% is required.");
    }
    $this->yell(sprintf('Coverage at %s%%. %s%% required.', $percent, $pass));
  }

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
    $multisites = [];

    $default_alias = Yaml::decode(file_get_contents("$root/drush/sites/default.site.yml"));
    $sites = glob("$root/drush/sites/*.site.yml");
    foreach ($sites as $site_file) {
      $alias = Yaml::decode(file_get_contents($site_file));
      preg_match('/sites\/(.*)\.site\.yml/', $site_file, $matches);
      $site_name = $matches[1];

      $multisites[] = $site_name;
      if (count($alias) != count($default_alias)) {
        foreach ($default_alias as $environment => $env_alias) {
          $env_alias['uri'] = "$site_name.sites-pro.stanford.edu";
          $alias[$environment] = $env_alias;
        }
      }

      file_put_contents($site_file, Yaml::encode($alias));
    }

    // Add the site to the multisites in BLT's configuration.
    $root = $this->getConfigValue('repo.root');
    $blt_config = Yaml::decode(file_get_contents("$root/blt/blt.yml"));
    asort($multisites);
    $blt_config['multisites'] = array_unique($multisites);
    file_put_contents("$root/blt/blt.yml", Yaml::encode($blt_config));

    $this->say(sprintf('Remember to create the cron task. Run <info>gryphon:create-cron</info> to create the new cron job.'));
    $create_db = $this->ask('Would you like to create the database on Acquia now? (y/n)');
    if (substr(strtolower($create_db), 0, 1) == 'y') {
      $this->invokeCommand('gryphon:create-database');
    }
  }

  /**
   * Deletes any local related file from artifact after BLT copies them over.
   *
   * @hook post-command artifact:build:simplesamlphp-config
   */
  public function postArtifactSamlConfigCopy() {
    $deploy_dir = $this->getConfigValue('deploy.dir');
    $files = glob("$deploy_dir/vendor/simplesamlphp/simplesamlphp/config/*local.*");
    $task = $this->taskFileSystemStack();
    foreach ($files as $file) {
      $task->remove($file);
    }
    $task->run();
  }

  /**
   * Copy the default global settings for local settings.
   *
   * @hook post-command blt:init:settings
   */
  public function postInitSettings() {
    $docroot = $this->getConfigValue('docroot');
    if (!file_exists("$docroot/sites/settings/local.settings.php")) {
      $this->taskFilesystemStack()
        ->stopOnFail()
        ->setVerbosityThreshold(VerbosityThresholdInterface::VERBOSITY_VERBOSE)
        ->copy("$docroot/sites/settings/default.local.settings.php", "$docroot/sites/settings/local.settings.php")
        ->run();

      $this->getConfig()
        ->expandFileProperties("$docroot/sites/settings/local.settings.php");
    }
    if (EnvironmentDetector::isLocalEnv()) {
      $this->invokeCommand('gryphon:keys');
    }
  }

  /**
   * Switch the context for the site that was copied.
   *
   * @hook pre-command artifact:ac-hooks:db-scrub
   */
  public function preDbScrub(CommandData $comand_data) {
    $args_options = $comand_data->getArgsAndOptions();
    // Databases should correlate directly to the site name. Except the default
    // directory which has a different database name. This allows the db scrub
    // drush command to operate on the correct database.
    $site = $args_options['db_name'] == 'stanfordgryphon' ? 'default' : $args_options['db_name'];
    $this->switchSiteContext($site);
  }

  /**
   * Set nobots to emit headers for non-production sites.
   *
   * @hook post-command artifact:ac-hooks:post-db-copy
   */
  public function postDbCopy($result, CommandData $comand_data) {
    if (!EnvironmentDetector::isProdEnv()) {
      // Disable alias since we are targeting specific uri.
      $this->config->set('drush.alias', '');

      foreach ($this->getConfigValue('multisites') as $multisite) {
        $this->switchSiteContext($multisite);
        $this->taskDrush()->drush('state:set nobots 1')->run();
      }
    }
  }

}
