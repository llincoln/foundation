<?php
/**
 * @package    Fuel\Foundation
 * @version    2.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2016 Fuel Development Team
 * @link       http://fuelphp.com
 */

declare(strict_types=1);

namespace Fuel\Foundation;

use Fuel\Config\Container as ConfigContainer;
use Fuel\Config\Container;
use Fuel\Dependency\Container as DependencyContainer;
use Fuel\Foundation\Controller\ControllerInterface;
use Fuel\Foundation\Event\AppShutdown;
use Fuel\Foundation\Event\AppStarted;
use Fuel\Foundation\Event\RequestFinished;
use Fuel\Foundation\Event\RequestStarted;
use Fuel\Foundation\Event\ResponseFinished;
use Fuel\Foundation\Event\ResponseStarted;
use Fuel\Foundation\Request\RequestInterface;
use Fuel\Foundation\Response\ResponseInterface;
use Fuel\Routing\Match;
use Fuel\Routing\Router;
use League\Container\ContainerInterface;
use League\Event\Emitter;
use Symfony\Component\BrowserKit\Response;
use Zend\Diactoros\CallbackStream;
use Zend\Diactoros\Stream;

class Application
{
	/**
	 * @var ContainerInterface
	 */
	protected $dependencyContainer;

	/**
	 * @var array
	 */
	protected $config;

	public static function init(array $config) : Application
	{
		// Ensure the needed config entries exists
		$config['events'] = $config['events'] ?? [];
		$config['components'] = $config['components'] ?? [];

		return new static($config);
	}

	public function __construct(array $config, ContainerInterface $dependencyContainer = null)
	{
		$this->initDependencyContainer($config, $dependencyContainer);

		// register any events from the config
		$this->registerEvents($config['events']);

		// Load components
		$this->loadComponents($config['components']);

		$this->registerRoutes();

		// trigger app created event
		$this->dependencyContainer
			->get('fuel.application.event')
			->emit(new AppStarted($this));
	}

	protected function registerRoutes()
	{
		/** @var Router $router */
		$router = $this->dependencyContainer->get('fuel.application.router');

		/** @var Container $config */
		$config = $this->dependencyContainer->get('fuel.config');
		$config->load('routing', 'routing');

		foreach ($config->get('routing', []) as $uri => $routeConfig)
		{
			$router->all($uri)->filters($routeConfig);
		}
	}

	public function setDependencyContainer(ContainerInterface $dependencyContainer)
	{
		$this->dependencyContainer = $dependencyContainer;
	}

	public function getDependencyContainer() : ContainerInterface
	{
		return $this->dependencyContainer;
	}

	/**
	 * @param array $events
	 */
	protected function registerEvents(array $events)
	{
		/** @var Emitter $eventContainer */
		$eventContainer = $this->dependencyContainer->get('fuel.application.event');

		foreach ($events as $event)
		{
			$eventContainer->addListener(
				$event['name'],
				$event['listener'],
				$event['priority'] ?? $eventContainer::P_NORMAL
			);
		}
	}

	/**
	 * @param string[] $components
	 */
	protected function loadComponents(array $components)
	{
		/** @var ComponentManagerInterface $componentManager */
		$componentManager = $this->getDependencyContainer()
			->get('fuel.application.component_manager');

		foreach ($components as $component)
		{
			$componentManager->load($component);
		}
	}

	/**
	 * @param array              $config
	 * @param ContainerInterface $dependencyContainer
	 */
	protected function initDependencyContainer(array $config, ContainerInterface $dependencyContainer = null)
	{
		$this->setDependencyContainer($dependencyContainer ?? new DependencyContainer());
		// So our application can be fetched
		$this->dependencyContainer->add('fuel.application', $this);
		// And our application config if needed
		$this->dependencyContainer->add('fuel.application.config', $config);
		// Finally add all our application level services
		$this->dependencyContainer->addServiceProvider(new ApplicationServicesProvider());
	}

	public function run()
	{
		$request = $this->dependencyContainer->get('fuel.application.request');
		$response = $this->performRequest($request);

		// trigger response started event
		$this->dependencyContainer
			->get('fuel.application.event')
			->emit(new ResponseStarted($this));

		http_response_code($response->getStatusCode());
		echo $response->getBody();

		// send shutdown event
		$this->dependencyContainer
			->get('fuel.application.event')
			->emit(new ResponseFinished($this));

		$this->dependencyContainer
			->get('fuel.application.event')
			->emit(new AppShutdown($this));
	}

	public function performRequest(RequestInterface $request) : ResponseInterface
	{
		$this->dependencyContainer->add('fuel.application.request', $request);

		// trigger request started event
		$this->dependencyContainer
			->get('fuel.application.event')
			->emit(new RequestStarted($this));

		// route to and call controller
		$match = $this->getRouteMatch($request);
		$response = $this->getControllerResult($match);

		// trigger request ended event
		$this->dependencyContainer
			->get('fuel.application.event')
			->emit(new RequestFinished($this));

		return $response;
	}

	/**
	 * @param RequestInterface $request
	 *
	 * @return Match
	 */
	protected function getRouteMatch(RequestInterface $request) : Match
	{
		/** @var Router $router */
		$router = $this->dependencyContainer->get('fuel.application.router');
		$match = $router->translate($request->getUri()->getPath(), $request->getMethod());

		return $match;
	}

	/**
	 * @param Match $match
	 *
	 * @return ResponseInterface
	 */
	protected function getControllerResult(Match $match) : ResponseInterface
	{
		if ($match->controller === null) {
			$response = $this->dependencyContainer->get('fuel.application.response');
			return $response->withStatus(404);
		}

		$controller = $this->createController($match);
		$controllerResult = $controller->{$match->action}();

		// generate and send response
		// If the controller response is a response object then just pass that back out
		if ($controllerResult instanceof ResponseInterface) {
			return $controllerResult;
		}

		// Else update the application response with the controller result#
		/** @var ResponseInterface $response */
		$response = $this->dependencyContainer->get('fuel.application.response');
		$response->withStatus(200);
		return $response->withBody(new CallbackStream(
			function() use ($controllerResult) {
				return $controllerResult;
			}
		));
	}

	/**
	 * @param \Fuel\Routing\Match $match
	 *
	 * @return ControllerInterface
	 */
	protected function createController(Match $match) : ControllerInterface
	{
		// TODO: Use dependency magic to create the controller instance
		/** @var ControllerInterface $controller */
		$controller = new $match->controller();
		$controller->setRouteParams($match->parameters);

		return $controller;
	}

}
