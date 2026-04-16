<?php

namespace GlobyApp\OdataToDoctrine\View;

use Symfony\Component\Serializer\Attribute\Groups;

class OdataResponseView
{
    /**
     * @var mixed[] $data
     */
    #[Groups(['odata-response'])]
    public array $data;

    #[Groups(['odata-response'])]
    public MetadataView $metadata;

    /**
     * @param mixed[]      $data     The list of objects to return
     * @param MetadataView $metadata Metadata about pagination of the results
     */
    public function __construct(array $data, MetadataView $metadata)
    {
        $this->data = $data;
        $this->metadata = $metadata;
    }
}
