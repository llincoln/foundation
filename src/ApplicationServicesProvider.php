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

use Fuel\Config\Container;
use Fuel\Foundation\Request\Http;
use Fuel\Foundation\Request\RequestInterface;
use Fuel\Foundation\Response\ResponseInterface;
use Fuel\Routing\Router;
use League\Container\ServiceProvider\AbstractServiceProvider;

class ApplicationServicesProvider extends AbstractServiceProvider
{

	protected $provides = [
		'fuel.application.event',

		'fuel.application.request',
		'Fuel\Foundation\Request\Cli',
		'Fuel\Foundation\Request\Http',

		'fuel.application.response',
		'Fuel\Foundation\Response\Cli',
		'Fuel\Foundation\Response\Http',

		'fuel.application.finder',

		'fuel.config',

		'fuel.application.component_manager',

		'fuel.application.router',
	];

	/**
	 * {@inheritdoc}
	 */
	public function register()
	{
		$this->getContainer()->add('fuel.application.event', 'League\Event\Emitter', true);

		$this->getContainer()->add('Fuel\Foundation\Request\Cli', 'Fuel\Foundation\Request\Cli', false);
		$this->getContainer()->add('Fuel\Foundation\Request\Http', Http::forge(), false);
		$this->getContainer()->add('fuel.application.request', $this->constructRequest(), true);

		$this->getContainer()->add('Fuel\Foundation\Response\Cli', 'Fuel\Foundation\Response\Cli', false);
		$this->getContainer()->add('Fuel\Foundation\Response\Http', 'Fuel\Foundation\Response\Http', false);
		$this->getContainer()->add('fuel.application.response', $this->constructResponse(), true);

		$this->getContainer()->add('fuel.application.finder', 'Fuel\FileSystem\Finder', true);

		// Also create a config container for our services
		$this->getContainer()->add('fuel.config', new Container(null, $this->getContainer()->get('fuel.application.finder')), true);

		$this->getContainer()->add('fuel.application.component_manager', $this->constructComponentManager(), true);

		$this->getContainer()->add('fuel.application.router', $this->constructRouter(), true);
	}

	/**
	 * @return Router
	 */
	protected function constructRouter() : Router
	{
		$router = new Router;
		$router->setType('string', Router::MATCH_ANY);
		$router->setType('num', Router::MATCH_NUM);
		$router->setType('int', Router::MATCH_NUM);

		return $router;
	}

	protected function constructComponentManager() : ComponentManager
	{
		return new ComponentManager($this->getContainer()->get('fuel.application.finder'));
	}

	/**
	 * @return RequestInterface
	 */
	protected function constructRequest() : RequestInterface
	{
		if ($this->isCli()) {
			return $this->getContainer()->get('Fuel\Foundation\Request\Cli');
		}

		return $this->getContainer()->get('Fuel\Foundation\Request\Http');
	}

	/**
	 * @return ResponseInterface
	 */
	protected function constructResponse() : ResponseInterface
	{
		if ($this->isCli()) {
			return $this->getContainer()->get('Fuel\Foundation\Response\Cli');
		}

		return $this->getContainer()->get('Fuel\Foundation\Response\Http');
	}

	/**
	 * @return bool
	 */
	public function isCli() : bool
	{
		return php_sapi_name() === 'cli';
	}
}
