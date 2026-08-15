<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Identity\Application\RoutePolicyRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AdminAuthorizationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RoutePolicyRegistry $policies,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'authorize'];
    }

    public function authorize(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = $event->getRequest()->attributes->get('_route');
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/admin')) {
            return;
        }

        $policy = \is_string($route) ? $this->policies->policy($route) : null;
        if (null === $policy
            || !\in_array($event->getRequest()->getMethod(), $policy['methods'], true)
            || !$this->authorization->isGranted($policy['role']->value)
        ) {
            throw new AccessDeniedException('Access denied.');
        }
    }
}
