<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_views\Kernel;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\RenderContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\jsonapi_resources\Kernel\Traits\RequestTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Drupal\jsonapi_views\Plugin\views\display_extender\JsonapiViews;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use Drupal\user\UserInterface;
use Drupal\views\Entity\View;
use Drupal\views\Tests\ViewTestData;
use Drupal\views\Views;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the JSON:API Views resource via the HTTP kernel.
 *
 * The existing Functional test coverage (JsonapiViewsResourceTest) makes
 * real HTTP requests to a PHP subprocess, so PHPUnit's coverage driver
 * cannot see ViewsResource's execution there. Requests here are dispatched
 * in-process through the HTTP kernel instead, so coverage is measured.
 *
 * @group jsonapi_views
 */
final class JsonapiViewsResourceKernelTest extends KernelTestBase {

  use NodeCreationTrait;
  use RequestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'views',
    'serialization',
    'jsonapi',
    'jsonapi_resources',
    'jsonapi_views',
    'jsonapi_views_test',
  ];

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['jsonapi_views_test_node_view'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'filter', 'node', 'jsonapi_views_test']);

    // Kernel tests enable modules without running their install hooks, so
    // jsonapi_views_install()'s registration of the jsonapi_views display
    // extender never runs. Without it, JsonapiViews::isExposed() is never
    // consulted and every display defaults to exposed.
    $this->config('views.settings')->set('display_extenders', ['jsonapi_views'])->save();

    ViewTestData::createTestViews(self::class, ['jsonapi_views_test']);

    // Ensure neither role grants any permission by default - tests grant
    // exactly what they need.
    foreach ([RoleInterface::ANONYMOUS_ID, RoleInterface::AUTHENTICATED_ID] as $role_id) {
      $role = Role::load($role_id);
      foreach ($role->getPermissions() as $permission) {
        $role->revokePermission($permission);
      }
      $role->save();
    }

    $account = $this->createUser();
    assert($account instanceof UserInterface);
    $this->container->get('current_user')->setAccount($account);

    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Grants permissions to the authenticated role.
   *
   * @param string[] $permissions
   *   Permissions to grant.
   */
  protected function grantPermissionsToTestedRole(array $permissions): void {
    $role = Role::load(RoleInterface::AUTHENTICATED_ID);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();
  }

  /**
   * Builds a GET request against a JSON:API Views resource.
   *
   * @param string $view_id
   *   The view machine name.
   * @param string $display_id
   *   The display machine name.
   * @param array $query
   *   Query parameters.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   The request.
   */
  private function jsonApiRequest(string $view_id, string $display_id, array $query = []): Request {
    $request = Request::create('/jsonapi/views/' . $view_id . '/' . $display_id, 'GET', $query);
    $request->headers->set('Accept', 'application/vnd.api+json');
    return $request;
  }

  /**
   * Tests the page display: listing, pagination links, and cache metadata.
   */
  public function testPageDisplayWithPagination(): void {
    $this->grantPermissionsToTestedRole(['access content']);

    // The page_1 display paginates 5 per page; create 7 to force a 'next'
    // link and exercise ViewsResource::getViewsPager()'s prev/next branches.
    for ($i = 0; $i < 7; $i++) {
      $this->createNode(['type' => 'room', 'status' => 1]);
    }

    $response = $this->request($this->jsonApiRequest('jsonapi_views_test_node_view', 'page_1'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

    $document = self::decodeResponse($response);
    $this->assertCount(5, $document['data']);
    $this->assertEquals(7, $document['meta']['count'] ?? NULL);
    $this->assertArrayHasKey('next', $document['links']);
    $this->assertArrayNotHasKey('prev', $document['links']);

    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $cache_contexts = $response->getCacheableMetadata()->getCacheContexts();
    $this->assertContains('url.query_args:page', $cache_contexts);

    // Follow the 'next' link to also exercise the 'prev' link branch.
    $next_request = Request::create($document['links']['next']['href'], 'GET');
    $next_request->headers->set('Accept', 'application/vnd.api+json');
    $response = $this->request($next_request);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

    $document = self::decodeResponse($response);
    $this->assertCount(2, $document['data']);
    $this->assertArrayHasKey('prev', $document['links']);
    $this->assertArrayNotHasKey('next', $document['links']);
  }

  /**
   * Tests a display disabled via the JsonapiViews display extender.
   *
   * The feed_1 display sets display_extenders.jsonapi_views.enabled=false
   * in the test view fixture, so it exercises ViewsResource::process()'s
   * early-return 403 branch.
   */
  public function testUnexposedDisplayReturns403(): void {
    $this->grantPermissionsToTestedRole(['access content']);

    $response = $this->request($this->jsonApiRequest('jsonapi_views_test_node_view', 'feed_1'), TRUE);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Tests the attachment display and its hard-coded filtered result set.
   */
  public function testAttachmentDisplay(): void {
    $this->grantPermissionsToTestedRole(['access content']);

    $location = $this->createNode(['type' => 'location', 'status' => 1]);
    $this->createNode(['type' => 'room', 'status' => 1]);

    $response = $this->request($this->jsonApiRequest('jsonapi_views_test_node_view', 'attachment_1'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

    $document = self::decodeResponse($response);
    $this->assertCount(1, $document['data']);
    $this->assertSame($location->uuid(), $document['data'][0]['id']);
  }

  /**
   * Tests exposed filter and sort query parameters.
   */
  public function testExposedFiltersAndSorts(): void {
    $this->grantPermissionsToTestedRole(['access content', 'bypass node access']);

    $unpublished = $this->createNode(['type' => 'room', 'status' => 0]);
    $published = $this->createNode(['type' => 'room', 'status' => 1]);

    $response = $this->request($this->jsonApiRequest(
      'jsonapi_views_test_node_view',
      'page_1',
      ['views-filter' => ['status' => '0']],
    ));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

    $document = self::decodeResponse($response);
    $this->assertSame([$unpublished->uuid()], array_map(
      static fn(array $data) => $data['id'],
      $document['data'],
    ));

    $response = $this->request($this->jsonApiRequest(
      'jsonapi_views_test_node_view',
      'page_1',
      ['views-sort' => ['sort_order' => 'DESC']],
    ));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());

    $document = self::decodeResponse($response);
    $this->assertSame(
      [$published->uuid(), $unpublished->uuid()],
      array_map(static fn(array $data) => $data['id'], $document['data']),
    );
  }

  /**
   * Tests route building for two views that share one entity type.
   *
   * Routes::routes() caches the resource type names for each entity type
   * (#3484714). A second view with the same entity type reuses that
   * cache. It does not compute the names again. This test adds a second
   * 'node' view and checks two things: the route gives the correct
   * resource type, and the cache was used. A spy repository counts each
   * call. If a future change breaks the cache, this test fails, even
   * though the response stays correct.
   */
  public function testMultipleViewsShareEntityTypeResourceTypeCache(): void {
    $this->grantPermissionsToTestedRole(['access content']);

    $second_view = View::create([
      'id' => 'jsonapi_views_test_node_view_2',
      'label' => 'JSON:API Views Test Node View 2',
      'base_table' => 'node_field_data',
      'display' => [
        'default' => [
          'display_plugin' => 'default',
          'id' => 'default',
          'display_options' => [
            'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
          ],
        ],
        'page_1' => [
          'display_plugin' => 'page',
          'id' => 'page_1',
          'display_options' => [
            'path' => 'jsonapi-views-test-node-view-2',
          ],
        ],
      ],
    ]);
    $second_view->save();

    // Replace the resource type repository with a spy. The spy counts
    // each call to get(), by entity type and bundle.
    $real_repository = $this->container->get('jsonapi.resource_type.repository');
    assert($real_repository instanceof ResourceTypeRepositoryInterface);
    $counting_repository = new CountingResourceTypeRepository($real_repository);
    $this->container->set('jsonapi.resource_type.repository', $counting_repository);

    $this->container->get('router.builder')->rebuild();

    // Each bundle of the shared 'node' entity type must resolve once for
    // the whole rebuild. One call for two views proves the second view
    // used the cache.
    foreach ($counting_repository->callsByBundle as $key => $count) {
      $this->assertSame(1, $count, "$key resolved more than once across the two views.");
    }

    $room = $this->createNode(['type' => 'room', 'status' => 1]);

    // The first view still gives the correct result. It filled the cache.
    $response = $this->request($this->jsonApiRequest('jsonapi_views_test_node_view', 'page_1'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $document = self::decodeResponse($response);
    $this->assertSame('node--room', $document['data'][0]['type']);

    // The second view shares the 'node' entity type. It uses the cached
    // resource types. The result must still show the correct type.
    $response = $this->request($this->jsonApiRequest('jsonapi_views_test_node_view_2', 'page_1'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $document = self::decodeResponse($response);
    $this->assertNotEmpty($document['data']);
    $this->assertSame($room->uuid(), $document['data'][0]['id']);
    $this->assertSame('node--room', $document['data'][0]['type']);
  }

  /**
   * Tests the JsonapiViews display extender's admin options form.
   *
   * BuildOptionsForm() and submitOptionsForm() only act when the form
   * state's "section" matches this extender's plugin ID - Views calls
   * every extender's hooks unconditionally for every settings section, so
   * each extender is responsible for ignoring sections that aren't its own.
   */
  public function testDisplayExtenderOptionsForm(): void {
    $view = Views::getView('jsonapi_views_test_node_view');
    $view->setDisplay('page_1');
    $extenders = $view->getDisplay()->getExtenders();
    $extender = $extenders['jsonapi_views'] ?? NULL;
    $this->assertInstanceOf(JsonapiViews::class, $extender);

    // Enabled by default (JsonapiViews::defineOptions()).
    $this->assertTrue($extender->isExposed());

    // A section that isn't this extender's own: no form element is added.
    $form = [];
    $form_state = new FormState();
    $form_state->set('section', 'other_section');
    $extender->buildOptionsForm($form, $form_state);
    $this->assertArrayNotHasKey('enabled', $form);

    // This extender's own section: the checkbox is added, defaulting to
    // the current (enabled) state.
    $form = [];
    $form_state = new FormState();
    $form_state->set('section', 'jsonapi_views');
    $extender->buildOptionsForm($form, $form_state);
    $this->assertArrayHasKey('enabled', $form);
    $this->assertSame('checkbox', $form['enabled']['#type']);
    $this->assertTrue($form['enabled']['#default_value']);

    // Submitting a non-matching section leaves the option untouched.
    $submitted_form = [];
    $form_state = new FormState();
    $form_state->set('section', 'other_section');
    $form_state->setValue('enabled', FALSE);
    $extender->submitOptionsForm($submitted_form, $form_state);
    $this->assertTrue($extender->isExposed());

    // Submitting this extender's own section stores the new value.
    $submitted_form = [];
    $form_state = new FormState();
    $form_state->set('section', 'jsonapi_views');
    $form_state->setValue('enabled', FALSE);
    $extender->submitOptionsForm($submitted_form, $form_state);
    $this->assertFalse($extender->isExposed());

    // optionsSummary() reflects the option regardless of section.
    $categories = [];
    $options = [];
    $extender->optionsSummary($categories, $options);
    $this->assertSame('JSON:API', (string) $categories['jsonapi_views']['title']);
    $this->assertSame('No', (string) $options['jsonapi_views']['value']);
  }

  /**
   * Tests route building when a bundle machine name is only digits.
   *
   * PHP's array_keys() turns an all-digit bundle ID, like "123", into an
   * int. ResourceTypeRepository::get() asserts that its $bundle argument
   * is a string. Routes::routes() must cast the bundle ID back to a
   * string. See #3503402.
   */
  public function testNumericBundleMachineName(): void {
    NodeType::create(['type' => '123', 'name' => '123'])->save();

    // Routes::routes() loads every bundle of the view's base entity type,
    // not just the bundles the view queries. Rebuilding the router must
    // not throw when the numeric-machine-name bundle exists.
    $this->container->get('router.builder')->rebuild();

    $this->grantPermissionsToTestedRole(['access content']);
    $response = $this->request($this->jsonApiRequest('jsonapi_views_test_node_view', 'page_1'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
  }

  /**
   * Tests exposed filters use their identifier in the preview URL.
   *
   * The test view's "type" filter is exposed with identifier
   * "content_type", which differs from its field name. The "status"
   * filter's identifier matches its field name. The preview URL built by
   * jsonapi_views_views_preview_info_alter() must key each filter's query
   * parameter by its identifier. Otherwise the filter is dropped when the
   * identifier differs from the field name. See #3376193.
   */
  public function testPreviewFilterIdentifier(): void {
    $view = Views::getView('jsonapi_views_test_node_view');
    $view->setDisplay('page_1');
    $view->setExposedInput([
      'content_type' => 'room',
      'status' => '1',
    ]);
    $view->initHandlers();

    $rows = [];
    $renderer = $this->container->get('renderer');
    $renderer->executeInRenderContext(new RenderContext(), static function () use (&$rows, $view): void {
      jsonapi_views_views_preview_info_alter($rows, $view);
    });

    $markup = (string) $rows['query'][0][1]['data']['#markup'];
    $this->assertStringContainsString('views-filter[content_type]=room', $markup);
    $this->assertStringContainsString('views-filter[status]=1', $markup);
    $this->assertStringNotContainsString('views-filter[type]', $markup);
  }

}
