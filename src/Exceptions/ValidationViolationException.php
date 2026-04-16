<?php

namespace GlobyApp\OdataToDoctrine\Exceptions;

use Symfony\Component\Validator\ConstraintViolationListInterface;

class ValidationViolationException extends \Exception
{
    private ConstraintViolationListInterface $violations;

    public function __construct(ConstraintViolationListInterface $violationList) {
        $this->violations = $violationList;

        if ($violationList->count() === 1) {
            $violation = $violationList->get(0);
            parent::__construct($violation->getMessage(), $violation->getCode());
        } else {
            parent::__construct('Multiple constraints have been violated');
        }
    }

    public function getViolations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }
}