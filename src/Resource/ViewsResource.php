<?php

declare(strict_types=1);

namespace Drupal\jsonapi_views\Resource;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\CacheableResourceResponse;
use Drupal\jsonapi\JsonApiResource\Link;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\ResourceResponse;
use Drupal\jsonapi_resources\Resource\EntityResourceBase;
use Drupal\jsonapi_views\Plugin\views\display_extender\JsonapiViews;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Processes a request for a collection of featured nodes.
 *
 * @internal
 */
final class ViewsResource extends EntityResourceBase implements ContainerInjectionInterface {

  /**
   * The request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The pager manager.
   *
   * @var \Drupal\Core\Pager\PagerManagerInterface
   */
  protected $pagerManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a ViewsResource object.
   *
   * @param \Drupal\Core\Pager\PagerManagerInterface $pager_manager
   *   The pager manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(PagerManagerInterface $pager_manager, RendererInterface $renderer) {
    $this->pagerManager = $pager_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('pager.manager'),
      $container->get('renderer')
    );
  }

  /**
   * Extracts exposed filter values from the request.
   *
   * @return array
   *   Key value pairs of exposed filters.
   */
  protected function getExposedFilterParams() {
    $all_params = $this->request->query->all();
    $exposed_filter_params = $all_params['views-filter'] ?? [];
    return $exposed_filter_params;
  }

  /**
   * Extracts exposed sort values from the request.
   *
   * @return array
   *   Key value pairs of exposed sorts.
   */
  protected function getExposedSortParams() {
    $all_params = $this->request->query->all();
    $exposed_sort_params = $all_params['views-sort'] ?? [];
    return $exposed_sort_params;
  }

  /**
   * Extracts view argument values from the request.
   *
   * @return array
   *   View arguments.
   */
  protected function getViewArguments() {
    $all_params = $this->request->query->all();
    return $all_params['views-argument'] ?? [];
  }

  /**
   * Get views pager.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   View executable.
   *
   * @return array
   *   Navigation links and total count.
   */
  public function getViewsPager(ViewExecutable $view) : array {
    $pager_links = new LinkCollection([]);

    if (!$view->pager) {
      return [$pager_links, count($view->result)];
    }

    $element = $view->pager->getPagerId();
    $pager = $this->pagerManager->getPager($element);

    if (!$pager) {
      return [$pager_links, count($view->result)];
    }

    $parameters = [];
    $current = $pager->getCurrentPage();
    $total = $pager->getTotalPages();

    // Add 'prev' link.
    if ($current > 0) {
      $options = [
        'query' => $this->pagerManager->getUpdatedParameters($parameters, $element, $current - 1),
      ];
      $prev = Url::fromUri($this->request->getUri(), $options);
      $pager_links = $pager_links->withLink('prev', new Link(new CacheableMetadata(), $prev, 'prev'));
    }

    // Add 'next' link.
    if ($current < ($total - 1)) {
      $options = [
        'query' => $this->pagerManager->getUpdatedParameters($parameters, $element, $current + 1),
      ];
      $next = Url::fromUri($this->request->getUri(), $options);
      $pager_links = $pager_links->withLink('next', new Link(new CacheableMetadata(), $next, 'next'));
    }

    return [$pager_links, $pager->getTotalItems()];
  }

  /**
   * Executes a view display with url parameters.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   An executable view instance.
   * @param string $display_id
   *   A display machine name.
   *
   * @return array
   *   The preview result from the executed view.
   */
  protected function executeView(ViewExecutable &$view, string $display_id) {
    // Get params from request.
    $exposed_filter_params = $this->getExposedFilterParams();
    $exposed_sort_params = $this->getExposedSortParams();
    $exposed_params = \array_merge($exposed_filter_params, $exposed_sort_params);
    $view->setExposedInput($exposed_params);

    return $view->preview($display_id, $this->getViewArguments());
  }

  /**
   * Process the resource request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return \Drupal\jsonapi\ResourceResponse
   *   The response.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function process(Request $request): ResourceResponse {
    $view_id = $request->attributes->get('view');
    assert(is_string($view_id));
    $view = Views::getView($view_id);
    assert($view instanceof ViewExecutable);

    // Set the request.
    $this->request = $request;

    $display_id = $request->attributes->get('display');
    assert(is_string($display_id));

    $view->setDisplay($display_id);
    $extenders = $view->getDisplay()->getExtenders();
    $jsonapi_extender = $extenders['jsonapi_views'] ?? NULL;
    // @todo Check access properly.
    if (!$view->access($display_id) || ($jsonapi_extender instanceof JsonapiViews && !$jsonapi_extender->isExposed())) {
      $response = $this->createJsonapiResponse($this->createCollectionDataFromEntities([]), $this->request, 403, []);
      assert($response instanceof CacheableResourceResponse);
      $response->addCacheableDependency($view->getDisplay()->getCacheMetadata());
      return $response;
    }

    $context = new RenderContext();
    $this->renderer->executeInRenderContext($context, function () use (&$view, $display_id) {
      return $this->executeView($view, $display_id);
    });

    // Collect cacheability from the view's display handler.
    $cacheability = $view->getDisplay()->getCacheMetadata();

    // Merge in any cacheability that bubbled up during rendering.
    if (!$context->isEmpty()) {
      $cacheability = $cacheability->merge(CacheableMetadata::createFromObject($context->pop()));
    }

    $entities = array_map(function (ResultRow $row) {
      return $row->_entity;
    }, $view->result);
    $data = $this->createCollectionDataFromEntities($entities);
    [$pagination_links, $total_count] = $this->getViewsPager($view);

    $response = $this->createJsonapiResponse($data, $this->request, 200, [], $pagination_links, ['count' => $total_count]);
    assert($response instanceof CacheableResourceResponse);
    $cacheability->addCacheContexts([
      'url.query_args:page',
      'url.query_args:views-filter',
      'url.query_args:views-sort',
      'url.query_args:views-argument',
    ]);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
