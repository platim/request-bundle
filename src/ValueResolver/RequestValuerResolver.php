<?php

declare(strict_types=1);

namespace Platim\RequestBundle\ValueResolver;

use Platim\RequestBundle\Attribute\Request as RequestAttribute;
use Platim\RequestBundle\Exception\ValidationException;
use Platim\RequestBundle\Request\RequestInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RequestValuerResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $type = $argument->getType();
        if (!($type && class_exists($type))) {
            return [];
        }

        $reflection = new \ReflectionClass($type);

        if (
            $reflection->implementsInterface(RequestInterface::class)
            || \count($reflection->getAttributes(RequestAttribute::class)) > 0
            || \count($argument->getAttributes(RequestAttribute::class)) > 0
        ) {
            return [];
        }

        $hasBody = \in_array(
            $request->getMethod(),
            [Request::METHOD_POST, Request::METHOD_PUT, Request::METHOD_PATCH],
            true
        );
        $normalData = [];
        $format = $request->getContentTypeFormat();
        if ($hasBody) {
            if ('json' === $format) {
                $normalData = $request->toArray();
            } elseif ('form' === $format) {
                $normalData = $request->request->all();
            }
        } else {
            $normalData = $request->query->all();
        }

        $attributes = $argument->getAttributes(RequestAttribute::class);
        if (0 === \count($attributes)) {
            $reflection = new \ReflectionClass($argument->getType());
            $attributes = $reflection->getAttributes(RequestAttribute::class);
        }
        $formClass = $attributes[0]?->formClass ?? null;
        if (null !== $formClass) {
            $form = $this->formFactory->create($formClass, null, [
                'data_class' => $argument->getType(),
            ]);

            $form->submit($normalData, false);

            if ($form->isSubmitted() && $form->isValid()) {
                yield $form->getData();
            } else {
                $errors = $this->processFormErrors($form);

                throw new ValidationException($errors);
            }
        } else {
            $instance = $this->serializer->denormalize(
                $normalData,
                $argument->getType(),
                'json' === $format ? 'json' : 'csv'
            );
            $violations = [];
            $errors = $this->validator->validate($instance, null, ['Default', $request->getMethod()]);
            if ($errors->count()) {
                foreach ($errors as $error) {
                    $this->putErrorAtPropertyPath($violations, $error->getPropertyPath(), $error->getMessage());
                }
            }
            if (\count($violations)) {
                throw new ValidationException($violations);
            }

            yield $instance;
        }
    }

    private function putErrorAtPropertyPath(array &$violations, string $propertyPath, string $errorMessage): void
    {
        $pointer = &$violations;
        foreach (explode('.', $propertyPath) as $item) {
            $index = null;
            if (preg_match('/(\w+)\[(\d+)]/', $item, $matches)) {
                $item = $matches[1];
                $index = (int) $matches[2];
            }
            if (!isset($pointer[$item])) {
                $pointer[$item] = [];
            }
            if (null !== $index && !isset($pointer[$item][$index])) {
                $pointer[$item][$index] = [];
            }
            $pointer = &$pointer[$item];
            if (null !== $index) {
                $pointer = &$pointer[$index];
            }
        }
        $pointer[] = $errorMessage;
    }

    private function processFormErrors(FormInterface $form): array
    {
        $formName = $form->getName();
        $errors = [];

        foreach ($form->getErrors(true) as $formError) {
            $name = $formError->getOrigin()->getName() === $formName ? [] : [$formError->getOrigin()->getName()];
            $origin = $formError->getOrigin();

            while ($origin = $origin->getParent()) {
                if ($formName !== $origin->getName()) {
                    $name[] = $origin->getName();
                }
            }
            $fieldName = empty($name) ? 'global' : implode('_', array_reverse($name));

            if (!isset($errors[$fieldName])) {
                $errors[$fieldName] = [];
            }
            $errors[$fieldName][] = $formError->getMessage();
        }

        return $errors;
    }
}
