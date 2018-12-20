<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
declare(strict_types=1);

namespace Spiral\Cycle\Relation\Morphed;

use Spiral\Cycle\Command\CommandInterface;
use Spiral\Cycle\Command\ContextCarrierInterface as CC;
use Spiral\Cycle\Heap\Node;
use Spiral\Cycle\Mapper\ProxyFactoryInterface;
use Spiral\Cycle\ORMInterface;
use Spiral\Cycle\Promise\PromiseOne;
use Spiral\Cycle\Relation;
use Spiral\Cycle\Relation\BelongsToRelation;

class BelongsToMorphedRelation extends BelongsToRelation
{
    /** @var mixed|null */
    private $morphKey;

    /**
     * @param ORMInterface $orm
     * @param string       $target
     * @param string       $name
     * @param array        $schema
     */
    public function __construct(ORMInterface $orm, string $name, string $target, array $schema)
    {
        parent::__construct($orm, $name, $target, $schema);
        $this->morphKey = $schema[Relation::MORPH_KEY] ?? null;
    }

    /**
     * @inheritdoc
     */
    public function initPromise(Node $parentNode): array
    {
        if (empty($innerKey = $this->fetchKey($parentNode, $this->innerKey))) {
            return [null, null];
        }

        $target = $this->fetchKey($parentNode, $this->morphKey);
        $query = [$this->outerKey => $innerKey];

        if (!empty($e = $this->orm->get($target, $query, false))) {
            return [$e, $e];
        }

        $p = new PromiseOne($this->orm, $target, $query);

        $m = $this->getSource($target);
        if ($m instanceof ProxyFactoryInterface) {
            $p = $m->makeProxy($p);
        }

        return [$p, $p];
    }

    /**
     * @inheritdoc
     */
    public function queue(CC $parentStore, $parentEntity, Node $parentNode, $related, $original): CommandInterface
    {
        $wrappedStore = parent::queue($parentStore, $parentEntity, $parentNode, $related, $original);

        if (is_null($related)) {
            if ($this->fetchKey($parentNode, $this->morphKey) !== null) {
                $parentStore->register($this->morphKey, null, true);
                $parentNode->register($this->morphKey, null, true);
            }
        } else {
            $relState = $this->getNode($related);
            if ($this->fetchKey($parentNode, $this->morphKey) != $relState->getRole()) {
                $parentStore->register($this->morphKey, $relState->getRole(), true);
                $parentNode->register($this->morphKey, $relState->getRole(), true);
            }
        }

        return $wrappedStore;
    }
}