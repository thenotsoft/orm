<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
declare(strict_types=1);

namespace Spiral\Cycle\Relation;

/**
 * Identical to RelationInterface but defines "left" side of the graph (relation to parent objects).
 */
interface DependencyInterface extends RelationInterface
{

}