<?php

namespace GlobyApp\OdataToDoctrine\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
/**
 * Multiple instances are allowed. Subclasses use the alias set in the last occurrence of this attribute.
 */
class DbLinkJoin
{
    /**
     * @var class-string
     */
    public string $entity;

    public string $alias;
    public ?string $joinConditions;
    /**
     * @var class-string|null
     */
    public ?string $referencedDatatype;
    public bool $inferAliasFromContext;

    /**
     * @param class-string      $entity                The repository the root of this overview is based upon, for creating a
     * @param string            $alias                 The alias to use when constructing the query builder
     * @param string|null       $joinConditions        The join conditions used when adding the join to the query builder. Don't include the alias for the first (join) field, it will be inferred from DbLinkContext and DbLinkJoin attributes
     * @param class-string|null $referencedDatatype    The DTO or view class this join applies to
     * @param bool              $inferAliasFromContext Whether the prepend the join clause with the alias extracted from the current context
     */
    public function __construct(string $entity, string $alias, ?string $joinConditions, ?string $referencedDatatype = null, bool $inferAliasFromContext = true)
    {
        $this->entity = $entity;
        $this->alias = $alias;
        $this->joinConditions = $joinConditions;
        $this->referencedDatatype = $referencedDatatype;
        $this->inferAliasFromContext = $inferAliasFromContext;
    }
}
