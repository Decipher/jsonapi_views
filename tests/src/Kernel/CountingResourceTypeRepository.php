<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_views\Kernel;

use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;

/**
 * Wraps a resource type repository and counts calls to get().
 *
 * Test double for JsonapiViewsResourceKernelTest. It counts each call by
 * entity type and bundle. A test can then check that the cache in
 * Routes::routes() works. See #3484714.
 */
final class CountingResourceTypeRepository implements ResourceTypeRepositoryInterface {

  /**
   * The number of get() calls, keyed by "entity_type_id:bundle".
   *
   * @var array<string, int>
   */
  public array $callsByBundle = [];

  public function __construct(
    /**
     * The real repository.
     */
    private readonly ResourceTypeRepositoryInterface $resourceTypeRepository,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function all() {
    return $this->resourceTypeRepository->all();
  }

  /**
   * {@inheritdoc}
   */
  public function get($entity_type_id, $bundle) {
    $key = $entity_type_id . ':' . $bundle;
    $this->callsByBundle[$key] ??= 0;
    $this->callsByBundle[$key]++;
    return $this->resourceTypeRepository->get($entity_type_id, $bundle);
  }

  /**
   * {@inheritdoc}
   */
  public function getByTypeName($type_name) {
    return $this->resourceTypeRepository->getByTypeName($type_name);
  }

}
