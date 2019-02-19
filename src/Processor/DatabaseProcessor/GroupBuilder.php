<?php
/**
 * This file is part of Hydrogen package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace RDS\Hydrogen\Processor\DatabaseProcessor;

use Doctrine\ORM\Query\Expr\Composite;
use Doctrine\ORM\QueryBuilder;
use RDS\Hydrogen\Criteria\Where;
use Doctrine\ORM\Query\Expr\Andx;
use RDS\Hydrogen\Criteria\Criterion;
use RDS\Hydrogen\Criteria\WhereGroup;
use RDS\Hydrogen\Criteria\CriterionInterface;
use RDS\Hydrogen\Processor\DatabaseProcessor\Common\Expression;

/**
 * Class GroupBuilder
 */
class GroupBuilder extends Builder
{
    /**
     * @var string[]|Criterion[]
     */
    protected const ALLOWED_INNER_TYPES = [
        Where::class      => 'applyWhere',
        WhereGroup::class => 'applyGroup',
    ];

    /**
     * @var int
     */
    private $nestedLevel = 0;

    /**
     * @param QueryBuilder $builder
     * @param CriterionInterface|WhereGroup $group
     * @return iterable|null
     */
    public function apply($builder, CriterionInterface $group): ?iterable
    {
        ++$this->nestedLevel;
        $expression = $group->isAnd() ? $builder->expr()->andX() : $builder->expr()->orX();

        foreach ($this->getInnerSelections($group) as $criterion => $fn) {
            yield from $fn($builder, $expression, $criterion);
        }

        --$this->nestedLevel;
        if ($this->nestedLevel === 0) {
            if ($group->isAnd()) {
                $builder->andWhere($expression);
            } else {
                $builder->orWhere($expression);
            }
        }

        return $expression->getParts();
    }

    /**
     * @param WhereGroup $group
     * @return iterable|callable[]
     */
    protected function getInnerSelections(WhereGroup $group): iterable
    {
        $query = $group->getQuery();

        foreach ($query->getCriteria() as $criterion) {
            foreach (static::ALLOWED_INNER_TYPES as $typeOf => $fn) {
                if ($criterion instanceof $typeOf) {
                    yield $criterion => [$this, $fn];
                    continue 2;
                }
            }

            $error = 'Groups not allowed for %s criterion';
            throw new \LogicException(\sprintf($error, \get_class($criterion)));
        }
    }

    /**
     * @param QueryBuilder $builder
     * @param Andx $context
     * @param WhereGroup $group
     * @return \Generator
     */
    protected function applyGroup(QueryBuilder $builder, Composite $context, WhereGroup $group): \Generator
    {
        $expression = $group->isAnd() ? $builder->expr()->andX() : $builder->expr()->orX();

        yield from $parts = $this->apply($builder, $group);

        foreach ($parts->getReturn() as $part) {
            $expression->add($part);
        }

        $context->add($expression);
    }

    /**
     * @param QueryBuilder $builder
     * @param Andx $context
     * @param Where $where
     * @return \Generator
     */
    protected function applyWhere(QueryBuilder $builder, Composite $context, Where $where): \Generator
    {
        $expression = new Expression($builder, $where->getOperator(), $where->getValue());
        yield from $result = $expression->create($where->getField());

        $context->add($result->getReturn());
    }
}

