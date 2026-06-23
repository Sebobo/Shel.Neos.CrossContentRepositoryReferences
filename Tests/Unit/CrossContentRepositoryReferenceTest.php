<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Tests\Unit;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shel\Neos\CrossContentRepositoryReferences\Dto\CrossContentRepositoryReference;

class CrossContentRepositoryReferenceTest extends TestCase
{
    private ContentRepositoryId $crId;
    private NodeAggregateId $nodeId;
    private CrossContentRepositoryReference $reference;

    protected function setUp(): void
    {
        $this->crId = ContentRepositoryId::fromString('default');
        $this->nodeId = NodeAggregateId::fromString('abc-123');
        $this->reference = new CrossContentRepositoryReference($this->crId, $this->nodeId);
    }

    #[Test]
    public function constructorStoresProperties(): void
    {
        self::assertSame($this->crId, $this->reference->contentRepositoryId);
        self::assertSame($this->nodeId, $this->reference->nodeAggregateId);
    }

    #[Test]
    public function fromArrayReturnsInstanceWithValidData(): void
    {
        $result = CrossContentRepositoryReference::fromArray([
            'contentRepositoryId' => 'hub',
            'nodeAggregateId' => 'def-456',
        ]);

        self::assertNotNull($result);
        self::assertSame('hub', $result->contentRepositoryId->value);
        self::assertSame('def-456', $result->nodeAggregateId->value);
    }

    #[Test]
    public function fromArrayReturnsNullWhenContentRepositoryIdIsMissing(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromArray([
            'nodeAggregateId' => 'abc-123',
        ]));
    }

    #[Test]
    public function fromArrayReturnsNullWhenNodeAggregateIdIsMissing(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromArray([
            'contentRepositoryId' => 'default',
        ]));
    }

    #[Test]
    public function fromArrayReturnsNullWhenContentRepositoryIdIsNotAString(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromArray([
            'contentRepositoryId' => 123,
            'nodeAggregateId' => 'abc-123',
        ]));
    }

    #[Test]
    public function fromArrayReturnsNullWhenNodeAggregateIdIsNotAString(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromArray([
            'contentRepositoryId' => 'default',
            'nodeAggregateId' => 456,
        ]));
    }

    #[Test]
    public function fromArrayReturnsNullWhenContentRepositoryIdIsInvalid(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromArray([
            'contentRepositoryId' => '',
            'nodeAggregateId' => 'abc-123',
        ]));
    }

    #[Test]
    public function fromArrayReturnsNullWhenNodeAggregateIdIsInvalid(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromArray([
            'contentRepositoryId' => 'default',
            'nodeAggregateId' => '',
        ]));
    }

    #[Test]
    public function fromJsonStringReturnsInstanceWithValidJson(): void
    {
        $result = CrossContentRepositoryReference::fromString(
            '{"contentRepositoryId":"hub","nodeAggregateId":"def-456"}',
        );

        self::assertNotNull($result);
        self::assertSame('hub', $result->contentRepositoryId->value);
        self::assertSame('def-456', $result->nodeAggregateId->value);
    }

    #[Test]
    public function fromJsonStringReturnsNullWithInvalidJson(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromString('not-json'));
    }

    #[Test]
    public function fromJsonStringReturnsNullWithNonArrayJson(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromString('"just-a-string"'));
    }

    #[Test]
    public function fromJsonStringReturnsNullWithEmptyObject(): void
    {
        self::assertNull(CrossContentRepositoryReference::fromString('{}'));
    }

    #[Test]
    public function toJsonProducesValidJsonString(): void
    {
        $json = json_encode($this->reference);

        self::assertJson($json);
        $decoded = json_decode($json, true);
        self::assertSame('default', $decoded['contentRepositoryId']);
        self::assertSame('abc-123', $decoded['nodeAggregateId']);
    }

    #[Test]
    public function jsonSerializeReturnsExpectedStructure(): void
    {
        $serialized = $this->reference->jsonSerialize();

        self::assertSame('default', $serialized['contentRepositoryId']);
        self::assertSame('abc-123', $serialized['nodeAggregateId']);
    }

    #[Test]
    public function roundTripJsonProducesEqualReference(): void
    {
        $json = json_encode($this->reference);
        $restored = CrossContentRepositoryReference::fromString($json);

        self::assertNotNull($restored);
        self::assertTrue($this->reference->contentRepositoryId->equals($restored->contentRepositoryId));
        self::assertTrue($this->reference->nodeAggregateId->equals($restored->nodeAggregateId));
    }
}
