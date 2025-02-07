<?php

/********************************************************* {COPYRIGHT-TOP} ***
 * Licensed Materials - Property of IBM
 * 5725-L30, 5725-Z22
 *
 * (C) Copyright IBM Corporation 2018, 2021
 *
 * All Rights Reserved.
 * US Government Users Restricted Rights - Use, duplication or disclosure
 * restricted by GSA ADP Schedule Contract with IBM Corp.
 ********************************************************** {COPYRIGHT-END} **/

namespace Drupal\product\Controller;

use Drupal\apic_api\Api;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\product\Product;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Controller routines for product routes.
 */
class ProductController extends ControllerBase {

  /**
   * Request stack.
   *
   * @var RequestStack
   */
  public $request;

  /**
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;

  /**
   * ProductController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   * @param \Drupal\Core\Messenger\Messenger $messenger
   */
  public function __construct(RequestStack $request, Messenger $messenger) {
    $this->request = $request;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(// Load the service required to construct this class.
      $container->get('request_stack'), $container->get('messenger'));
  }

  /**
   * This method simply redirects to the node/x page, with the node having been loaded via a ParamConverter
   *
   * @param \Drupal\node\NodeInterface $prodNode
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|array
   */
  public function productView(NodeInterface $prodNode) {
    if ($prodNode !== NULL && $prodNode->bundle() === 'product') {
      $viewBuilder = \Drupal::entityTypeManager()->getViewBuilder('node');
      $returnValue = $viewBuilder->view($prodNode, 'full');
    }
    else {
      \Drupal::logger('product')->error('productView: not a valid product.', []);
      $this->messenger->addWarning(t('The specified arguments were not correct.'));
      $url = Url::fromRoute('<front>')->toString();
      $returnValue = new RedirectResponse($url);
    }
    return $returnValue;
  }

  /**
   * @param \Drupal\node\NodeInterface $prodNode
   *
   * @return string
   */
  public function productTitle(NodeInterface $prodNode): string {
    if ($prodNode !== NULL && $prodNode->bundle() === 'product') {
      $returnValue = $prodNode->getTitle() . ' - ' . \Drupal::config('system.site')->get('name');
    }
    else {
      \Drupal::logger('product')->error('productView: not a valid product.', []);
      $this->messenger->addWarning(t('The specified arguments were not correct.'));
      $returnValue = 'ERROR';
    }
    return $returnValue;
  }

