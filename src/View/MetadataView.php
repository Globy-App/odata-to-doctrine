<?php

namespace Globyapp\OdataToDoctrine\View;

use Symfony\Component\Serializer\Attribute\Groups;

class MetadataView
{
    #[Groups(['odata-response'])]
    public int $currentPageResults;

    #[Groups(['odata-response'])]
    public int $totalResultCount;

    #[Groups(['odata-response'])]
    public int $skip;

    #[Groups(['odata-response'])]
    public int $top;

    /**
     * @param int $currentPageResults The amount of results on the current page
     * @param int $totalResultCount   The total amount of results found by the search query
     * @param int $skip               An echo of the requested skip parameter
     * @param int $top                An echo of the requested top parameter
     */
    public function __construct(int $currentPageResults, int $totalResultCount, int $skip, int $top)
    {
        $this->currentPageResults = $currentPageResults;
        $this->totalResultCount = $totalResultCount;
        $this->skip = $skip;
        $this->top = $top;
    }
}
