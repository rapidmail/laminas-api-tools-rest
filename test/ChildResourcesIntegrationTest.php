<?php

declare(strict_types=1);

namespace LaminasTest\ApiTools\Rest;

use Laminas\ApiTools\ApiProblem\View\ApiProblemRenderer;
use Laminas\ApiTools\Hal\Collection;
use Laminas\ApiTools\Hal\Collection as HalCollection;
use Laminas\ApiTools\Hal\Entity;
use Laminas\ApiTools\Hal\Entity as HalEntity;
use Laminas\ApiTools\Hal\Extractor\LinkCollectionExtractor;
use Laminas\ApiTools\Hal\Extractor\LinkExtractor;
use Laminas\ApiTools\Hal\Link\Link;
use Laminas\ApiTools\Hal\Link\LinkUrlBuilder;
use Laminas\ApiTools\Hal\Plugin\Hal as HalHelper;
use Laminas\ApiTools\Hal\View\HalJsonModel;
use Laminas\ApiTools\Hal\View\HalJsonRenderer;
use Laminas\ApiTools\Rest\Resource;
use Laminas\ApiTools\Rest\RestController;
use Laminas\Http\Request;
use Laminas\Mvc\Controller\PluginManager as ControllerPluginManager;
use Laminas\Mvc\Router\Http\TreeRouteStack as V2TreeRouteStack;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\View\Helper\ServerUrl as ServerUrlHelper;
use Laminas\View\Helper\Url as UrlHelper;
use Laminas\View\HelperPluginManager;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Container\ContainerInterface;
use ReflectionObject;

use function call_user_func_array;
use function json_decode;
use function method_exists;

class ChildResourcesIntegrationTest extends TestCase
{
    use ProphecyTrait;
    use RouteMatchFactoryTrait;
    use TreeRouteStackFactoryTrait;

    /** @var object */
    private $parent;

    /** @var object */
    private $child;

    /** @var array */
    private $collection;

    /** @var TreeRouteStack|V2TreeRouteStack */
    private $router;

    /** @var HelperPluginManager */
    private $helpers;

    /** @var HalJsonRenderer */
    private $renderer;

    /** @var ControllerPluginManager */
    private $plugins;

    public function setUp(): void
    {
        $this->setupRouter();
        $this->setupHelpers();
        $this->setupRenderer();
    }

    public function setupHelpers()
    {
        if (! $this->router) {
            $this->setupRouter();
        }

        $services = $this->prophesize(ContainerInterface::class)->reveal();

        $urlHelper = new UrlHelper();
        $urlHelper->setRouter($this->router);

        $serverUrlHelper = new ServerUrlHelper();
        $serverUrlHelper->setScheme('http');
        $serverUrlHelper->setHost('localhost.localdomain');

        $linkUrlBuilder = new LinkUrlBuilder($serverUrlHelper, $urlHelper);

        $linksHelper = new HalHelper();
        $linksHelper->setLinkUrlBuilder($linkUrlBuilder);

        $linkExtractor           = new LinkExtractor($linkUrlBuilder);
        $linkCollectionExtractor = new LinkCollectionExtractor($linkExtractor);
        $linksHelper->setLinkCollectionExtractor($linkCollectionExtractor);

        $this->helpers = $helpers = new HelperPluginManager($services);
        $helpers->setService('url', $urlHelper);
        $helpers->setService('serverUrl', $serverUrlHelper);
        $helpers->setService('hal', $linksHelper);
        if (method_exists($helpers, 'configure')) {
            $helpers->setAlias('Hal', 'hal');
        }

        $this->plugins = $plugins = new ControllerPluginManager($services);
        $plugins->setService('hal', $linksHelper);
        if (method_exists($plugins, 'configure')) {
            $plugins->setAlias('Hal', 'hal');
        }
    }

    public function setupRenderer(): void
    {
        if (! $this->helpers) {
            $this->setupHelpers();
        }
        $this->renderer = $renderer = new HalJsonRenderer(new ApiProblemRenderer());
        $renderer->setHelperPluginManager($this->helpers);
    }

