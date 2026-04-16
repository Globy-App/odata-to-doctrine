<?php

namespace App\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class DbLinkContext
{
    /**
     * @var class-string
     */
    public string $entity;

    public string $alias;

    /**
     * @param class-string $entity The repository the root of this overview is based upon, for creating a
     * @param string       $alias  The alias to use when constructing the query builder
     */
    public function __construct(string $entity, string $alias)
    {
        $this->entity = $entity;
        $this->alias = $alias;
    }
}
