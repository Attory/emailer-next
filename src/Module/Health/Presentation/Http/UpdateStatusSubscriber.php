<?php

declare(strict_types=1);

namespace App\Module\Health\Presentation\Http;

use App\Module\Health\Application\UpdateStatus;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class UpdateStatusSubscriber implements EventSubscriberInterface
{
    public function __construct(private UpdateStatus $state)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['load', -20]];
    }

    public function load(ControllerEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if ('admin_update_status' !== $route && $event->isMainRequest() && \is_string($route) && str_starts_with($route, 'admin_')) {
            $event->getRequest()->attributes->set('update_delivery', $this->state->current());
        }
    }
}
