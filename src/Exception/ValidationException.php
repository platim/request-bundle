<?php

declare(strict_types=1);

namespace Platim\RequestBundle\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ValidationException extends HttpException
{
    public function __construct(
        private readonly array $errors
    ) {
        parent::__construct(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation error');
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