    public function setupRouter(): void
    {
        $routes       = [
            'parent' => [
                'type'          => 'Segment',
                'options'       => [
                    'route'    => '/api/parent[/:parent]',
                    'defaults' => [
                        'controller' => 'Api\ParentController',
                    ],
                ],
                'may_terminate' => true,
                'child_routes'  => [
                    'child' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => '/child[/:child]',
                            'defaults' => [
                                'controller' => 'Api\ChildController',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $this->router = $router = $this->createTreeRouteStack();
        $router->addRoutes($routes);
    }

    public function setUpParentResource(): HalEntity
    {
        $this->parent = (object) [
            'id'   => 'anakin',
            'name' => 'Anakin Skywalker',
        ];
        $resource     = new HalEntity($this->parent, 'anakin');

        $link = new Link('self');
        $link->setRoute('parent');
        $link->setRouteParams(['parent' => 'anakin']);
        $resource->getLinks()->add($link);

        return $resource;
    }

    /** @param int|string $id */
    public function setUpChildResource($id, string $name): HalEntity
    {
        $this->child = (object) [
            'id'   => $id,
            'name' => $name,
        ];
        $resource    = new HalEntity($this->child, $id);

        $link = new Link('self');
        $link->setRoute('parent/child');
        $link->setRouteParams(['child' => $id]);
        $resource->getLinks()->add($link);

        return $resource;
    }

    public function setUpChildCollection(): HalCollection
    {
        $children         = [
            ['luke', 'Luke Skywalker'],
            ['leia', 'Leia Organa'],
        ];
        $this->collection = [];
        foreach ($children as $info) {
            $collection[] = call_user_func_array([$this, 'setUpChildResource'], $info);
        }
        $collection = new HalCollection($this->collection);
        $collection->setCollectionRoute('parent/child');
        $collection->setEntityRoute('parent/child');
        $collection->setPage(1);
        $collection->setPageSize(10);
        $collection->setCollectionName('child');

        $link = new Link('self');
        $link->setRoute('parent/child');
        $collection->getLinks()->add($link);

        return $collection;
    }

    public function setUpAlternateRouter()
    {
        $routes       = [
            'parent' => [
                'type'          => 'Segment',
                'options'       => [
                    'route'    => '/api/parent[/:id]',
                    'defaults' => [
                        'controller' => 'Api\ParentController',
                    ],
                ],
                'may_terminate' => true,
                'child_routes'  => [
                    'child' => [
                        'type'    => 'Segment',
                        'options' => [
                            'route'    => '/child[/:child_id]',
                            'defaults' => [
                                'controller' => 'Api\ChildController',
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $this->router = $router = $this->createTreeRouteStack();
        $router->addRoutes($routes);
        $this->helpers->get('url')->setRouter($router);
    }

    public function testChildResourceObjectIdentifierMappingViaControllerReturn()
    {
        $this->setUpAlternateRouter();

        $resource = new Resource();
        $resource->getEventManager()->attach('fetch', function ($e) {
            return (object) [
                'id'   => 'luke',
                'name' => 'Luke Skywalker',
            ];
        });
        $controller = new RestController();
        $controller->setPluginManager($this->plugins);
        $controller->setResource($resource);
        $controller->setIdentifierName('child_id');
        $r = new ReflectionObject($controller);
        $m = $r->getMethod('getIdentifier');
        $m->setAccessible(true);

        $uri     = 'http://localhost.localdomain/api/parent/anakin/child/luke';
        $request = new Request();
        $request->setUri($uri);
        $matches = $this->router->match($request);
        $this->assertInstanceOf($this->getRouteMatchClass(), $matches);
        $this->assertEquals('anakin', $matches->getParam('id'));
        $this->assertEquals('luke', $matches->getParam('child_id'));
        $this->assertEquals('parent/child', $matches->getMatchedRouteName());

        // Emulate url helper factory and inject route matches
        $this->helpers->get('url')->setRouteMatch($matches);

        // Ensure we matched an identifier!
        $id = $m->invoke($controller, $matches, $request);
        $this->assertEquals('luke', $id);

        $result = $controller->get('luke');
        $this->assertInstanceOf(Entity::class, $result);
        $self   = $result->getLinks()->get('self');
        $params = $self->getRouteParams();
        $this->assertArrayHasKey('child_id', $params);
        $this->assertEquals('luke', $params['child_id']);
    }

    public function testChildResourceObjectIdentifierMappingInCollectionsViaControllerReturn()
    {
        $this->setUpAlternateRouter();

        $resource = new Resource();
        $resource->getEventManager()->attach('fetchAll', function ($e) {
            return [
                (object) [
                    'id'   => 'luke',
                    'name' => 'Luke Skywalker',
                ],
                (object) [
                    'id'   => 'leia',
                    'name' => 'Leia Organa',
                ],
            ];
        });
        $controller = new RestController();
        $controller->setPluginManager($this->plugins);
        $controller->setResource($resource);
        $controller->setRoute('parent/child');
        $controller->setIdentifierName('child_id');
        $controller->setCollectionName('children');
        $r = new ReflectionObject($controller);
        $m = $r->getMethod('getIdentifier');
        $m->setAccessible(true);

        $uri     = 'http://localhost.localdomain/api/parent/anakin/child';
        $request = new Request();
        $request->setUri($uri);
        $matches = $this->router->match($request);
        $this->assertInstanceOf($this->getRouteMatchClass(), $matches);
        $this->assertEquals('anakin', $matches->getParam('id'));
        $this->assertNull($matches->getParam('child_id'));
        $this->assertEquals('parent/child', $matches->getMatchedRouteName());

        // Emulate url helper factory and inject route matches
        $this->helpers->get('url')->setRouteMatch($matches);

        $result = $controller->getList();
        $this->assertInstanceOf(Collection::class, $result);

        // Now, what happens if we render this?
        $model = new HalJsonModel();
        $model->setPayload($result);

        $json = $this->renderer->render($model);
        $test = json_decode($json);
        $this->assertObjectHasProperty('_links', $test);
        $this->assertObjectHasProperty('self', $test->_links);
        $this->assertObjectHasProperty('href', $test->_links->self);
        $this->assertEquals('http://localhost.localdomain/api/parent/anakin/child', $test->_links->self->href);

        $this->assertObjectHasProperty('_embedded', $test);
        $this->assertObjectHasProperty('children', $test->_embedded);
        $this->assertIsArray($test->_embedded->children);

        foreach ($test->_embedded->children as $child) {
            $this->assertObjectHasProperty('_links', $child);
            $this->assertObjectHasProperty('self', $child->_links);
            $this->assertObjectHasProperty('href', $child->_links->self);
            $this->assertMatchesRegularExpression(
                '#^http://localhost.localdomain/api/parent/anakin/child/[^/]+$#',
                $child->_links->self->href
            );
        }
    }
}
