<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\PropertyConverter;

use Shel\Neos\CrossContentRepositoryReferences\Dto\CrossContentRepositoryReference;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class CrossContentRepositoryReferenceNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @inheritDoc
     * @param array<mixed> $context
     * @phpstan-ignore missingType.iterableValue, missingType.generics
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): mixed
    {
        if ($object instanceof CrossContentRepositoryReference) {
            return $this->normalizer->normalize(
                $object->jsonSerialize(),
                $format,
                $context
            );
        }
        /** @phpstan-ignore return.type */
        return $object;
    }

    public function supportsNormalization(mixed $data, ?string $format = null): bool
    {
        return $data instanceof CrossContentRepositoryReference;
    }
}
