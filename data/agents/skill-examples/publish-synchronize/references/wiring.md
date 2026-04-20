# Wiring Reference — DependencyProviders & Queues

## PublisherDependencyProvider

Register publisher plugins in `src/Pyz/Zed/Publisher/PublisherDependencyProvider.php`:

```php
use Pyz\Zed\{Module}\Communication\Plugin\Publisher\{Entity}WritePublisherPlugin;
use Pyz\Zed\{Module}\Communication\Plugin\Publisher\{Entity}DeletePublisherPlugin;
use Pyz\Zed\{Module}\Communication\Plugin\Publisher\{Entity}PublisherTriggerPlugin;

protected function getPublisherPlugins(): array
{
    return [
        // Option A — use the default publish queue:
        new {Entity}WritePublisherPlugin(),
        new {Entity}DeletePublisherPlugin(),

        // Option B — use a dedicated queue for this entity:
        // {Module}Config::PUBLISH_{ENTITY}_QUEUE => [
        //     new {Entity}WritePublisherPlugin(),
        //     new {Entity}DeletePublisherPlugin(),
        // ],
    ];
}

protected function getPublisherTriggerPlugins(): array
{
    return [
        new {Entity}PublisherTriggerPlugin(),
    ];
}
```

## SynchronizationDependencyProvider

Register sync plugin in `src/Pyz/Zed/Synchronization/SynchronizationDependencyProvider.php`:

```php
use Pyz\Zed\{Module}\Communication\Plugin\Synchronization\{Entity}SynchronizationDataPlugin;

protected function getSynchronizationDataPlugins(): array
{
    return [
        new {Entity}SynchronizationDataPlugin(),
    ];
}
```

## QueueDependencyProvider

Register queue message processors in `src/Pyz/Zed/Queue/QueueDependencyProvider.php`:

```php
use Pyz\Shared\{Module}\{Module}Config;
use Spryker\Shared\Event\EventConstants;
use Spryker\Shared\Publisher\PublisherConfig;
use Spryker\Zed\Event\Communication\Plugin\Queue\EventQueueMessageProcessorPlugin;
use Spryker\Zed\Event\Communication\Plugin\Queue\EventRetryQueueMessageProcessorPlugin;
use Spryker\Zed\Synchronization\Communication\Plugin\Queue\SynchronizationStorageQueueMessageProcessorPlugin;
// use Spryker\Zed\Synchronization\Communication\Plugin\Queue\SynchronizationSearchQueueMessageProcessorPlugin;

protected function getProcessorMessagePlugins(Container $container): array
{
    return [
        EventConstants::EVENT_QUEUE            => new EventQueueMessageProcessorPlugin(),
        EventConstants::EVENT_QUEUE_RETRY      => new EventRetryQueueMessageProcessorPlugin(),
        PublisherConfig::PUBLISH_QUEUE         => new EventQueueMessageProcessorPlugin(),
        PublisherConfig::PUBLISH_RETRY_QUEUE   => new EventRetryQueueMessageProcessorPlugin(),

        // Redis sync queue for this entity:
        {Module}Config::{ENTITY}_SYNC_STORAGE_QUEUE => new SynchronizationStorageQueueMessageProcessorPlugin(),

        // Elasticsearch sync queue (if using Search instead):
        // {Module}Config::{ENTITY}_SYNC_SEARCH_QUEUE => new SynchronizationSearchQueueMessageProcessorPlugin(),
    ];
}
```

> Error queues are created automatically as `{queue_name}.error` — no extra registration needed.

## Module DependencyProvider (internal dependencies)

```php
// src/Pyz/Zed/{Module}/{Module}DependencyProvider.php
namespace Pyz\Zed\{Module};

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;

class {Module}DependencyProvider extends AbstractBundleDependencyProvider
{
    public const SERVICE_UTIL_ENCODING = 'SERVICE_UTIL_ENCODING';

    public function provideBusinessLayerDependencies(Container $container): Container
    {
        $container->set(static::SERVICE_UTIL_ENCODING, function (Container $container) {
            return $container->getLocator()->utilEncoding()->service();
        });

        return $container;
    }
}
```
