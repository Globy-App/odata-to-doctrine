<?php

namespace Globyapp\OdataToDoctrine\Traits;

use Globyapp\OdataToDoctrine\DTO\ODataURLDTO;
use Globyapp\OdataToDoctrine\Exceptions\ValidationViolationException;
use Globyapp\OdataToDoctrine\Services\DTOToDoctrineService;
use Globyapp\OdataToDoctrine\Services\OdataQueryToDoctrineService;
use Doctrine\Persistence\ManagerRegistry;
use GlobyApp\OdataQueryParser\OdataQueryParser;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validator\ValidatorInterface;

trait OdataTrait
{
    /**
     * Function to take a request, extract odata keys from it, validate them, convert them into a doctrine query builder and return the results
     * as a serialized json string.
     *
     * @param Request $request The request to extract the odata query from
     * @param ValidatorInterface $validator The validator to use to validate the odata query and search fields
     * @param ManagerRegistry $registry The registry to perform doctrine actions with
     * @param class-string $referenceClass The class to base the field mappings on
     * @param string $scope The scope for this odata request, used for determining the default search fields and which fields to serialize
     *
     * @return Response The serialized response of the odata query
     *
     * @throws \ReflectionException      if a class in $reflectionClass, or a sub object of $reflectionClass doesn't exist
     * @throws \InvalidArgumentException should not be thrown, this indicates that Attributes are missing required arguments
     * @throws \LogicException           if doctrine cannot instantiate a repository with the class string in the DbLinkContext attribute of $dto
     * @throws \TypeError|ValidationViolationException                When the object type of ReflectionType is not supported. Only ReflectionNamedType and ReflectionUnionType are supported.
     * @throws ValidationViolationException When the OData DTO is invalid.
     * @throws ExceptionInterface On serialization exceptions.
     * @throws \JsonException When the SearchFields array is not a valid json object.
     */
    protected function executeOdataRequest(Request $request, ValidatorInterface $validator, ManagerRegistry $registry, string $referenceClass, string $scope): Response
    {
        $odataDTO = $this->parseOdataUri($request->getUri(), $validator, $request->query->get('search'), $request->query->get('searchFields'));

        // Build an object map of the Order detail DTO and convert it to a doctrine QueryBuilder
        $dtoToDoctrineService = new DTOToDoctrineService();
        $map = $dtoToDoctrineService->getObjectMap($referenceClass);
        $qb = $dtoToDoctrineService->dtoToDoctrine($referenceClass, $registry, $map);

        // Validate the odata query data against the map of the OrderDetailDTO
        $odataQueryToDoctrineService = new OdataQueryToDoctrineService();
        $toValidate = $odataQueryToDoctrineService->getQueryValidator($odataDTO, $map);
        $this->validateObject($validator, $toValidate);

        // Execute the QueryBuilder and extract the metadata from the results
        $responseView = $odataQueryToDoctrineService->filtersToView($qb, $odataDTO, $referenceClass, $scope, $map);

        // Construct a symfony serioalizer and serialize the results to a json object
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([new BackedEnumNormalizer(), new DateTimeNormalizer(), new ObjectNormalizer($classMetadataFactory)], [new JsonEncoder()]);

        return new Response(
            $serializer->serialize(
                $responseView, 'json', ['groups' => ['odata-response', $scope],
                    AbstractObjectNormalizer::SKIP_NULL_VALUES => true]),
            200, ['Content-Type' => 'application/json']
        );
    }

    /**
     * Function to parse an odata uri into a DTO.
     *
     * @param string $requestUri The request URI to parse
     * @param ValidatorInterface $validator The validator to validate the resulting DTO with
     * @param string|null $search The wildcard search query term, if any
     * @param string|null $searchFields A json list of the fields to find the wildcard search query in
     *
     * @return ODataURLDTO The parsed version of the odata URI
     * @throws InvalidArgumentException The URL, or parts of it are malformed and could not be processed (from odata-query-parser).
     * @throws ValidationViolationException When the OData DTO is invalid.
     * @throws \JsonException When the SearchFields array is not a valid json object.
     */
    protected function parseOdataUri(string $requestUri, ValidatorInterface $validator, ?string $search, ?string $searchFields): ODataURLDTO
    {
        $data = OdataQueryParser::parse($requestUri);

        // Use the parsed odata string and create a DTO for validation purposes
        $parsedSearchFields = null === $searchFields ? [] : json_decode($searchFields, true, 10, JSON_THROW_ON_ERROR);

        // Compile and validate the DTO before requesting the filter overview from the order service
        $dto = new ODataURLDTO($data, $search, $parsedSearchFields);
        $this->validateObject($validator, $dto);

        return $dto;
    }

    /**
     * Function to validate a DTO and act accordingly.
     *
     * @param ValidatorInterface $validator The validator to use to validate the DTO
     * @param mixed $DTO The DTO to validate
     *
     * @return void Nothing, if the DTO is invalid, an exception will be thrown
     *
     * @throws ValidationViolationException Thrown if the DTO is invalid
     */
    protected function validateObject(ValidatorInterface $validator, mixed $DTO): void
    {
        $errors = $validator->validate($DTO);
        if (sizeof($errors) > 0) {
            throw new ValidationViolationException($errors);
        }
    }
}
