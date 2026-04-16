<?php

namespace App\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class DbLinkArrayType
{
    /**
     * @var class-string
     */
    public string $object;

    /**
     * Documents the type of object in this array. Will throw an exception if not specified for an array to prevent inconsistencies.
     * Specify one object type string. If this is a union typed array, repeat this attribute for all types in the union.
     *
     * @param class-string $object The type of object the array consists of
     */
    public function __construct(string $object)
    {
        $this->object = $object;
    }
}
