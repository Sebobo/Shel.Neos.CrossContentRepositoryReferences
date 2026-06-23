<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Tests\Unit;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shel\Neos\CrossContentRepositoryReferences\Service\DimensionTranslator;

class DimensionTranslatorTest extends TestCase
{
    private ContentRepositoryId $targetCrId;

    protected function setUp(): void
    {
        $this->targetCrId = ContentRepositoryId::fromString('target');
    }

    private function createTranslatorWithSettings(array $crSettings): DimensionTranslator
    {
        $translator = new DimensionTranslator();
        $translator->injectCrSettings($crSettings);
        return $translator;
    }

    #[Test]
    public function returnsDimensionlessPointWhenTargetHasNoDimensions(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'de']),
        );

        self::assertSame([], $result->coordinates);
    }

    #[Test]
    public function returnsDimensionlessPointWhenTargetHasNoContentDimensionsKey(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'de']),
        );

        self::assertSame([], $result->coordinates);
    }

    #[Test]
    public function keepsSourceDimensionWhenValueExistsInTarget(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [
                    'language' => [
                        'values' => [
                            'de' => ['label' => 'German'],
                            'en' => ['label' => 'English'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'de']),
        );

        self::assertSame('de', $result->coordinates['language']);
    }

    #[Test]
    public function dropsSourceDimensionNotExistingInTarget(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [
                    'language' => [
                        'values' => [
                            'de' => ['label' => 'German'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['market' => 'eu']),
        );

        self::assertArrayNotHasKey('market', $result->coordinates);
    }

    #[Test]
    public function fallsBackToDefaultWhenSourceValueIsInvalid(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [
                    'language' => [
                        'values' => [
                            'de' => ['label' => 'German'],
                            'en' => ['label' => 'English'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'fr']),
        );

        self::assertSame('de', $result->coordinates['language']);
    }

    #[Test]
    public function fillsMissingTargetDimensionsWithDefaultValue(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [
                    'language' => [
                        'values' => [
                            'en' => ['label' => 'English'],
                            'de' => ['label' => 'German'],
                        ],
                    ],
                    'country' => [
                        'values' => [
                            'us' => ['label' => 'United States'],
                            'de' => ['label' => 'Germany'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'de']),
        );

        self::assertSame('de', $result->coordinates['language']);
        self::assertSame('us', $result->coordinates['country']);
    }

    #[Test]
    public function handlesMixedScenarioWithMultipleDimensions(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [
                    'language' => [
                        'values' => [
                            'de' => ['label' => 'German'],
                            'en' => ['label' => 'English'],
                        ],
                    ],
                    'country' => [
                        'values' => [
                            'ch' => ['label' => 'Switzerland'],
                            'de' => ['label' => 'Germany'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray([
                'language' => 'en',
                'unknown' => 'val',
                'country' => 'invalid',
            ]),
        );

        self::assertCount(2, $result->coordinates);
        self::assertSame('en', $result->coordinates['language']);
        self::assertSame('ch', $result->coordinates['country']);
    }

    #[Test]
    public function collectsSpecializationValuesAsValid(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [
                    'language' => [
                        'values' => [
                            'de' => [
                                'label' => 'German',
                                'specializations' => [
                                    'en' => ['label' => 'English'],
                                    'fr' => ['label' => 'French'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'en']),
        );

        self::assertSame('en', $result->coordinates['language']);
    }

    #[Test]
    public function handlesNonArrayDimensionConfig(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'target' => [
                'contentDimensions' => [
                    'language' => 'not-an-array',
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'de']),
        );

        self::assertSame([], $result->coordinates);
    }

    #[Test]
    public function handlesMissingCrInSettings(): void
    {
        $translator = $this->createTranslatorWithSettings([
            'other-cr' => [
                'contentDimensions' => [
                    'language' => [
                        'values' => [
                            'de' => ['label' => 'German'],
                        ],
                    ],
                ],
            ],
        ]);

        $result = $translator->translateDimensionSpacePoint(
            $this->targetCrId,
            DimensionSpacePoint::fromArray(['language' => 'de']),
        );

        self::assertSame([], $result->coordinates);
    }
}
