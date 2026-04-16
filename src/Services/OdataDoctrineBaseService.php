<?php

namespace GlobyApp\OdataToDoctrine\Services;

use GlobyApp\OdataToDoctrine\Attributes\DbLink;
use GlobyApp\OdataToDoctrine\Attributes\DbLinkArrayType;
use GlobyApp\OdataToDoctrine\Attributes\DbLinkContext;
use GlobyApp\OdataToDoctrine\Attributes\DbLinkJoin;
use GlobyApp\OdataToDoctrine\DTO\QueryBuilder\JoinClause;
use GlobyApp\OdataToDoctrine\DTO\QueryBuilder\MapEntry;
use Symfony\Component\Serializer\Attribute\Groups;

class OdataDoctrineBaseService
{
    private const int MAX_RECURSION_DEPTH = 16;

    /**
     * Function to build a map based on the properties and their attributes of a dto.
     *
     * @param class-string $className The object class for which to generate a map
     *
     * @return array<string, MapEntry> An array with the alias to join the class-string with
     *
     * @throws \ReflectionException      if a class in $reflectionClass, or a sub object of $reflectionClass doesn't exist
     * @throws \InvalidArgumentException should not be thrown, this indicates that Attributes are missing required arguments
     * @throws \TypeError                When the object type of ReflectionType is not supported. Only ReflectionNamedType and ReflectionUnionType are supported.
     */
    public function getObjectMap(string $className): array
    {
        $reflectionClass = new \ReflectionClass($className);
        $map = $iteratedObjects = [];
        $sourceTree = $reflectionClass->getName();
        $objectIndex = '';

        $this->iterateReflectionClass($reflectionClass, $sourceTree, $objectIndex, $map, $iteratedObjects, []);

        return $map;
    }

