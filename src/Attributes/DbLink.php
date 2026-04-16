<?php

namespace GlobyApp\OdataToDoctrine\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class DbLink
{
    public string $path;

    /**
     * @param string $path The entity path, compatible with the table join abbreviation and doctrine
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }
}
