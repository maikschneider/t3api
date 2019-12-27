<?php
declare(strict_types=1);
namespace SourceBroker\T3api\Service;

use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\FilesystemCache;
use JMS\Serializer\Builder\DefaultDriverFactory;
use JMS\Serializer\Builder\DriverFactoryInterface;
use JMS\Serializer\DeserializationContext;
use JMS\Serializer\EventDispatcher\EventDispatcher;
use JMS\Serializer\EventDispatcher\EventSubscriberInterface;
use JMS\Serializer\Handler\HandlerRegistry;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\Naming\IdenticalPropertyNamingStrategy;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use JMS\Serializer\Naming\SerializedNameAnnotationStrategy;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerBuilder;
use JMS\Serializer\Type\Parser;
use Metadata\Cache\FileCache;
use Metadata\MetadataFactory;
use Metadata\MetadataFactoryInterface;
use RuntimeException;
use SourceBroker\T3api\Domain\Model\AbstractOperation;
use SourceBroker\T3api\Serializer\Accessor\AccessorStrategy;
use SourceBroker\T3api\Serializer\Construction\InitializedObjectConstructor;
use SourceBroker\T3api\Utility\FileUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;

/**
 * Class SerializerService
 */
class SerializerService implements SingletonInterface
{
    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @param ObjectManager $objectManager
     */
    public function injectObjectManager(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @return string
     */
    public static function getSerializerCacheDirectory(): string
    {
        return FileUtility::createWritableDirectory(Environment::getVarPath() . '/cache/code/t3api/jms-serializer');
    }

    /**
     * @return string
     */
    public static function getAnnotationsCacheDirectory(): string
    {
        return FileUtility::createWritableDirectory(self::getSerializerCacheDirectory() . '/annotations');
    }

    /**
     * @return string
     */
    public static function getAutogeneratedMetadataDirectory(): string
    {
        return FileUtility::createWritableDirectory(Environment::getVarPath() . '/cache/code/t3api/jms-metadir');
    }

    /**
     * @return FileCache
     */
    public static function getMetadataCache(): FileCache
    {
        return new FileCache(FileUtility::createWritableDirectory(self::getSerializerCacheDirectory() . '/metadata'));
    }

    /**
     * @param array $params
     */
    public static function clearCache(array $params)
    {
        if (in_array($params['cacheCmd'], ['all', 'system'])) {
            GeneralUtility::flushDirectory(self::getSerializerCacheDirectory(), true, true);
            GeneralUtility::flushDirectory(self::getAutogeneratedMetadataDirectory(), true, true);
        }
    }

    /**
     * @return bool
     */
    public static function isDebugMode(): bool
    {
        return GeneralUtility::getApplicationContext()->isDevelopment();
    }

    /**
     * @param mixed $result
     *
     * @return string
     */
    public function serialize($result)
    {
        return $this->getSerializerBuilder()->build()->serialize($result, 'json');
    }

    /**
     * @param AbstractOperation $operation
     * @param mixed $result
     *
     * @return string
     */
    public function serializeOperation(AbstractOperation $operation, $result)
    {
        return $this->getSerializerBuilder()
            ->setSerializationContextFactory(function () use ($operation) {
                $serializationContext = SerializationContext::create()
                    ->setSerializeNull(true);

                if (!empty($operation->getContextGroups())) {
                    $serializationContext->setGroups($operation->getContextGroups());
                }

                return $serializationContext;
            })
            ->build()
            ->serialize($result, 'json');
    }

    /**
     * @param AbstractOperation $operation
     * @param mixed $data
     * @param mixed $targetObject
     *
     * @return mixed
     */
    public function deserializeOperation(AbstractOperation $operation, $data, $targetObject = null)
    {
        $serializerBuilder = $this->getSerializerBuilder();
        $context = new DeserializationContext();

        if (!empty($targetObject)) {
            $context->setAttribute('target', $targetObject);
        }

        return $serializerBuilder
            ->setDeserializationContextFactory(function () use ($operation) {
                $deserializationContext = DeserializationContext::create();

                if (!empty($operation->getContextGroups())) {
                    $deserializationContext->setGroups($operation->getContextGroups());
                }

                return $deserializationContext;
            })
            ->setObjectConstructor(new InitializedObjectConstructor())
            ->build()
            ->deserialize($data, $operation->getApiResource()->getEntity(), 'json', $context);
    }

    /**
     * @return SerializerBuilder
     */
    public function getSerializerBuilder(): SerializerBuilder
    {
        static $serializerBuilder;

        if (!empty($serializerBuilder)) {
            return clone $serializerBuilder;
        }

        $serializerBuilder = SerializerBuilder::create()
            ->setCacheDir(self::getSerializerCacheDirectory())
            ->setDebug(self::isDebugMode())
            ->configureHandlers(function (HandlerRegistry $registry) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3api']['serializerHandlers'] ?? [] as $handlerClass) {
                    /** @var SubscribingHandlerInterface $handler */
                    $handler = $this->objectManager->get($handlerClass);
                    $registry->registerSubscribingHandler($handler);
                }
            })
            ->configureListeners(function (EventDispatcher $dispatcher) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3api']['serializerSubscribers'] ?? [] as $subscriberClass) {
                    /** @var EventSubscriberInterface $subscriber */
                    $subscriber = $this->objectManager->get($subscriberClass);
                    $dispatcher->addSubscriber($subscriber);
                }
            })
            ->addDefaultHandlers()
            ->setAccessorStrategy($this->objectManager->get(AccessorStrategy::class))
            ->setPropertyNamingStrategy($this->getPropertyNamingStrategy())
            ->setAnnotationReader($this->getAnnotationReader())
            ->setMetadataDriverFactory($this->getDriverFactory())
            ->setMetadataCache(self::getMetadataCache())
            ->addMetadataDirs($this->getMetadataDirs());

        // @todo add signal for serializer customization

        return $serializerBuilder;
    }

    /**
     * @return MetadataFactoryInterface
     */
    public function getMetadataFactory(): MetadataFactoryInterface
    {
        $metadataDriver = $this->getDriverFactory()
            ->createDriver($this->getMetadataDirs(), $this->getAnnotationReader());
        $metadataFactory = new MetadataFactory($metadataDriver, null, self::isDebugMode());
        $metadataFactory->setCache(self::getMetadataCache());

        return $metadataFactory;
    }

    /**
     * @return array
     */
    protected function getMetadataDirs(): array
    {
        return ['' => self::getAutogeneratedMetadataDirectory()];
    }

    /**
     * @throws RuntimeException
     * @return Reader
     */
    protected function getAnnotationReader(): Reader
    {
        try {
            return new CachedReader(
                new AnnotationReader(),
                new FilesystemCache(self::getAnnotationsCacheDirectory()),
                self::isDebugMode()
            );
        } catch (AnnotationException $exception) {
            throw new RuntimeException('Could not create annotation reader for serializer', 1572363525745, $exception);
        }
    }

    /**
     * @return SerializedNameAnnotationStrategy
     */
    protected function getPropertyNamingStrategy(): PropertyNamingStrategyInterface
    {
        return $this->objectManager->get(
            SerializedNameAnnotationStrategy::class,
            $this->objectManager->get(IdenticalPropertyNamingStrategy::class)
        );
    }

    /**
     * @return DriverFactoryInterface
     */
    protected function getDriverFactory(): DriverFactoryInterface
    {
        return new DefaultDriverFactory($this->getPropertyNamingStrategy(), new Parser());
    }
}
