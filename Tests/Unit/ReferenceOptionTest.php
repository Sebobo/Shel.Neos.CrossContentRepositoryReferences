<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Tests\Unit;

use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shel\Neos\CrossContentRepositoryReferences\Dto\CrossContentRepositoryReference;
use Shel\Neos\CrossContentRepositoryReferences\Dto\ReferenceOption;

class ReferenceOptionTest extends TestCase
{
    private ReferenceOption $option;

    protected function setUp(): void
    {
        $reference = new CrossContentRepositoryReference(
            ContentRepositoryId::fromString('default'),
            NodeAggregateId::fromString('abc-123'),
        );
        $this->option = new ReferenceOption(
            'My Title',
            $reference,
            NodeTypeName::fromString('Neos.Neos:Document'),
        );
    }

    #[Test]
    public function constructorStoresProperties(): void
    {
        self::assertSame('My Title', $this->option->label);
        self::assertSame('default', $this->option->value->contentRepositoryId->value);
        self::assertSame('abc-123', $this->option->value->nodeAggregateId->value);
        self::assertSame('Neos.Neos:Document', $this->option->nodeType->value);
    }

    #[Test]
    public function jsonSerializeReturnsExpectedStructure(): void
    {
        $serialized = $this->option->jsonSerialize();

        self::assertSame('My Title', $serialized['label']);
        self::assertInstanceOf(CrossContentRepositoryReference::class, $serialized['value']);
        self::assertSame('default', $serialized['value']->contentRepositoryId->value);
        self::assertSame('abc-123', $serialized['value']->nodeAggregateId->value);
        self::assertSame('Neos.Neos:Document', $serialized['nodeType']->value);
    }

    #[Test]
    public function valueIsCrossContentRepositoryReference(): void
    {
        $serialized = $this->option->jsonSerialize();

        $value = $serialized['value'];
        self::assertInstanceOf(CrossContentRepositoryReference::class, $value);
        self::assertSame('default', $value->contentRepositoryId->value);
        self::assertSame('abc-123', $value->nodeAggregateId->value);
    }

    #[Test]
    public function fullJsonEncodingProducesCorrectStructure(): void
    {
        $encoded = json_encode($this->option);
        self::assertIsString($encoded);

        $decoded = json_decode($encoded, true);
        self::assertSame('My Title', $decoded['label']);
        self::assertSame('default', $decoded['value']['contentRepositoryId']);
        self::assertSame('abc-123', $decoded['value']['nodeAggregateId']);
        self::assertSame('Neos.Neos:Document', $decoded['nodeType']);
    }
}
