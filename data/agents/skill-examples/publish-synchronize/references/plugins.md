# Plugins Reference — Publisher, Trigger & Synchronization

## Publisher Plugin (Write)

Implements `Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherPluginInterface`.

```php
// src/Pyz/Zed/{Module}/Communication/Plugin/Publisher/{Entity}WritePublisherPlugin.php
namespace Pyz\Zed\{Module}\Communication\Plugin\Publisher;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherPluginInterface;

/**
 * @method \Pyz\Zed\{Module}\Business\{Module}FacadeInterface getFacade()
 */
class {Entity}WritePublisherPlugin extends AbstractPlugin implements PublisherPluginInterface
{
    /**
     * {@inheritDoc}
     * - Gets {Entity} IDs from event transfers.
     * - Publishes {entity} data to storage table.
     *
     * @param array<\Generated\Shared\Transfer\EventEntityTransfer> $transfers
     * @param string $eventName
     */
    public function handleBulk(array $transfers, $eventName): void
    {
        $this->getFacade()->writeCollectionBy{Entity}Events($transfers);
    }

    /**
     * @return array<string>
     */
    public function getSubscribedEvents(): array
    {
        return [
            {Module}Config::ENTITY_SPY_{ENTITY}_CREATE,
            {Module}Config::ENTITY_SPY_{ENTITY}_UPDATE,
        ];
    }
}
```

## Publisher Plugin (Delete)

```php
// src/Pyz/Zed/{Module}/Communication/Plugin/Publisher/{Entity}DeletePublisherPlugin.php
/**
 * @method \Pyz\Zed\{Module}\Business\{Module}FacadeInterface getFacade()
 */
class {Entity}DeletePublisherPlugin extends AbstractPlugin implements PublisherPluginInterface
{
    public function handleBulk(array $transfers, $eventName): void
    {
        $this->getFacade()->deleteCollectionBy{Entity}Events($transfers);
    }

    public function getSubscribedEvents(): array
    {
        return [
            {Module}Config::ENTITY_SPY_{ENTITY}_DELETE,
        ];
    }
}
```

## Publisher Trigger Plugin

Implements `Spryker\Zed\PublisherExtension\Dependency\Plugin\PublisherTriggerPluginInterface`.
Required for `vendor/bin/console publish:trigger-events -r {entity}` to work.

```php
// src/Pyz/Zed/{Module}/Communication/Plugin/Publisher/{Entity}PublisherTriggerPlugin.php
/**
 * @method \Pyz\Zed\{Module}\Business\{Module}FacadeInterface getFacade()
 * @method \Pyz\Zed\{Module}\Communication\{Module}CommunicationFactory getFactory()
 */
class {Entity}PublisherTriggerPlugin extends AbstractPlugin implements PublisherTriggerPluginInterface
{
    /**
     * @param int $offset
     * @param int $limit
     * @return array<\Spryker\Shared\Kernel\Transfer\AbstractTransfer>
     */
    public function getData(int $offset, int $limit): array
    {
        $criteriaTransfer = (new {Entity}CriteriaTransfer())
            ->setPagination(
                (new PaginationTransfer())
                    ->setLimit($limit)
                    ->setOffset($offset),
            );

        return $this->getFacade()
            ->get{Entity}Collection($criteriaTransfer)
            ->get{Entities}()
            ->getArrayCopy();
    }

    public function getResourceName(): string
    {
        return {Module}Config::{ENTITY}_RESOURCE_NAME;
    }

    public function getEventName(): string
    {
        return {Module}Config::PUBLISH_{ENTITY}_WRITE;
    }

    public function getIdColumnName(): ?string
    {
        return SpyStoreTableMap::COL_ID_{ENTITY};
    }
}
```

## Synchronization Plugin

Implements `Spryker\Zed\SynchronizationExtension\Dependency\Plugin\SynchronizationDataBulkRepositoryPluginInterface`.

```php
// src/Pyz/Zed/{Module}/Communication/Plugin/Synchronization/{Entity}SynchronizationDataPlugin.php
namespace Pyz\Zed\{Module}\Communication\Plugin\Synchronization;

use Spryker\Zed\Kernel\Communication\AbstractPlugin;
use Spryker\Zed\SynchronizationExtension\Dependency\Plugin\SynchronizationDataBulkRepositoryPluginInterface;

/**
 * @method \Pyz\Zed\{Module}\Business\{Module}FacadeInterface getFacade()
 * @method \Pyz\Zed\{Module}\{Module}Config getConfig()
 */
class {Entity}SynchronizationDataPlugin extends AbstractPlugin implements SynchronizationDataBulkRepositoryPluginInterface
{
    public function getResourceName(): string
    {
        return {Module}Config::{ENTITY}_RESOURCE_NAME;
    }

    /**
     * Return true if entity is store-related.
     * If false — getSynchronizationQueuePoolName() must return a non-null pool name,
     * and schema must have queue_pool parameter set.
     */
    public function hasStore(): bool
    {
        return true;
    }

    /**
     * @param int $offset
     * @param int $limit
     * @param array $ids
     * @return array<\Generated\Shared\Transfer\SynchronizationDataTransfer>
     */
    public function getData(int $offset, int $limit, array $ids = []): array
    {
        return $this->getFacade()->get{Entity}StorageSynchronizationDataTransfers(
            $this->createCriteriaTransfer($offset, $limit, $ids),
        );
    }

    public function getParams(): array
    {
        return [];
    }

    public function getQueueName(): string
    {
        return {Module}Config::{ENTITY}_SYNC_STORAGE_QUEUE;
    }

    /**
     * Return null when hasStore() = true.
     * Return pool name string when hasStore() = false.
     */
    public function getSynchronizationQueuePoolName(): ?string
    {
        return null;
    }

    private function createCriteriaTransfer(int $offset, int $limit, array $ids): {Entity}StorageCriteriaTransfer
    {
        $conditions = new {Entity}StorageConditionsTransfer();
        if ($ids) {
            $conditions->set{Entity}Ids($ids);
        }

        return (new {Entity}StorageCriteriaTransfer())
            ->set{Entity}StorageConditions($conditions)
            ->setPagination(
                (new PaginationTransfer())->setLimit($limit)->setOffset($offset)
            );
    }
}
```

