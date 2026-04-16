<?php

namespace Globyapp\OdataToDoctrine\DTO\QueryBuilder;

use Globyapp\OdataToDoctrine\DTO\ODataURLDTO;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class QueryMapDTO
{
    #[Assert\NotNull]
    public ?ODataURLDTO $query;

    /**
     * @var array<string, MapEntry>|null
     */
    #[Assert\NotNull]
    public ?array $map;

    /**
     * @param ODataURLDTO|null $query The OdataQuery object to validate
     * @param MapEntry[]|null  $map   The map to validate the OdataQuery with
     */
    public function __construct(?ODataURLDTO $query, ?array $map)
    {
        $this->query = $query;
        $this->map = $map;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->query === null || $this->map === null) {
            // The dedicated NotNull constraint will take care of this case. The callback is always executed last.
            return;
        }

        $validKeys = array_keys($this->map);
        foreach ($this->query->getSelect() as $select) {
            if (!in_array($select, $validKeys)) {
                $context->buildViolation(sprintf('Unknown property in select: %s', $select))
                    ->atPath('select')
                    ->setCode(Assert\Choice::NO_SUCH_CHOICE_ERROR)
                    ->addViolation();
            }
        }

        foreach ($this->query->getOrderBy() as $orderByClause) {
            if (!in_array($orderByClause->getProperty(), $validKeys)) {
                $context->buildViolation(sprintf('Unknown property in order by: %s', $orderByClause->getProperty()))
                    ->atPath('orderBy')
                    ->setCode(Assert\Choice::NO_SUCH_CHOICE_ERROR)
                    ->addViolation();
            }
        }

        foreach ($this->query->getFilter() as $filterClause) {
            if (!in_array($filterClause->getProperty(), $validKeys)) {
                $context->buildViolation(sprintf('Unknown property in filter: %s', $filterClause->getProperty()))
                    ->atPath('filter')
                    ->setCode(Assert\Choice::NO_SUCH_CHOICE_ERROR)
                    ->addViolation();
            }
        }

        // VALIDATION OF ADDED FIELDS IN ODataURLDTO

        // Validate that the fields specified as search fields are valid fields in the context this DTO is used
        foreach ($this->query->searchFields as $field) {
            if (!in_array($field, $validKeys)) {
                $context->buildViolation(sprintf('Unknown property in filter: %s', $field))
                    ->atPath('searchFields')
                    ->setCode(Assert\Choice::NO_SUCH_CHOICE_ERROR)
                    ->addViolation();
            }
        }
    }
}
