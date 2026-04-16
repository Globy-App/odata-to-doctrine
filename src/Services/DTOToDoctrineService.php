<?php

namespace Globyapp\OdataToDoctrine\Services;

use App\Attributes\DbLinkContext;
use Globyapp\OdataToDoctrine\DTO\DoctrineOdata\JoinClause;
use Globyapp\OdataToDoctrine\DTO\DoctrineOdata\MapEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class DTOToDoctrineService extends OdataDoctrineBaseService
{
    /**
     * Function that takes an object and builds a query builder with joins based on the attributes specified in the $dto class.
     *
     * @param class-string                 $className The object for which to generate a querybuilder and joins
     * @param ManagerRegistry              $registry  The doctrine registry to pass into the repository constructor
     * @param array<string, MapEntry>|null $map       a map containing information about the link to doctrine entities and symfony groups
     *                                                If null, will be generated automatically
     *
     * @return QueryBuilder the resulting QueryBuilder, created with the repository of the entity specified in
     *                      the DbLinkContext attribute of $dto and joins added
     *
     * @throws \ReflectionException      if a class in $reflectionClass, or a sub object of $reflectionClass doesn't exist
     * @throws \InvalidArgumentException should not be thrown, this indicates that Attributes are missing required arguments
     * @throws \LogicException           if doctrine cannot instantiate a repository with the class string in the DbLinkContext attribute of $dto
     * @throws \TypeError                When the object type of ReflectionType is not supported. Only ReflectionNamedType and ReflectionUnionType are supported.
     */
    public function dtoToDoctrine(string $className, ManagerRegistry $registry, ?array $map = null): QueryBuilder
    {
        $map = $map ?? $this->getObjectMap($className);
        $reflectionClass = new \ReflectionClass($className);
        $queryBuilder = $this->getQueryBuilder($reflectionClass, $registry);

        // Find the root entity of the querybuilder, to exclude it from the join list
        $rootEntities = $queryBuilder->getRootEntities();
        $aliases = $queryBuilder->getAllAliases();
        $rootConfiguration = array_combine($aliases, $rootEntities);

        // Determine all join clauses by using the map and merging the result
        $onlyJoins = array_map(fn (MapEntry $entry) => $entry->getRequiredJoins(), $map);

        // Extract the joins from the property they are mapped to, to make a unified list of required joins for the entire map
        $flattenedJoins = call_user_func_array('array_merge', array_values($onlyJoins));

        // Merge the different clauses together, eliminating double joins in the process
        $mergedUniqueJoins = array_unique($flattenedJoins, SORT_REGULAR);

        foreach ($rootConfiguration as $alias => $entity) {
            // Ensure we only remove the root entity, not other instances of the same entity
            if (array_key_exists($alias, $mergedUniqueJoins) && $mergedUniqueJoins[$alias] === $entity) {
                unset($mergedUniqueJoins[$alias]);
            }
        }

        return $this->addJoinClauses($queryBuilder, $mergedUniqueJoins);
    }

    /**
     * Function to determine whether the input class is valid and return a QueryBuilder from the associated class.
     * Checks whether the linked class is an instance of ServiceEntityRepositoryProxy, if so, instantiates the class with
     * $registry and the class string in the DbLinkContext attribute.
     *
     * @param \ReflectionClass<object> $reflectionClass The reflection class wrapped input object
     * @param ManagerRegistry          $registry        The registry to pass into the constructor of the ServiceEntityRepositoryProxy
     *
     * @return QueryBuilder The QueryBuilder received from the associated class
     *
     * @throws \InvalidArgumentException If the annotations of the input class are invalid
     * @throws \LogicException           If doctrine cannot instantiate a repository with the class string in the DbLinkContext attribute
     */
    private function getQueryBuilder(\ReflectionClass $reflectionClass, ManagerRegistry $registry): QueryBuilder
    {
        $attributes = $reflectionClass->getAttributes(DbLinkContext::class);

        if (count($attributes) !== 1) {
            throw new \InvalidArgumentException('Input class should contain exactly one DbLinkContext attribute.');
        }

        $arguments = $attributes[0]->getArguments();
        if (count($arguments) !== 2) {
            throw new \InvalidArgumentException('Both parameters for DbLinkContext are required');
        }

        /**
         * ```
         * $arguments[0] = type-string, of the corresponding entity
         * $arguments[1] = string, the alias the entity should receive in the query
         * ```.
         */
        $repository = new ServiceEntityRepository($registry, $arguments[0]);

        // Doctrine will throw an exception at this point (LogicException) if the class string is not a valid entity
        return $repository->createQueryBuilder($arguments[1]);
    }

    /**
     * Function to add a list of join clauses to a QueryBuilder.
     *
     * @param QueryBuilder $queryBuilder The QueryBuilder to apply the joins to
     * @param JoinClause[] $joinClauses  The list of join clauses to apply
     *
     * @return QueryBuilder The resulting QueryBuilder, with joins applied
     */
    private function addJoinClauses(QueryBuilder $queryBuilder, array $joinClauses): QueryBuilder
    {
        foreach ($joinClauses as $clause) {
            if (!$clause->useClause()) {
                continue;
            }

            $queryBuilder = $queryBuilder->leftJoin($clause->getEntityClass(), $clause->getAlias(), Join::ON, $clause->getClause());
        }

        return $queryBuilder;
    }
}
