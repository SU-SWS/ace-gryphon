<?php

class DemoCest {

  /**
   * Validate the homepage loads.
   */
  public function testHomepage(AcceptanceTester $I) {
    $I->amOnTheHomepage();
    $I->canSee('Stanford');
  }

}