    /**
     * Function to iterate through the properties of a reflection class, recursing into sub objects, if any.
     *
     * @param \ReflectionClass<object> $reflectionClass The reflection class to iterate
     * @param string                   $sourceTree      The current position in the input class and it's subclasses
     * @param string                   $objectIndex     The current position in the object, this key is equal to the key the user should specify when referencing this property
     * @param array<string, MapEntry>  $map             Key: objectIndex, value: details about the entry to be used in the QueryBuilder and wildcard search
     * @param string[]                 $iteratedObjects The objects that are already iterated, to prevent infinite loops
     * @param JoinClause[]             $joins           A list of joins required in the current context
     * @param string|null              $alias           The alias to append to query fields in the current context
     * @param int                      $recursionDepth  A counter keeping track of the current recursion depth
     *
     * @return void Nothing, this method only calls itself and iterateReflectionClass, if a sub object is found
     *
     * @throws \ReflectionException      if a class in $reflectionClass, or a sub object of $reflectionClass doesn't exist
     * @throws \InvalidArgumentException should not be thrown, this indicates that Attributes are missing required arguments
     * @throws \TypeError                When the object type of ReflectionType is not supported. Only ReflectionNamedType and ReflectionUnionType are supported.
     */
    protected function iterateReflectionClass(\ReflectionClass $reflectionClass, string $sourceTree, string $objectIndex, array &$map, array &$iteratedObjects, array $joins, ?string $alias = null, int $recursionDepth = 0): void
    {
        if ($recursionDepth > self::MAX_RECURSION_DEPTH) {
            throw new \LogicException(sprintf('Maximum recursion depth exceeded in class: %s', $reflectionClass->getName()));
        }

        $pathIdentifier = sprintf('%s-%s', $objectIndex, $reflectionClass->getName());
        // Prevent the algorithm from iterating the same object, in the same path twice
        if (in_array($pathIdentifier, $iteratedObjects)) {
            return;
        }

        // Iterate through the properties and sub properties of objects found and add a list of join clauses
        $iteratedObjects[] = $pathIdentifier;

        if ($alias === null) {
            $alias = $this->determineAliasForContext($reflectionClass);
        }

        foreach ($reflectionClass->getProperties() as $property) {
            $type = $property->getType();
            $subSourceTree = sprintf('%s.%s', $sourceTree, $property->getName());
            $subObjectIndex = empty($objectIndex) ? $property->getName() : sprintf('%s.%s', $objectIndex, $property->getName());

            // If no property is defined, we cannot determine whether this is an object to iterate or not,
            // throw a InvalidArgumentException to notify the user that types should always be specified
            if ($type === null) {
                throw new \InvalidArgumentException(sprintf('Every property and sub property in the input object should 
                    have a declared type. Source: %s', $sourceTree));
            }

            $this->iterateTypes($type, $subSourceTree, $subObjectIndex, $map, $property, $iteratedObjects, $joins, $alias, $recursionDepth);
        }
    }

    /**
     * Function to take the DbLinkContext attribute, validate the values and return the alias set.
     *
     * @param \ReflectionClass<object> $reflectionClass The reflection class for which to extract the alias
     *
     * @return string The alias extracted from the DbLinkContext attribute
     */
    protected function determineAliasForContext(\ReflectionClass $reflectionClass): string
    {
        // Determine the alias from the DbLinkContext attribute
        $attributes = $reflectionClass->getAttributes(DbLinkContext::class);

        if (count($attributes) !== 1) {
            throw new \InvalidArgumentException('Input class should contain exactly one DbLinkContext attribute.');
        }

        $arguments = $attributes[0]->getArguments();
        if (count($arguments) !== 2) {
            throw new \InvalidArgumentException('Both parameters for DbLinkContext are required');
        }

        /*
         * ```
         * $arguments[0] = type-string, of the corresponding entity
         * $arguments[1] = string, the alias the entity should receive in the query
         * ```
         */
        return $arguments[1];
    }

    /**
     * Function to determine the object type of the ReflectionType interface and process it accordingly.
     *
     * @param \ReflectionType         $type            The type which to process, based on it's object type
     * @param string                  $sourceTree      The current position in the input class and it's subclasses
     * @param string                  $objectIndex     The current position in the object, this key is equal to the key the user should specify when referencing this property
     * @param array<string, MapEntry> $map             Key: objectIndex, value: details about the entry to be used in the QueryBuilder and wildcard search
     * @param \ReflectionProperty     $property        The property object that has this type. Used for determining the DbLinkArrayTypeClass of an array
     * @param string[]                $iteratedObjects The objects that are already iterated, to prevent infinite loops, only passed through by reference
     * @param JoinClause[]            $joins           A list of joins required in the current context
     * @param string                  $alias           The alias to append to query fields in the current context
     * @param int                     $recursionDepth  A counter keeping track of the current recursion depth
     *
     * @return void Nothing, this method only calls itself and iterateReflectionClass, if a sub object is found
     *
     * @throws \ReflectionException      if the class in $type, or a subobject of $type does not exist
     * @throws \InvalidArgumentException should not be thrown, this indicates that Attributes are missing required arguments
     * @throws \TypeError                When the object type of ReflectionType is not supported. Only ReflectionNamedType and ReflectionUnionType are supported.
     */
    protected function iterateTypes(\ReflectionType $type, string $sourceTree, string $objectIndex, array &$map, \ReflectionProperty $property, array &$iteratedObjects, array $joins, string $alias, int $recursionDepth): void
    {
        $reflectionClass = new \ReflectionClass($property->class);
        $alias = $this->determineAliasForContext($reflectionClass);

        // This is the most basic type, and can be used to determine whether we need to add a join clause
        if ($type instanceof \ReflectionNamedType) {
            // Base case, this is just a single type. Determine whether it's an object, array or scalar
            // As this is still the same property, there is no need to modify the sourceTree
            $this->processSingleType($type->getName(), $sourceTree, $objectIndex, $map, $property, $iteratedObjects, $joins, $alias, $recursionDepth);

            return;
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $val) {
                // These are multiple types of the same property, don't append anything to the sourceTree
                // Don't increase the recursion depth. By design, a ReflectionUnionType cannot contain another ReflectionUnionType, so this statement can only recurse once
                $this->iterateTypes($val, $sourceTree, $objectIndex, $map, $property, $iteratedObjects, $joins, $alias, $recursionDepth);
            }

            return;
        }

        if ($type instanceof \ReflectionIntersectionType) {
            throw new \TypeError(sprintf('Intersection types are not supported. Found: %s', $sourceTree));
        }

        // Other reflection types might be added in the future. The argument type needs to be ReflectionType,
        // as ReflectionUnionType::getTypes returns a list of ReflectionType objects
        throw new \TypeError(sprintf('Unsupported reflection type: %s in %s.', get_class($type), $sourceTree));
    }

    /**
     * Function to process a single type-string. For compatibility with PHP's ReflectionNamedType, which does not
     * guarantee a type-string return, this method determines whether a string is a valid type string.
     * If not, it will just ignore the type.
     *
     * @param string                  $typeName        The type name for which to determine the join clauses, if possible
     * @param string                  $sourceTree      The current position in the input class and it's subclasses
     * @param string                  $objectIndex     The current position in the object, this key is equal to the key the user should specify when referencing this property
     * @param array<string, MapEntry> $map             Key: objectIndex, value: details about the entry to be used in the QueryBuilder and wildcard search
     * @param \ReflectionProperty     $property        The property object that has this type. Used for determining the DbLinkArrayTypeClass of an array
     * @param string[]                $iteratedObjects The objects that are already iterated, to prevent infinite loops, only passed through by reference
     * @param JoinClause[]            $joins           A list of joins required in the current context
     * @param string                  $alias           The alias to append to query fields in the current context
     * @param int                     $recursionDepth  A counter keeping track of the current recursion depth
     *
     * @return void Nothing, this method only calls itself and iterateReflectionClass, if a sub object is found
     *
     * @throws \ReflectionException      if the class specified by $typeName does not exist
     * @throws \InvalidArgumentException should not be thrown, this indicates that Attributes are missing required arguments
     * @throws \TypeError                When the object type of ReflectionType is not supported. Only ReflectionNamedType and ReflectionUnionType are supported.
     */
    protected function processSingleType(string $typeName, string $sourceTree, string $objectIndex, array &$map, \ReflectionProperty $property, array &$iteratedObjects, array $joins, string $alias, int $recursionDepth): void
    {
        try {
            if ($typeName === 'array') {
                // Determine the type of the array by DbLinkArrayType attribute
                foreach ($property->getAttributes(DbLinkArrayType::class) as $arrayType) {
                    $arguments = $arrayType->getArguments();
                    if (count($arguments) !== 1) {
                        throw new \InvalidArgumentException('The reference object in DbLinkArrayType is required');
                    }

                    /*
                     * ```
                     * $arguments[0] = type-string, of the corresponding entity
                     * ```
                     */
                    // Recurse for every type in the array.
                    $this->processSingleType($arguments[0], $sourceTree, $objectIndex, $map, $property, $iteratedObjects, $joins, $alias, $recursionDepth + 1);
                }

                return;
            }

            // Determine the joins required to add this type to the QueryBuilder
            $curJoins = $this->extractJoins($property, $alias);

            // Construct a reflection class to recursively walk through the subclass
            $reflectionClass = null;
            try {
                /* @phpstan-ignore-next-line Type of $typeName is checked by constructing a new ReflectionClass instance and exceptions are caught */
                $reflectionClass = new \ReflectionClass($typeName);
            } catch (\ReflectionException) {
                // Is expected if this is not an object, but a scalar type
                // OR: $typeName is not a class string
            }

            // Determine the new alias, if any join is specified. Otherwise, keep the current alias
            if ($reflectionClass?->getName() === null) {
                // For simple, builtin php types, attribute binding is not supported, use the conventional logic to find the new alias
                $alias = count($curJoins) > 0 ? $curJoins[count($curJoins) - 1]->getAlias() : $alias;
            } else {
                // Find the new alias in this context, where the reflection class can be constructed (custom datatype)
                $alias = count($curJoins) > 0 ? $this->findAlias($curJoins, $reflectionClass->getName(), $sourceTree) : $alias;
            }

            $joins = array_merge($joins, $curJoins);

            // Check whether the class type is a scalar, or internal php type. In that case, don't traverse the object
            if (!class_exists($typeName) || ($reflectionClass !== null && ($reflectionClass->isInternal() || $reflectionClass->isEnum()))) {
                if ($typeName === 'null') {
                    return;
                }

                // This is a primitive php type, an enum, or a non-existent class, add it to the map
                if (!array_key_exists($objectIndex, $map)) {
                    $queryLabel = $this->getQueryLabel($property, $sourceTree);
                    // This property is not coupled to a database property, don't include it in the map
                    if ($queryLabel === null) {
                        return;
                    }

                    // Determine the groups and add a new MapEntry
                    $groups = $this->getGroups($property, $sourceTree);
                    $labelWithAlias = sprintf('%s.%s', $alias, $queryLabel);
                    $map[$objectIndex] = new MapEntry($labelWithAlias, $joins, $groups);
                }

                return;
            }

            // This case should not happen and should be handled by the if statement above
            if ($reflectionClass === null) {
                throw new \LogicException(sprintf('Constructing a reflection class for %s failed', $sourceTree));
            }

            // Call iterateReflectionClass to find all join clauses in the subclass
            $this->iterateReflectionClass($reflectionClass, $sourceTree, $objectIndex, $map, $iteratedObjects, $joins, $alias, $recursionDepth + 1);
        } catch (\ReflectionException $e) {
            // As the php code of the input type is validated before executing this code,
            // we can assume that every type in the input class is valid and thus this error message
            // means that the type we are attempting to construct a ReflectionClass for is a scalar class
            if (!str_ends_with($e->getMessage(), 'does not exist')) {
                throw $e;
            }
        }
    }

    /**
     * Function to extract the doctrine reference to the property in the corresponding entity from the DbLink annotation.
     *
     * @param \ReflectionProperty $property   The property to extract the reference from
     * @param string              $sourceTree The current position in the input object, used in exceptions
     *
     * @return string|null Null if no DbLink attribute is present, the value of the attribute otherwise
     */
    private function getQueryLabel(\ReflectionProperty $property, string $sourceTree): ?string
    {
        $dbLinks = $property->getAttributes(DbLink::class);

        // If no DbLink attribute is attached to the property, disregard it
        if (count($dbLinks) === 0) {
            return null;
        }
        if (count($dbLinks) > 1) {
            throw new \InvalidArgumentException(sprintf('Property can have at most one DbLink attribute: %s.', $sourceTree));
        }

        // Should not happen, as the DbLink attribute is set to have one required parameter
        if (count($dbLinks[0]->getArguments()) !== 1) {
            throw new \InvalidArgumentException(sprintf('DbLink path is required: %s', $sourceTree));
        }

        return $dbLinks[0]->getArguments()[0];
    }

    /**
     * Function to find all symfony serializer groups of a property.
     *
     * @param \ReflectionProperty $property   The property to extract the groups from
     * @param string              $sourceTree The current position in the input object, used in exceptions
     *
     * @return string[] The list of groups, merged from all Groups annotations for the property
     */
    protected function getGroups(\ReflectionProperty $property, string $sourceTree): array
    {
        $groups = $property->getAttributes(Groups::class);

        foreach ($groups as $group) {
            if (count($group->getArguments()) !== 1) {
                throw new \InvalidArgumentException(sprintf('Symfony groups attribute needs to have exactly one argument: %s.', $sourceTree));
            }
        }

        // Extract the array of group names from each attribute instance
        $extractedGroups = array_map(fn (\ReflectionAttribute $attr) => $attr->getArguments()[0], $groups);

        // And merge the group name arrays from both attributes into a single array
        $isArray = null;
        foreach ($extractedGroups as $group) {
            if (is_array($group)) {
                if ($isArray === false) {
                    throw new \LogicException(sprintf('Mixed case between group array and single string constructor detected. This should not be possible: %s', $sourceTree));
                }
                $isArray = true;
                continue;
            }

            $isArray = false;
        }

        if ($isArray === null) {
            return [];
        }
        if ($isArray) {
            return array_merge(...$extractedGroups);
        }

        return $extractedGroups;
    }

    /**
     * Function to convert DbLinkJoin attributes of a property to a list of join clauses.
     *
     * @param \ReflectionProperty $property The property to extract the attributes from
     *
     * @return JoinClause[] A list of join clauses extracted from the property
     */
    protected function extractJoins(\ReflectionProperty $property, string $alias): array
    {
        $res = [];
        foreach ($property->getAttributes(DbLinkJoin::class) as $join) {
            $arguments = $join->getArguments();
            if (count($arguments) < 3) {
                throw new \InvalidArgumentException('The reference object in DbLinkJoin is required to have 4 parameters');
            }

            /**
             * ```
             * $arguments[0] = type-string, of the corresponding entity
             * $arguments[1] = alias of the joined entity
             * $arguments[2] = join conditions to use to join the entity
             * $arguments[3] = datatype this attribute applies to
             * $arguments[4] = whether to prepend the join clause with the current alias
             * ```.
             */
            // If no join clause is specified, don't add the join condition, as this attribute is only meant to indicate an alias
            $useClause = $arguments[2] !== null;
            $prependAlias = $arguments[4] ?? true;

            // Add the join clause to the list of existing join clauses. Append the alias first
            $clause = $prependAlias ? sprintf('%s.%s', $alias, $arguments[2]) : $arguments[2];
            $res = array_merge($res, [new JoinClause($arguments[0], $arguments[1], $clause, $useClause, $arguments[3] ?? null)]);
        }

        return $res;
    }

    /**
     * Function to find the alias attached to a specific class name.
     *
     * @assumes count($joins) > 0, throws an exception otherwise
     *
     * @param JoinClause[] $joins      The list of join clauses to find the alias in
     * @param class-string $className  The class name for which to find the corresponding bound join class, if any
     * @param string       $sourceTree The source tree in the current context
     *
     * @return string The alias belonging to the specified class name
     *
     * @throws \InvalidArgumentException If multiple join clauses are specified, but none match the given class name
     */
    private function findAlias(array $joins, string $className, string $sourceTree): string
    {
        // If only one attribute is specified, return the first one, as there are not multiple attributes to distinguish between
        if (count($joins) === 1) {
            return $joins[0]->getAlias();
        }

        // Check for each join if it is bound to the given class name. If so, return the alias.
        foreach ($joins as $join) {
            if ($join->getReferencedDatatype() === $className) {
                return $join->getAlias();
            }
        }

        // No matches are found, throw an exception
        throw new \InvalidArgumentException("$sourceTree: Referenced class not specified, even though multiple attributes are specified.");
    }
}