  /**
   * This method loads the product telling it which API to embed
   *
   * @param \Drupal\node\NodeInterface $prodNode
   * @param \Drupal\node\NodeInterface $apiNode
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  public function productApi(NodeInterface $prodNode, NodeInterface $apiNode) {
    if ($prodNode !== NULL && $apiNode !== NULL && $prodNode->id() && $apiNode->id()) {
      ibm_apim_entry_trace(__CLASS__ . '::' . __FUNCTION__, [
        'prodNode' => $prodNode->id(),
        'apiNode' => $apiNode->id(),
      ]);
    }
    else {
      ibm_apim_entry_trace(__CLASS__ . '::' . __FUNCTION__, NULL);
    }
    $found = FALSE;
    $config = \Drupal::config('ibm_apim.settings');
    $ibmApimShowVersions = (boolean) $config->get('show_versions');
    if ($ibmApimShowVersions === NULL) {
      $ibmApimShowVersions = TRUE;
    }
    $ibmApimShowPlaceholderImages = (boolean) $config->get('show_placeholder_images');
    if ($ibmApimShowPlaceholderImages === NULL) {
      $ibmApimShowPlaceholderImages = TRUE;
    }
    $api = '';
    $product = '';

    if ($prodNode !== NULL && $prodNode->bundle() === 'product') {
      if ($apiNode !== NULL && $apiNode->bundle() === 'api') {
        // check this product actually includes the specified API
        $prodRefs = [];
        foreach ($prodNode->product_apis->getValue() as $arrayValue) {
          $prodRefs[] = unserialize($arrayValue['value'], ['allowed_classes' => FALSE]);
        }
        foreach ($prodRefs as $prodRef) {
          if ($prodRef['name'] === $apiNode->apic_ref->value) {
            $found = TRUE;
            $product = [
              'nid' => $prodNode->id(),
              'id' => $prodNode->product_id->value,
              'title' => $prodNode->getTitle(),
              'version' => $prodNode->apic_version->value,
              'pathalias' => $prodNode->apic_pathalias->value,
            ];
          }
        }

        $fid = $apiNode->apic_image->getValue();
        $apiImageUrl = NULL;
        if (isset($fid[0]['target_id'])) {
          $file = File::load($fid[0]['target_id']);
          if (isset($file)) {
            $apiImageUrl = $file->createFileUrl();
          }
        }
        elseif ($ibmApimShowPlaceholderImages === TRUE) {
          $rawImage = Api::getRandomImageName($apiNode->getTitle());
          $apiImageUrl = base_path() . drupal_get_path('module', 'apic_api') . '/images/' . $rawImage;
        }
        $enforced = true;
        if (isset($apiNode->api_swagger->value)) {
          $swagger = unserialize($apiNode->api_swagger->value, ['allowed_classes' => FALSE]);
          if (!isset($swagger['x-ibm-configuration']) || $swagger['x-ibm-configuration']['enforced'] === false) {
            $enforced = false;
          }
        }

        $api = [
          'nid' => $apiNode->id(),
          'id' => $apiNode->api_id->value,
          'title' => $apiNode->getTitle(),
          'version' => $apiNode->apic_version->value,
          'pathalias' => $apiNode->apic_pathalias->value,
          'image_url' => $apiImageUrl,
          'enforced' => $enforced,
        ];

        if (!$found) {
          \Drupal::logger('product')->error('productApi: product does not contain the specified API.', []);
          $this->messenger->addWarning(t('The specified arguments were not correct.'));
        }
      }
      else {
        \Drupal::logger('product')->error('productApi: not a valid API.', []);
        $this->messenger->addWarning(t('The specified arguments were not correct.'));
      }
    }
    else {
      \Drupal::logger('product')->error('productApi: not a valid product.', []);
      $this->messenger->addWarning(t('The specified arguments were not correct.'));
    }

    ibm_apim_exit_trace(__CLASS__ . '::' . __FUNCTION__, $found);
    if ($found) {
      $attached = ['library' => []];
      $attached['library'][] = 'product/basic';
      $moduleHandler = \Drupal::service('module_handler');
      if ($moduleHandler->moduleExists('votingapi_widgets')) {
        $attached['library'][] = 'votingapi_widgets/fivestar';
      }

      return [
        '#theme' => 'product_wrapper',
        '#api' => $api,
        '#product' => $product,
        '#attached' => $attached,
        '#showPlaceholders' => $ibmApimShowPlaceholderImages,
        '#showVersions' => $ibmApimShowVersions,
      ];
    }
    else {
      \Drupal::logger('product')
        ->error('productApi: api %api is not in product %product.', [
          '%api' => $apiNode->id(),
          '%product' => $prodNode->id(),
        ]);
      $this->messenger->addWarning(t('The specified arguments were not correct.'));
      $url = Url::fromRoute('<front>')->toString();
      return new RedirectResponse($url);
    }
  }

  /**
   * @param \Drupal\node\NodeInterface|NULL $apiNode
   *
   * @return string
   */
  public function productApiTitle(NodeInterface $apiNode = NULL): string {
    if ($apiNode !== NULL && $apiNode->bundle() === 'api') {
      $returnValue = $apiNode->getTitle() . ' - ' . \Drupal::config('system.site')->get('name');
    }
    else {
      \Drupal::logger('product')->error('productApi: not a valid api.', []);
      $this->messenger->addWarning(t('The specified arguments were not correct.'));
      $returnValue = 'ERROR';
    }
    return $returnValue;
  }

  /**
   * @param \Drupal\node\NodeInterface|NULL $apiNode
   *
   * @return NULL|array
   */
  public function select(NodeInterface $apiNode = NULL): ?array {
    $products = [];

    if ($apiNode !== NULL && $apiNode->id()) {
      ibm_apim_entry_trace(__CLASS__ . '::' . __FUNCTION__, [
        'apiNode' => $apiNode->id(),
      ]);
      $apiNid = $apiNode->id();

      $productNids = Product::getProductsContainingAPI($apiNode->apic_ref->value);
      if (isset($productNids) && !empty($productNids)) {
        $nodes = Node::loadMultiple($productNids);
        // if only one product found then just load it and redirect
        if (count($nodes) === 1) {
          $firstNode = reset($nodes);
          $path = Url::fromRoute('product.api', ['prodNode' => $firstNode->id(), 'apiNode' => $apiNode->id()])
            ->setAbsolute()
            ->toString();
          $response = new RedirectResponse($path);
          $response->send();
          ibm_apim_exit_trace(__CLASS__ . '::' . __FUNCTION__, NULL);
          return NULL;
        }
        else {
          foreach ($nodes as $prodNode) {
            $products[] = [
              'nid' => $prodNode->id(),
              'id' => $prodNode->product_id->value,
              'title' => $prodNode->getTitle(),
              'version' => $prodNode->apic_version->value,
            ];
          }
        }
      }
    }
    else {
      ibm_apim_entry_trace(__CLASS__ . '::' . __FUNCTION__, NULL);
      $apiNid = NULL;
    }

    $attached = ['library' => []];
    $attached['library'][] = 'product/basic';
    ibm_apim_exit_trace(__CLASS__ . '::' . __FUNCTION__, $products);
    return [
      '#theme' => 'product_select',
      '#apiNid' => $apiNid,
      '#products' => $products,
      '#attached' => $attached,
    ];
  }
}
