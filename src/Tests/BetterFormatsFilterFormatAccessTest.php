<?php

namespace Drupal\better_formats\Tests;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessResult;
use Drupal\simpletest\WebTestBase;

/**
 * Tests access to text formats.
 *
 * @group Access
 * @group filter
 */
class BetterFormatsFilterFormatAccessTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['block', 'filter', 'node', 'better_formats'];

  /**
   * A user with administrative permissions.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A user with 'administer filters' permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $filterAdminUser;

  /**
   * A user with permission to create and edit own content.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $webUser;

  /**
   * An object representing an allowed text format.
   *
   * @var object
   */
  protected $allowedFormat;

  /**
   * An object representing a secondary allowed text format.
   *
   * @var object
   */
  protected $secondAllowedFormat;

  /**
   * An object representing a disallowed text format.
   *
   * @var object
   */
  protected $disallowedFormat;

  protected function setUp() {
    parent::setUp();

    $this->drupalCreateContentType(array('type' => 'page', 'name' => 'Basic page'));

    // Create a user who can administer text formats, but does not have
    // specific permission to use any of them.
    $this->filterAdminUser = $this->drupalCreateUser(array(
      'administer filters',
      'create page content',
      'edit any page content',
    ));

    // Create three text formats. Two text formats are created for all users so
    // that the drop-down list appears for all tests.
    $this->drupalLogin($this->filterAdminUser);
    $formats = array();
    for ($i = 0; $i < 3; $i++) {
      $edit = array();
      $edit['format'] = Unicode::strtolower($this->randomMachineName());
      $edit['name'] = $this->randomMachineName();
      $edit['filters[filter_autop][status]'] = TRUE;
      $this->drupalPostForm('admin/config/content/formats/add', $edit, t('Save configuration'));
      $this->resetFilterCaches();
      $formats[] = entity_load('filter_format', $edit['format']);
    }
    list($this->allowedFormat, $this->secondAllowedFormat, $this->disallowedFormat) = $formats;
    $this->drupalLogout();

    // Create a regular user with access to two of the formats.
    $this->webUser = $this->drupalCreateUser(array(
      'create page content',
      'edit any page content',
      'show format selection for node',
      $this->allowedFormat->getPermissionName(),
      $this->secondAllowedFormat->getPermissionName(),
    ));

    // Create an administrative user who has access to use all three formats.
    $this->adminUser = $this->drupalCreateUser(array(
      'administer filters',
      'create page content',
      'edit any page content',
      'show format tips',
      'show more format tips link',
      $this->allowedFormat->getPermissionName(),
      $this->secondAllowedFormat->getPermissionName(),
      $this->disallowedFormat->getPermissionName(),
    ));
  }

  /**
   * Tests the Filter format access permissions functionality.
   */
  function testFormatPermissions() {
    // Make sure that a regular user only has access to the text formats for
    // which they were granted access.
    $fallback_format = entity_load('filter_format', filter_fallback_format());
    $this->assertTrue($this->allowedFormat->access('use', $this->webUser), 'A regular user has access to use a text format they were granted access to.');
    $this->assertEqual(AccessResult::allowed()->addCacheContexts(['user.permissions']), $this->allowedFormat->access('use', $this->webUser, TRUE), 'A regular user has access to use a text format they were granted access to.');
    $this->assertFalse($this->disallowedFormat->access('use', $this->webUser), 'A regular user does not have access to use a text format they were not granted access to.');
    $this->assertEqual(AccessResult::neutral()->cachePerPermissions(), $this->disallowedFormat->access('use', $this->webUser, TRUE), 'A regular user does not have access to use a text format they were not granted access to.');
    $this->assertTrue($fallback_format->access('use', $this->webUser), 'A regular user has access to use the fallback format.');
    $this->assertEqual(AccessResult::allowed(), $fallback_format->access('use', $this->webUser, TRUE), 'A regular user has access to use the fallback format.');

    // Perform similar checks as above, but now against the entire list of
    // available formats for this user.
    $this->assertTrue(in_array($this->allowedFormat->id(), array_keys(filter_formats($this->webUser))), 'The allowed format appears in the list of available formats for a regular user.');
    $this->assertFalse(in_array($this->disallowedFormat->id(), array_keys(filter_formats($this->webUser))), 'The disallowed format does not appear in the list of available formats for a regular user.');
    $this->assertTrue(in_array(filter_fallback_format(), array_keys(filter_formats($this->webUser))), 'The fallback format appears in the list of available formats for a regular user.');

    // Make sure that a regular user only has permission to use the format
    // they were granted access to.
    $this->assertTrue($this->webUser->hasPermission($this->allowedFormat->getPermissionName()), 'A regular user has permission to use the allowed text format.');
    $this->assertFalse($this->webUser->hasPermission($this->disallowedFormat->getPermissionName()), 'A regular user does not have permission to use the disallowed text format.');

    // Verify that filter format tips and link are controlled by permissions.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('node/add/page');
    $this->assertRaw('Lines and paragraphs break automatically.');
    $this->assertLinkByHref('filter/tips');

    $this->drupalLogin($this->webUser);
    $this->drupalGet('node/add/page');
    $this->assertNoRaw('Lines and paragraphs break automatically.');
    $this->assertNoLinkByHref('filter/tips');

    // Make sure that the allowed format appears on the node form and that
    // the disallowed format does not.
    $elements = $this->xpath('//select[@name=:name]/option', array(
      ':name' => 'body[0][format]',
      ':option' => $this->allowedFormat->id(),
    ));
    $options = array();
    foreach ($elements as $element) {
      $options[(string) $element['value']] = $element;
    }
    $this->assertTrue(isset($options[$this->allowedFormat->id()]), 'The allowed text format appears as an option when adding a new node.');
    $this->assertFalse(isset($options[$this->disallowedFormat->id()]), 'The disallowed text format does not appear as an option when adding a new node.');
    $this->assertFalse(isset($options[filter_fallback_format()]), 'The fallback format does not appear as an option when adding a new node.');

    // Check regular user access to the filter tips pages.
    $this->drupalGet('filter/tips/' . $this->allowedFormat->id());
    $this->assertResponse(200);
    $this->drupalGet('filter/tips/' . $this->disallowedFormat->id());
    $this->assertResponse(403);
    $this->drupalGet('filter/tips/' . filter_fallback_format());
    $this->assertResponse(200);
    $this->drupalGet('filter/tips/invalid-format');
    $this->assertResponse(404);

    // Check admin user access to the filter tips pages.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('filter/tips/' . $this->allowedFormat->id());
    $this->assertResponse(200);
    $this->drupalGet('filter/tips/' . $this->disallowedFormat->id());
    $this->assertResponse(200);
    $this->drupalGet('filter/tips/' . filter_fallback_format());
    $this->assertResponse(200);
    $this->drupalGet('filter/tips/invalid-format');
    $this->assertResponse(404);
  }

  /**
   * Rebuilds text format and permission caches in the thread running the tests.
   */
  protected function resetFilterCaches() {
    filter_formats_reset();
  }

}
