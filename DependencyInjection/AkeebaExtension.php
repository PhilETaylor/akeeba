<?php
/**
 * @author    Phil Taylor <phil@phil-taylor.com>
 * @copyright Copyright (C) 2016, 2017 Blue Flame IT Ltd. All rights reserved.
 * @license   GPL
 * @source    https://github.com/PhilETaylor/akeeba
 */

namespace Akeeba\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This class is automatically discovered by the kernel and load() is called at startup.
 * It gives us a chance to read config/services.xml and make things defined there available for use.
 * For more information, see http://symfony.com/doc/2.0/cookbook/bundles/extension.html
 */
class AkeebaExtension extends Extension
{
	/**
	 * Called by the kernel at load-time.
	 */
	public function load(array $configs, ContainerBuilder $container)
	{
		$loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
		$loader->load('services.yml');
	}
}
