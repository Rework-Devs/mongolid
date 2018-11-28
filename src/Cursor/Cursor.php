<?php
namespace Mongolid\Cursor;

use Iterator;
use LogicException as BaseLogicException;
use MongoDB\Collection;
use MongoDB\Driver\Cursor as DriverCursor;
use MongoDB\Driver\Exception\LogicException;
use MongoDB\Driver\ReadPreference;
use MongoDB\Model\CachingIterator;
use Mongolid\Connection\Connection;
use Mongolid\Container\Container;
use Serializable;

/**
 * This class wraps the query execution and the actual creation of the driver cursor.
 * By doing this we can, call 'sort', 'skip', 'limit' and others after calling
 * 'where'. Because the mongodb library's MongoDB\Cursor is much more
 * limited (in that regard) than the old driver MongoCursor.
 */
class Cursor implements CursorInterface, Serializable
{
    /**
     * @var Collection
     */
    protected $collection;

    /**
     * The command that is being called in the $collection.
     *
     * @var string
     */
    protected $command;

    /**
     * The parameters of the $command.
     *
     * @var array
     */
    protected $params;

    /**
     * The MongoDB cursor used to interact with db.
     *
     * @var DriverCursor
     */
    protected $cursor = null;

    /**
     * Iterator position (to be used with foreach).
     *
     * @var int
     */
    protected $position = 0;

    /**
     * @param Collection $collection the raw collection object that will be used to retrieve the documents
     * @param string     $command    the command that is being called in the $collection
     * @param array      $params     the parameters of the $command
     */
    public function __construct(
        Collection $collection,
        string $command,
        array $params
    ) {
        $this->cursor = null;
        $this->collection = $collection;
        $this->command = $command;
        $this->params = $params;
    }

    /**
     * Limits the number of results returned.
     *
     * @param int $amount the number of results to return
     *
     * @return static
     */
    public function limit(int $amount): CursorInterface
    {
        $this->params[1]['limit'] = $amount;

        return $this;
    }

    /**
     * Sorts the results by given fields.
     *
     * @param array $fields An array of fields by which to sort.
     *                      Each element in the array has as key the field name,
     *                      and as value either 1 for ascending sort, or -1 for descending sort.
     *
     * @return static
     */
    public function sort(array $fields): CursorInterface
    {
        $this->params[1]['sort'] = $fields;

        return $this;
    }

    /**
     * Skips a number of results.
     *
     * @param int $amount the number of results to skip
     *
     * @return static
     */
    public function skip(int $amount): CursorInterface
    {
        $this->params[1]['skip'] = $amount;

        return $this;
    }

    /**
     * Disable idle timeout of 10 minutes from MongoDB cursor.
     * This method should be called before the cursor was started.
     *
     * @param bool $flag toggle timeout on or off
     *
     * @return static
     */
    public function disableTimeout(bool $flag = true)
    {
        $this->params[1]['noCursorTimeout'] = $flag;

        return $this;
    }

    /**
     * This describes how the Cursor route the future read operations to the members of a replica set.
     *
     * @see http://php.net/manual/pt_BR/class.mongodb-driver-readpreference.php
     *
     * @param int $mode preference mode that the Cursor will use
     *
     * @see ReadPreference::class To get a glance of the constants available
     *
     * @return $this
     */
    public function setReadPreference(int $mode)
    {
        $this->params[1]['readPreference'] = new ReadPreference($mode);

        return $this;
    }

    /**
     * Counts the number of results for this cursor.
     *
     * @return int the number of documents returned by this cursor's query
     */
    public function count(): int
    {
        return $this->collection->count(...$this->params);
    }

    /**
     * Iterator interface rewind (used in foreach).
     */
    public function rewind()
    {
        try {
            $this->getCursor()->rewind();
        } catch (LogicException | BaseLogicException $e) {
            $this->fresh();
            $this->getCursor();
        }

        $this->position = 0;
    }

    /**
     * Iterator interface current. Return a model object
     * with cursor document. (used in foreach).
     *
     * @return mixed
     */
    public function current()
    {
        $cursor = $this->getCursor();

        return $cursor->valid() ? $cursor->current() : null;
    }

    /**
     * Returns the first element of the cursor.
     *
     * @return mixed
     */
    public function first()
    {
        $this->rewind();

        return $this->current();
    }

    /**
     * Refresh the cursor in order to be able to perform a rewind and iterate
     * through it again. A new request to the database will be made in the next
     * iteration.
     */
    public function fresh()
    {
        $this->cursor = null;
    }

    /**
     * Iterator key method (used in foreach).
     *
     * @return int
     */
    public function key()
    {
        return $this->position;
    }

    /**
     * Iterator next method (used in foreach).
     */
    public function next()
    {
        ++$this->position;
        $this->getCursor()->next();
    }

    /**
     * Iterator valid method (used in foreach).
     */
    public function valid(): bool
    {
        return $this->getCursor()->valid();
    }

    /**
     * Convert the cursor instance to an array of Objects.
     *
     * @return array
     */
    public function all(): array
    {
        foreach ($this as $document) {
            $result[] = $document;
        }

        return $result ?? [];
    }

    /**
     * Convert the cursor instance to a full associative array.
     *
     * @return array
     */
    public function toArray(): array
    {
        foreach ($this->getCursor() as $document) {
            $result[] = (array) $document;
        }

        return $result ?? [];
    }

    /**
     * Serializes this object storing the collection name instead of the actual
     * MongoDb\Collection (which is unserializable).
     *
     * @return string serialized object
     */
    public function serialize()
    {
        $properties = get_object_vars($this);
        $properties['collection'] = $this->collection->getCollectionName();
        unset($properties['cursor']);

        return serialize($properties);
    }

    /**
     * Unserializes this object. Re-creating the database connection.
     *
     * @param mixed $serialized serialized cursor
     */
    public function unserialize($serialized)
    {
        $attributes = unserialize($serialized);

        $connection = Container::make(Connection::class);
        $db = $connection->defaultDatabase;
        $collectionObject = $connection->getClient()->$db->{$attributes['collection']};

        foreach ($attributes as $key => $value) {
            $this->$key = $value;
        }

        $this->collection = $collectionObject;
    }

    /**
     * Actually returns a Traversable object with the DriverCursor within.
     * If it does not exists yet, create it using the $collection, $command and
     * $params given.
     */
    protected function getCursor(): Iterator
    {
        if (!$this->cursor) {
            $driverCursor = $this->collection->{$this->command}(...$this->params);
            $this->cursor = new CachingIterator($driverCursor);
        }

        return $this->cursor;
    }
}
