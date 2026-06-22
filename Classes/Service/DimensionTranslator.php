<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Service;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;

/**
 * Translates a {@see DimensionSpacePoint} from a source content repository's
 * dimension configuration to be valid in a target content repository's
 * dimension configuration.
 *
 * This is necessary because different content repositories can have
 * different dimension configurations (dimension names, allowed values,
 * specializations). A dimension space point that is valid in one CR
 * may reference dimensions or values that don't exist in another CR.
 */
#[Flow\Scope('singleton')]
final class DimensionTranslator
{

    /**
     * @
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration('contentRepositories', 'Neos.ContentRepositoryRegistry')]
    protected array $crSettings;

    /**
     * @param array<string, mixed> $crSettings
     */
    public function injectCrSettings(array $crSettings): void
    {
        $this->crSettings = $crSettings;
    }

    /**
     * Translate a {@see DimensionSpacePoint} to be valid in the target content
     * repository's dimension configuration.
     *
     * Dimensions that don't exist in the target CR are dropped. For dimensions
     * that exist in the target CR but whose value is invalid, the first valid
     * value for that dimension is used as fallback. Dimensions that exist in
     * the target CR but are missing from the source DSP are filled with their
     * default (first) value.
     */
    public function translateDimensionSpacePoint(
        ContentRepositoryId $targetCrId,
        DimensionSpacePoint $sourceDsp,
    ): DimensionSpacePoint {
        $targetDimValues = $this->getDimensionValuesMap($targetCrId);

        if ($targetDimValues === []) {
            return DimensionSpacePoint::createWithoutDimensions();
        }

        $translatedCoordinates = [];
        foreach ($sourceDsp->coordinates as $dimName => $dimValue) {
            $targetValues = $targetDimValues[$dimName] ?? null;
            if ($targetValues === null) {
                continue;
            }
            /** @var list<string> $targetValues */
            if (in_array($dimValue, $targetValues, true)) {
                $translatedCoordinates[$dimName] = $dimValue;
            } else {
                $translatedCoordinates[$dimName] = $targetValues[0];
            }
        }

        foreach ($targetDimValues as $dimName => $targetValues) {
            if (!array_key_exists($dimName, $translatedCoordinates)) {
                /** @var list<string> $targetValues */
                $translatedCoordinates[$dimName] = $targetValues[0];
            }
        }

        return DimensionSpacePoint::fromArray($translatedCoordinates);
    }

    /**
     * @return array<string, list<string>>
     */
    private function getDimensionValuesMap(ContentRepositoryId $crId): array
    {
        $crConfig = $this->crSettings[$crId->value] ?? [];
        if (!is_array($crConfig)) {
            $crConfig = [];
        }
        $contentDimensions = $crConfig['contentDimensions'] ?? [];
        if (!is_array($contentDimensions)) {
            $contentDimensions = [];
        }

        $map = [];
        foreach ($contentDimensions as $dimId => $dimConfig) {
            if (!is_array($dimConfig)) {
                continue;
            }
            if (!is_string($dimId)) {
                continue;
            }
            $values = [];
            $dimValues = $dimConfig['values'] ?? [];
            if (is_array($dimValues)) {
                $typedDimValues = [];
                foreach ($dimValues as $k => $v) {
                    if (is_string($k)) {
                        $typedDimValues[$k] = $v;
                    }
                }
                $this->collectDimensionValues($typedDimValues, $values);
            }
            $map[$dimId] = $values;
        }
        return $map;
    }

    /**
     * @param array<mixed> $valuesConfig
     * @param list<string> &$values
     */
    private function collectDimensionValues(array $valuesConfig, array &$values): void
    {
        foreach ($valuesConfig as $valId => $valConfig) {
            if (!is_string($valId)) {
                continue;
            }
            if (!is_array($valConfig)) {
                continue;
            }
            $values[] = $valId;
            $specializations = $valConfig['specializations'] ?? [];
            if (is_array($specializations)) {
                $typedSpecs = [];
                foreach ($specializations as $k => $v) {
                    if (is_string($k)) {
                        $typedSpecs[$k] = $v;
                    }
                }
                $this->collectDimensionValues($typedSpecs, $values);
            }
        }
    }
}
