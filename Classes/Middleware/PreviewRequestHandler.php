<?php

declare(strict_types=1);

namespace Flowd\Typo3Look\Middleware;

use Flowd\Typo3Look\Controller\PreviewController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\Route;

/**
 * Answers the preview route before the backend authentication runs.
 *
 * The core keeps the list of backend routes that work without a session (login, install tool)
 * inside its authentication middleware; "access: public" on a route only skips the CSRF token.
 * The preview frame has an opaque origin and cannot send the session cookie, so this middleware
 * sits between routing and authentication and handles the route itself. Everything else passes
 * through untouched. Access is controlled by the signed, short-lived token the controller checks.
 */
final readonly class PreviewRequestHandler implements MiddlewareInterface
{
    public const ROUTE_IDENTIFIER = 'look_preview';

    public function __construct(private PreviewController $controller) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        if ($route instanceof Route && $route->getOption('_identifier') === self::ROUTE_IDENTIFIER) {
            return $this->controller->render($request);
        }

        return $handler->handle($request);
    }
}
