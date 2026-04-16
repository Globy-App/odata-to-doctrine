<?php

namespace Globyapp\OdataToDoctrine\DTO\QueryBuilder;

class MapEntry
{
    private string $queryLabel;
    /**
     * @var JoinClause[]
     */
    private array $requiredJoins;
    /**
     * @var string[]
     */
    private array $groups;

    /**
     * @param string       $queryLabel    The alias and field name concatenated, to reference this field in a query builder
     * @param JoinClause[] $requiredJoins The joins required to reference this field from a query builder
     * @param string[]     $groups
     */
    public function __construct(string $queryLabel, array $requiredJoins, array $groups)
    {
        $this->queryLabel = $queryLabel;
        $this->requiredJoins = $requiredJoins;
        $this->groups = $groups;
    }

    public function getQueryLabel(): string
    {
        return $this->queryLabel;
    }

    /**
     * @return JoinClause[]
     */
    public function getRequiredJoins(): array
    {
        return $this->requiredJoins;
    }

    /**
     * @return string[]
     */
    public function getGroups(): array
    {
        return $this->groups;
    }
}