## Facade + Business Layer

```php
// {Module}FacadeInterface.php
interface {Module}FacadeInterface
{
    public function writeCollectionBy{Entity}Events(array $eventTransfers): void;
    public function deleteCollectionBy{Entity}Events(array $eventTransfers): void;
    public function get{Entity}StorageSynchronizationDataTransfers(
        {Entity}StorageCriteriaTransfer $criteriaTransfer
    ): array;
}

// {Module}Facade.php
class {Module}Facade extends AbstractFacade implements {Module}FacadeInterface
{
    public function writeCollectionBy{Entity}Events(array $eventTransfers): void
    {
        $this->getFactory()->create{Entity}Writer()->writeByEvents($eventTransfers);
    }

    public function deleteCollectionBy{Entity}Events(array $eventTransfers): void
    {
        $this->getFactory()->create{Entity}Writer()->deleteByEvents($eventTransfers);
    }

    public function get{Entity}StorageSynchronizationDataTransfers(
        {Entity}StorageCriteriaTransfer $criteriaTransfer
    ): array {
        return $this->getRepository()->get{Entity}StorageSynchronizationDataTransfers($criteriaTransfer);
    }
}
```

## Writer (Business Model)

```php
// src/Pyz/Zed/{Module}/Business/Model/{Entity}Writer.php
class {Entity}Writer
{
    public function __construct(
        private {Module}RepositoryInterface $repository,
        private UtilEncodingServiceInterface $utilEncodingService,
    ) {}

    public function writeByEvents(array $eventTransfers): void
    {
        $ids = array_unique(array_column($eventTransfers, 'id'));
        $entities = $this->repository->find{Entity}ByIds($ids);

        foreach ($entities as $entity) {
            try {
                $storageEntity = $this->repository->findOrCreate{Entity}StorageByFk{Entity}(
                    $entity->getId{Entity}()
                );
                $storageTransfer = (new {Entity}StorageTransfer())->fromArray($entity->toArray(), true);
                $storageEntity->setData(
                    $this->utilEncodingService->encodeJson($storageTransfer->toArray())
                );
                $storageEntity->setKey($this->buildKey($entity));
                $storageEntity->save();
            } catch (\Throwable $e) {
                // log and continue — never throw inside bulk handler
            }
        }
    }

    public function deleteByEvents(array $eventTransfers): void
    {
        $ids = array_unique(array_column($eventTransfers, 'id'));
        $storageEntities = $this->repository->find{Entity}StorageByFk{Entity}Ids($ids);

        foreach ($storageEntities as $storageEntity) {
            $storageEntity->setData(null);
            $storageEntity->save();
            $storageEntity->delete();
        }
    }

    private function buildKey($entity): string
    {
        // Pattern: {resource}:{store}:{locale}:{id}
        return sprintf(
            '%s:%s',
            {Module}Config::{ENTITY}_RESOURCE_NAME,
            $entity->getId{Entity}()
        );
    }
}
```

## Repository

```php
// src/Pyz/Zed/{Module}/Persistence/{Module}Repository.php
class {Module}Repository extends AbstractRepository implements {Module}RepositoryInterface
{
    public function find{Entity}ByIds(array $ids): ObjectCollection
    {
        return $this->getFactory()->create{Entity}Query()->filterByPrimaryKeys($ids)->find();
    }

    public function findOrCreate{Entity}StorageByFk{Entity}(int $fk): Spy{Entity}Storage
    {
        return Spy{Entity}StorageQuery::create()->filterByFk{Entity}($fk)->findOneOrCreate();
    }

    public function find{Entity}StorageByFk{Entity}Ids(array $ids): ObjectCollection
    {
        return Spy{Entity}StorageQuery::create()
            ->filterByFk{Entity}($ids, Criteria::IN)
            ->find();
    }

    public function get{Entity}StorageSynchronizationDataTransfers(
        {Entity}StorageCriteriaTransfer $criteriaTransfer
    ): array {
        $query = Spy{Entity}StorageQuery::create();

        $conditions = $criteriaTransfer->get{Entity}StorageConditions();
        if ($conditions?->get{Entity}Ids()) {
            $query->filterByFk{Entity}_In($conditions->get{Entity}Ids());
        }

        if ($criteriaTransfer->getPagination()) {
            $query = $this->preparePagination($query, $criteriaTransfer->getPagination());
        }

        $synchronizationDataTransfers = [];
        foreach ($query->find() as $storageEntity) {
            $synchronizationDataTransfers[] = (new SynchronizationDataTransfer())
                ->setData($storageEntity->getData())
                ->setKey($storageEntity->getKey());
        }

        return $synchronizationDataTransfers;
    }
}
```
