<?php

declare(strict_types=1);

namespace App\Module\Configuration\Presentation\Http;

use App\Module\Configuration\Application\AppearanceSettings;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class AppearanceSubscriber implements EventSubscriberInterface
{
    public function __construct(private AppearanceSettings $settings, private TokenStorageInterface $tokens)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Run only after the existing administrative authorization subscriber.
        return [KernelEvents::CONTROLLER => ['load', -20]];
    }

    public function load(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/admin')) {
            return;
        }
        $actor = $this->tokens->getToken()?->getUser();
        if ($actor instanceof AdministrativeActor) {
            $event->getRequest()->attributes->set('appearance', $this->settings->view($actor));
        }
    }
}
