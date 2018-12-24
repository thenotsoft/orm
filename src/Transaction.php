<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
declare(strict_types=1);

namespace Spiral\Cycle;

use Spiral\Cycle\Command\CommandInterface;
use Spiral\Cycle\Exception\TransactionException;
use Spiral\Cycle\Heap\Node;
use Spiral\Cycle\Transaction\Runner;
use Spiral\Cycle\Transaction\RunnerInterface;

/**
 * Transaction provides ability to define set of entities to be stored or deleted within one transaction. Transaction
 * can operate as UnitOfWork. Multiple transactions can co-exists in one application.
 *
 * Internally, upon "run", transaction will request mappers to generate graph of linked commands to create, update or
 * delete entities.
 */
final class Transaction implements TransactionInterface
{
    /** @var ORMInterface */
    private $orm;

    /** @var \SplObjectStorage */
    private $known;

    /** @var array */
    private $persist = [];

    /** @var array */
    private $delete = [];

    /** @var RunnerInterface */
    private $runner;

    /**
     * @param ORMInterface         $orm
     * @param RunnerInterface|null $runner
     */
    public function __construct(ORMInterface $orm, RunnerInterface $runner = null)
    {
        $this->orm = $orm;
        $this->known = new \SplObjectStorage();
        $this->runner = $runner ?? new Runner();
    }

    /**
     * {@inheritdoc}
     */
    public function persist($entity, int $mode = self::MODE_CASCADE)
    {
        if ($this->known->offsetExists($entity)) {
            return;
        }
        $this->known->offsetSet($entity, true);

        $this->persist[] = [$entity, $mode];
    }

    /**
     * {@inheritdoc}
     */
    public function delete($entity, int $mode = self::MODE_CASCADE)
    {
        if ($this->known->offsetExists($entity)) {
            return;
        }
        $this->known->offsetSet($entity, true);

        $this->delete[] = [$entity, $mode];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        try {
            $commands = $this->initCommands();

            while (!empty($commands)) {
                $pending = [];
                $lastExecuted = count($this->runner);

                foreach ($this->sort($commands) as $wait => $do) {
                    if ($wait != null) {
                        if (in_array($wait, $pending, true)) {
                            continue;
                        }

                        $pending[] = $wait;
                        continue;
                    }

                    $this->runner->run($do);
                }

                if (count($this->runner) === $lastExecuted && !empty($pending)) {
                    throw new TransactionException("Unable to complete: " . $this->listCommands($pending));
                }

                $commands = $pending;
            }
        } catch (\Throwable $e) {
            $this->runner->rollback();

            // no calculations must be kept in node states, resetting
            // this will keep entity data as it was before transaction run
            $this->resetHeap();

            throw $e;
        } finally {
            if (!isset($e)) {
                // we are ready to commit all changes to our representation layer
                $this->syncHeap();
            }
        }

        $this->runner->complete();

        // resetting the scope
        $this->persist = $this->delete = [];
        $this->known = new \SplObjectStorage();
    }

    /**
     * Sync all entity states with generated changes.
     */
    protected function syncHeap()
    {
        $heap = $this->orm->getHeap();
        foreach ($heap as $e) {
            $node = $heap->get($e);

            // marked as being deleted and has no external claims (GC like approach)
            if ($node->getStatus() == Node::SCHEDULED_DELETE && !$node->getState()->hasClaims()) {
                $heap->detach($e);
                continue;
            }

            // sync the current entity data with newly generated data
            $this->orm->getMapper($node->getRole())->hydrate($e, $node->syncState());
        }
    }

    /**
     * Reset heap to it's initial state and remove all the changes.
     */
    protected function resetHeap()
    {
        $heap = $this->orm->getHeap();
        foreach ($heap as $e) {
            $heap->get($e)->resetState();
        }
    }

    /**
     * Return flattened list of commands required to store and delete associated entities.
     *
     * @return array
     */
    protected function initCommands(): array
    {
        $commands = [];
        foreach ($this->persist as $pair) {
            $commands[] = $this->orm->queueStore($pair[0], $pair[1]);
        }

        // other commands?

        foreach ($this->delete as $pair) {
            $commands[] = $this->orm->queueDelete($pair[0], $pair[1]);
        }

        return $commands;
    }

    /**
     * Fetch commands which are ready for the execution. Provide ready commands
     * as generated value and delayed commands as the key.
     *
     * @param iterable $commands
     * @return \Generator
     */
    protected function sort(iterable $commands): \Generator
    {
        /** @var CommandInterface $command */
        foreach ($commands as $command) {
            if (!$command->isReady()) {
                // command or command branch is not ready
                yield $command => null;
                continue;
            }

            if ($command instanceof \Traversable) {
                // deepening (cut-off on first not-ready level)
                yield from $this->sort($command);
                continue;
            }

            yield null => $command;
        }
    }

    /**
     * @param array $commands
     * @return string
     */
    private function listCommands(array $commands): string
    {
        $errors = [];
        foreach ($commands as $command) {
            // i miss you Go
            if (method_exists($command, '__toError')) {
                $errors[] = $command->__toError();
            } else {
                $errors[] = get_class($command);
            }
        }

        return join(', ', $errors);
    }
}