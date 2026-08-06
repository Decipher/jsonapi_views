<?php

declare(strict_types=1);

namespace Drupal\Tests\jsonapi_views\Kernel;

use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;

/**
 * Wraps a resource type repository. Hides one bundle.
 *
 * Test double for JsonapiViewsResourceKernelTest. get() returns NULL for
 * one chosen entity type and bundle. Every other call goes to the real
 * repository. This copies a bundle with no JSON:API resource type. For
 * example, jsonapi_extras can disable a bundle this way. See #3265781.
 */
final readonly class NullingResourceTypeRepository implements ResourceTypeRepositoryInterface {

  public function __construct(
    /**
     * The real repository.
     */
    private ResourceTypeRepositoryInterface $resourceTypeRepository,
    /**
     * The entity type ID to hide.
     */
    private string $hiddenEntityTypeId,
    /**
     * The bundle to hide.
     */
    private string $hiddenBundle,
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
    if ($entity_type_id === $this->hiddenEntityTypeId && $bundle === $this->hiddenBundle) {
      return NULL;
    }
    return $this->resourceTypeRepository->get($entity_type_id, $bundle);
  }

  /**
   * {@inheritdoc}
   */
  public function getByTypeName($type_name) {
    return $this->resourceTypeRepository->getByTypeName($type_name);
  }

}
