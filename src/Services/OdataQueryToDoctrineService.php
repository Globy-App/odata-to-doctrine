<?php

namespace Globyapp\OdataToDoctrine\Services;

use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use GlobyApp\OdataQueryParser\Datatype\FilterClause;
use GlobyApp\OdataQueryParser\Datatype\OrderByClause;
use GlobyApp\OdataQueryParser\Enum\FilterOperator;
use Globyapp\OdataToDoctrine\DTO\DoctrineOdata\MapEntry;
use Globyapp\OdataToDoctrine\DTO\ODataURLDTO;
use Globyapp\OdataToDoctrine\DTO\QueryBuilder\QueryMapDTO;
use Globyapp\OdataToDoctrine\View\MetadataView;
use Globyapp\OdataToDoctrine\View\OdataResponseView;

class OdataQueryToDoctrineService extends OdataDoctrineBaseService
{
    public const int DEFAULT_SKIP = 0;
    public const int DEFAULT_TOP = 50;

    /**
     * Function to add an odata query object to a QueryBuilder and return it as a Paginator.
     *
     * @param QueryBuilder            $builder    The query builder to add the odata query request to
     * @param ODataURLDTO             $query      The odata request to add to the query builder
     * @param string                  $scopeGroup The group used in the serializer, used to find the default search fields for wildcard search
     * @param array<string, MapEntry> $map        A map of the object being processed, used for finding the correct search fields for wildcard search
     *
     * @return Paginator the paginator built from the query builder provided, with the $query added to it
     *
     * @phpstan-ignore-next-line We cannot really document the type of the Paginator. This code is meant to be generic.
     */
    public function executeOdataQuery(QueryBuilder $builder, ODataURLDTO $query, string $scopeGroup, array $map): Paginator
    {
        $searchFields = count($query->searchFields) === 0 ? $this->determineDefaultSearchFields($map, $scopeGroup) : $query->searchFields;

        $qb = $this->applySelect($builder, $query->getSelect());
        $qb = $this->applyOrderBy($qb, $query->getOrderBy(), $map);
        $qb = $this->applyFilter($qb, $query->getFilter(), $map);
        $qb = $this->applyWilcardSearch($qb, $searchFields, $query->search, $map);
        $qb = $this->applyPaging($qb, $query->getTop(), $query->getSkip());

        return new Paginator($qb);
    }

    /**
     * Function to apply an odata request onto an existing query builder.
     *
     * @assumes all the necessary joins have already been done.
     * @assumes $query to be validated using the QueryMapDTO and a symfony validator. Not validating before might lead to unexpected results and invalid queries.
     *
     * @param QueryBuilder            $builder     The query builder to add the odata query request to
     * @param ODataURLDTO             $query       The odata request to add to the query builder
     * @param class-string            $objectClass The object class to construct with the entity returned by the query builder
     * @param string                  $scopeGroup  The group used in the serializer, used to find the default search fields for wildcard search
     * @param array<string, MapEntry> $map         A map of the object being processed, used for finding the correct search fields for wildcard search
     *
     * @return OdataResponseView The query with filters, sorting and pagina applied
     *
     * @throws \Exception When the iterator could not be fetched from the paginator
     */
    public function filtersToView(QueryBuilder $builder, ODataURLDTO $query, string $objectClass, string $scopeGroup, array $map): OdataResponseView
    {
        $paginator = $this->executeOdataQuery($builder, $query, $scopeGroup, $map);
        $objectResult = array_map(fn ($entity) => new $objectClass($entity), iterator_to_array($paginator->getIterator()));

        // Calculate metadata here
        $metadata = new MetadataView(count($objectResult), $paginator->count(), $query->getSkip() ?? self::DEFAULT_SKIP, $query->getTop() ?? self::DEFAULT_TOP);

        return new OdataResponseView($objectResult, $metadata);
    }

    /**
     * Function to apply the select fields from the odata query to a query builder.
     *
     * @param QueryBuilder $qb     The query builder to apply the select statements to
     * @param string[]     $select The list of fields to select
     *
     * @return QueryBuilder The query builder with the requested fields selected
     *
     * @throws \InvalidArgumentException If any select statements are requested, Globy doesn't support select statements at the moment
     */
    private function applySelect(QueryBuilder $qb, array $select): QueryBuilder
    {
        if (count($select) !== 0) {
            throw new \InvalidArgumentException("Globy doesn't support the \$select odata key.");
        }

        return $qb;
    }

    /**
     * Function to apply the top and skip arguments from the odata query to a query builder.
     *
     * @param QueryBuilder $qb   The query builder to apply paging to
     * @param int|null     $top  Top X entities to select, if specified in the odata query. Otherwise, will default to 50.
     * @param int|null     $skip Skip the first Y entities, if specified in the odata query. Otherwise, will not skip.
     *
     * @return Query The query builder, with skip and top applied, so will be turned into a query object
     *
     * @phpstan-ignore-next-line We cannot really document the type of the Paginator. This code is meant to be generic.
     */
    private function applyPaging(QueryBuilder $qb, ?int $top, ?int $skip): Query
    {
        // Set default values for top and skip
        $skip = $skip ?? self::DEFAULT_SKIP;
        $top = $top ?? self::DEFAULT_TOP;

        return $qb->getQuery()
            ->setFirstResult($skip)
            ->setMaxResults($top);
    }

