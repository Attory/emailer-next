<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Background reads cannot replace a cookie established by a newer login/rotation. */
final readonly class PollSessionCookieSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Run after Symfony's session listener has saved and attached its cookie.
        return [KernelEvents::RESPONSE => ['protectCookie', -1100]];
    }

    public function protectCookie(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->isMethodSafe() || !$request->hasSession()) {
            return;
        }
        $path = $request->getPathInfo();
        if ('/admin/update-status' !== $path && 1 !== preg_match('~^/admin/campaigns/[0-9a-f-]{36}/progress$~D', $path)) {
            return;
        }
        $name = $request->getSession()->getName();
        foreach ($event->getResponse()->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                $event->getResponse()->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }
    }
}
