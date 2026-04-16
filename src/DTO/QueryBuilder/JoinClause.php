<?php

namespace Globyapp\OdataToDoctrine\DTO\QueryBuilder;

class JoinClause
{
    /**
     * @var class-string
     */
    private string $entityClass;
    private string $alias;
    private string $clause;
    private bool $useClause;
    /**
     * @var class-string|null
     */
    private ?string $referencedDatatype;

    /**
     * @param class-string      $entityClass        The class string of the entity to join
     * @param string            $alias              The alias to use in this join
     * @param string            $clause             The clause to join the entity to an existing QueryBuilder
     * @param bool              $useClause          Whether to use this join clause in the query builder
     * @param class-string|null $referencedDatatype The datatype this attribute applies to
     */
    public function __construct(string $entityClass, string $alias, string $clause, bool $useClause = true, ?string $referencedDatatype = null)
    {
        $this->entityClass = $entityClass;
        $this->alias = $alias;
        $this->clause = $clause;
        $this->useClause = $useClause;
        $this->referencedDatatype = $referencedDatatype;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getClause(): string
    {
        return $this->clause;
    }

    public function useClause(): bool
    {
        return $this->useClause;
    }

    /**
     * @return class-string|null
     */
    public function getReferencedDatatype(): ?string
    {
        return $this->referencedDatatype;
    }
}
