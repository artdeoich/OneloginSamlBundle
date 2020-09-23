<?php

namespace Hslavich\OneloginSamlBundle\DependencyInjection;

use Hslavich\OneloginSamlBundle\Attributes\AttributesMapper;
use Hslavich\OneloginSamlBundle\DependencyInjection\Compiler\SpResolverCompilerPass;
use Hslavich\OneloginSamlBundle\Security\Utils\OneLoginAuthRegistry;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class HslavichOneloginSamlExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $container->setParameter('hslavich_onelogin_saml.settings', $config);
        $container->setParameter('hslavich_onelogin_saml.default_idp_name', $config['default_idp']);
        $this->loadIdentityProviders($config, $container);
        $this->createAttributesMapperDefinition($config['idps'], $container);
    }

    private function loadIdentityProviders(array $config, ContainerBuilder $container)
    {
        $idps = $config['idps'];
        unset($config['idps']);

        $registryDef = $container->getDefinition(OneLoginAuthRegistry::class);

        foreach($idps as $id => $idpConfig) {
            $idpConfig = array_merge(
                $config,
                array('idp' => $idpConfig),
                // Merge custom SP definition of IDP with the template one
                array('sp' => array_merge($config['sp'], isset($idpConfig['sp']) ? $idpConfig['sp'] : array()))
            );

            $idpServiceId = $this->createAuthDefinition(
                $container,
                $id,
                $idpConfig,
                $config['default_idp']
            );

            $registryDef->addMethodCall('addIdpAuth', [$id, new Reference($idpServiceId)]);
            $this->createLogoutDefinition($container, $id, $idpServiceId);
        }
    }

    private function createAttributesMapperDefinition(array $idps, ContainerBuilder $container)
    {
        $idpAttributesMap = [];
        foreach($idps as $id => $idpConfig) {
            if (isset($idpConfig['attributesMap'])) {
                $idpAttributesMap[$id] = $idpConfig['attributesMap'];
            }
        }

        $def = $container->getDefinition(AttributesMapper::class);
        $def->setArgument('$attributesMap', $idpAttributesMap);
    }

    private function createAuthDefinition(ContainerBuilder $container, $id, array $config, $defaultIdp)
    {
        $def = new ChildDefinition('onelogin_auth_abstract');
        $def->setArgument(0, $config);
        $def->addTag(SpResolverCompilerPass::TAG_NAME, [
            'name' => $id,
        ]);

        $serviceId = 'onelogin_auth.'.$id;
        $container->setDefinition($serviceId, $def);

        if ($id === $defaultIdp) {
            $container->setAlias('onelogin_auth', $serviceId);
        }

        return $serviceId;
    }

    private function createLogoutDefinition(ContainerBuilder $container, $id, $authId)
    {
        $namespace = 'hslavich_onelogin_saml.saml_logout';
        $def = new ChildDefinition($namespace);
        $def->setArgument(0, new Reference($authId));

        $serviceId = $namespace . '.' . $id;
        $container->setDefinition($serviceId, $def);

        return $serviceId;
    }
}
