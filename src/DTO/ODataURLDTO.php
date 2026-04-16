<?php

namespace GlobyApp\OdataToDoctrine\DTO;

use GlobyApp\OdataQueryParser\OdataQuery;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * This class extends the base OdataQuery from: https://github.com/Globy-App/odata-query-parser/blob/master/README.md.
 * It adds searchFields and search for adding wildcard support.
 */
class ODataURLDTO extends OdataQuery
{
    public ?string $search;

    /**
     * @var string[]
     */
    #[Assert\NotNull]
    public array $searchFields;

    /**
     * The parsed version of the input odata query string, including search and searchFields support.
     *
     * @param OdataQuery  $query        The odata query object to base this version with additional fields on
     * @param string|null $search       A wildcard search string, if specified
     * @param string[]    $searchFields A list of fields to find the wildcard $search in
     */
    public function __construct(OdataQuery $query, ?string $search = null, array $searchFields = [])
    {
        parent::__construct($query->getSelect(), $query->getCount(), $query->getTop(), $query->getSkip(), $query->getOrderBy(), $query->getFilter());

        $this->search = $search;
        $this->searchFields = $searchFields;
    }
}
