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
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RequestValuerResolver implements ValueResolverInterface
{
    private const CONTEXT_DENORMALIZE = [
        'disable_type_enforcement' => true,
        'collect_denormalization_errors' => true,
    ];

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface&DenormalizerInterface $serializer,
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
            !(
                $reflection->implementsInterface(RequestInterface::class)
                || \count($reflection->getAttributes(RequestAttribute::class)) > 0
                || \count($argument->getAttributes(RequestAttribute::class)) > 0
            )
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
            $violations = new ConstraintViolationList();
            try {
                $instance = $this->serializer->denormalize(
                    $normalData,
                    $argument->getType(),
                    'json' === $format ? 'json' : 'csv',
                    self::CONTEXT_DENORMALIZE
                );
            } catch (PartialDenormalizationException $e) {
                /** @var NotNormalizableValueException $error */
                foreach ($e->getErrors() as $error) {
                    $parameters = [];
                    $template = 'This value was of an unexpected type.';
                    if ($expectedTypes = $error->getExpectedTypes()) {
                        $template = 'This value should be of type {{ type }}.';
                        $parameters['{{ type }}'] = implode('|', $expectedTypes);
                    }
                    if ($error->canUseMessageForUser()) {
                        $parameters['hint'] = $error->getMessage();
                    }

                    if ($error->canUseMessageForUser()) {
                        $message = $error->getMessage();
                    } else {
                        $message = sprintf('The type must be one of "%s" ("%s" given).', implode(', ', $expectedTypes), $error->getCurrentType());
                    }
                    $violations->add(new ConstraintViolation($message, $template, $parameters, null, $error->getPath(), null));
                }
                $instance = $e->getData();
            }
            if (null !== $instance && !\count($violations)) {
                $violations->addAll($this->validator->validate($instance, null, ['Default', $request->getMethod()]));
            }
            $violationsArray = [];
            if ($violations->count()) {
                foreach ($violations as $violation) {
                    $this->putErrorAtPropertyPath($violationsArray, $violation->getPropertyPath(), $violation->getMessage());
                }
            }
            if (\count($violationsArray)) {
                throw new ValidationException($violationsArray);
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