    /**
     * Function to apply the order by clauses from the odata query to a query builder.
     *
     * @param QueryBuilder            $qb      The query builder to apply the order clauses to
     * @param OrderByClause[]         $orderBy The order by clauses to apply to the query builder
     * @param array<string, MapEntry> $map     The map of the object to find the correlation between the reference object and database entity paths
     *
     * @return QueryBuilder The query builder with order by clauses applied
     */
    private function applyOrderBy(QueryBuilder $qb, array $orderBy, array $map): QueryBuilder
    {
        foreach ($orderBy as $clause) {
            // We cannot be 100% sure the input is validated, even though the applyOdataQuery function has an @assumes
            // tag. In the worst case, using the map to lookup the field name (which comes from the input DTO) leads to
            // an unexpected exception, but prevents injection attacks. No unexpected behavior should occur if the input
            // ODataURLDTO is properly validated.
            $doctrineRef = $map[$clause->getProperty()]->getQueryLabel();

            $qb = $qb->addOrderBy($doctrineRef, $clause->getDirection()->value);
        }

        return $qb;
    }

    /**
     * Function to apply filter clauses from the odata query to a query builder.
     *
     * @param QueryBuilder            $qb     The query builder to apply the filter clauses to
     * @param FilterClause[]          $filter The filter clauses to apply to the query builder
     * @param array<string, MapEntry> $map    The map of the object to find the correlation between the reference object and database entity paths
     */
    private function applyFilter(QueryBuilder $qb, array $filter, array $map): QueryBuilder
    {
        for ($i = 0; $i < count($filter); ++$i) {
            $clause = $filter[$i];
            $doctrineRef = $map[$clause->getProperty()]->getQueryLabel();

            if ($clause->getOperator() === FilterOperator::IN) {
                if (!is_array($clause->getValue())) {
                    throw new \LogicException(sprintf('The filter value for the in operator should always be an array. Path: %s', $doctrineRef));
                }

                $qb = $qb->andWhere($this->buildExpression($clause, $qb, $doctrineRef, $i, $clause->getValue()));
            } else {
                $qb = $qb->andWhere($this->buildExpression($clause, $qb, $doctrineRef, $i));

                // Immediately add a setParameter clause to bind the parameter created in buildExpression()
                $qb = $qb->setParameter("bind$i", $clause->getValue());
            }
        }

        return $qb;
    }

    /**
     * Function to add an odata filter to a QueryBuilder.
     *
     * @param FilterClause                      $filter   The filter to add
     * @param QueryBuilder                      $qb       The query builder to add to
     * @param int                               $id       The id of this filter, to bind a value to
     * @param array<int|float|string|bool|null> $inValues A list of values if the operator is in, the values are bound upon creation of the expression
     *
     * @return Query\Expr\Comparison|Query\Expr\Func A doctrine comparison operator or function (->in()) to add to the where clause
     */
    private function buildExpression(FilterClause $filter, QueryBuilder $qb, string $doctrineRef, int $id, ?array $inValues = null): Query\Expr\Comparison|Query\Expr\Func
    {
        return match ($filter->getOperator()) {
            FilterOperator::EQUALS => $qb->expr()->eq($doctrineRef, ":bind$id"),
            FilterOperator::NOT_EQUALS => $qb->expr()->neq($doctrineRef, ":bind$id"),
            FilterOperator::GREATER_THAN => $qb->expr()->gt($doctrineRef, ":bind$id"),
            FilterOperator::GREATER_THAN_EQUALS => $qb->expr()->gte($doctrineRef, ":bind$id"),
            FilterOperator::LESS_THAN => $qb->expr()->lt($doctrineRef, ":bind$id"),
            FilterOperator::LESS_THAN_EQUALS => $qb->expr()->lte($doctrineRef, ":bind$id"),
            FilterOperator::IN => $qb->expr()->in($doctrineRef, $inValues ?? []),
        };
    }

    /**
     * Function to apply wildcard search to the query, if specified and if there are fields specified to search in.
     *
     * @param QueryBuilder            $qb           The query builder to apply wildcard search to
     * @param string[]                $searchFields The list of fields to find the search term in
     * @param string|null             $searchTerm   The search term to find in $searchFields
     * @param array<string, MapEntry> $map          The map of the object to find the correlation between the reference object and database entity paths
     *
     * @return QueryBuilder The query builder with wildcard search added
     */
    private function applyWilcardSearch(QueryBuilder $qb, array $searchFields, ?string $searchTerm, array $map): QueryBuilder
    {
        if ($searchTerm === null || count($searchFields) === 0) {
            return $qb;
        }

        $wildcards = $qb->expr()->orX();

        for ($i = 0; $i < count($searchFields); ++$i) {
            $doctrineRef = $map[$searchFields[$i]]->getQueryLabel();

            $wildcards->add($qb->expr()->like("LOWER(CONCAT('', $doctrineRef))", "LOWER(:bindw$i)"));
            $qb = $qb->setParameter("bindw$i", "%$searchTerm%");
        }

        return $qb->andWhere($wildcards);
    }

    /**
     * Returns a DTO object with symfony validator constraints to ensure that the query object is valid.
     *
     * @param ODataURLDTO             $query The OdataQuery object to validate
     * @param array<string, MapEntry> $map   The object map to validate the OdataQuery property paths with
     *
     * @return QueryMapDTO a QueryMap DTO, to be validated with the symfony validator
     */
    public function getQueryValidator(ODataURLDTO $query, array $map): QueryMapDTO
    {
        return new QueryMapDTO($query, $map);
    }

    /**
     * Function to return the property path of all fields that have a specific group in their list of groups.
     *
     * @param array<string, MapEntry> $map   The map to base the search on
     * @param string                  $group The group to return property paths for
     *
     * @return string[] A list of property paths that have $group in their list of groups
     */
    private function determineDefaultSearchFields(array $map, string $group): array
    {
        // Filter the keys that have the $group in their list of groups
        $applicableFields = array_filter($map, fn (MapEntry $entry) => in_array($group, $entry->getGroups()));

        // Return only the property paths of the applicable entries
        return array_keys($applicableFields);
    }
}
