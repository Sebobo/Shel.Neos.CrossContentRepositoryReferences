<?php

declare(strict_types=1);

namespace Shel\Neos\CrossContentRepositoryReferences\Middleware;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionFailedException;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that overrides the SiteDetectionResult for frontend preview
 * requests (`previewAction` / `showAction`) when the NodeAddress in the
 * `node` query parameter specifies a different content repository than
 * the one detected from the request's domain.
 *
 * The Neos preview action resolves the content repository from the
 * {@see SiteDetectionResult} (set by the {@see SiteDetectionMiddleware})
 * rather than from the NodeAddress. When the referenced node lives in a
 * different content repository than the currently edited site, the preview
 * action looks up the node in the wrong CR and fails with
 * "The requested node does not exist or isn't accessible to the current user".
 *
 * This middleware transparently corrects the SiteDetectionResult for such
 * requests so that the preview action finds the node in the correct CR.
 *
 * It must be registered **after** the `detectSite` middleware:
 *
 * ```yaml
 * Neos:
 *   Flow:
 *     http:
 *       middlewares:
 *         'crossContentRepositoryReferencePreview':
 *           position: 'after detectSite'
 *           middleware: 'Shel\Neos\CrossContentRepositoryReferences\Middleware\CrossContentRepositoryPreviewMiddleware'
 * ```
 */
final class CrossContentRepositoryPreviewMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nodeParam = $request->getQueryParams()['node'] ?? null;

        // Only interested in requests carrying a NodeAddress in the `node` query param.
        if (!is_string($nodeParam) || $nodeParam === '') {
            return $handler->handle($request);
        }

        // Parse the NodeAddress from the query parameter. If it's invalid, move on.
        try {
            $nodeAddress = NodeAddress::fromJsonString($nodeParam);
        } catch (\InvalidArgumentException) {
            return $handler->handle($request);
        }

        // Read the currently active SiteDetectionResult (set by SiteDetectionMiddleware).
        // If site detection hasn't run yet, this throws — we just pass through.
        try {
            $currentSiteDetectionResult = SiteDetectionResult::fromRequest($request);
        } catch (SiteDetectionFailedException) {
            return $handler->handle($request);
        }

        // If both CRs are the same, nothing to fix.
        if ($currentSiteDetectionResult->contentRepositoryId->equals($nodeAddress->contentRepositoryId)) {
            return $handler->handle($request);
        }

        // Override the content repository in the routing parameters so that
        // subsequent reads of SiteDetectionResult (e.g. in the preview action)
        // return the correct CR. Keep the current siteNodeName — the preview
        // action finds the actual site from the node itself.
        $correctedSiteDetectionResult = SiteDetectionResult::create(
            $currentSiteDetectionResult->siteNodeName,
            $nodeAddress->contentRepositoryId,
        );

        return $handler->handle($correctedSiteDetectionResult->storeInRequest($request));
    }
}
