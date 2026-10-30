<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity;

use App\Module\Identity\Presentation\Http\PollSessionCookieSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PollSessionCookieSubscriberTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function requests(): iterable
    {
        yield 'revoked status poll' => ['/admin/update-status', 'GET', false];
        yield 'campaign HEAD poll' => ['/admin/campaigns/01900000-0000-7000-8000-000000000001/progress', 'HEAD', false];
        yield 'reauthentication rotation' => ['/admin/reauthenticate', 'POST', true];
        yield 'MFA confirmation rotation' => ['/admin/mfa/confirm', 'POST', true];
        yield 'ordinary login' => ['/login', 'GET', true];
        yield 'different route' => ['/admin/profile', 'GET', true];
        yield 'unsafe status request' => ['/admin/update-status', 'POST', true];
    }

    #[DataProvider('requests')]
    public function testOnlyBackgroundReadsLoseTheirSessionCookie(string $path, string $method, bool $retained): void
    {
        $request = Request::create($path, $method);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $response = new Response(status: 302);
        $response->headers->setCookie(Cookie::create($request->getSession()->getName(), 'synthetic-replacement'));
        $response->headers->setCookie(Cookie::create('other_cookie', 'synthetic'));
        (new PollSessionCookieSubscriber())->protectCookie(new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $names = array_map(static fn (Cookie $cookie): string => $cookie->getName(), $response->headers->getCookies());
        self::assertSame($retained, \in_array($request->getSession()->getName(), $names, true));
        self::assertContains('other_cookie', $names);
        self::assertSame(302, $response->getStatusCode());
    }
}
