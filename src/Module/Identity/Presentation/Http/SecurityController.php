<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\LoginThrottle;
use App\Module\Identity\Application\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $passwordReset,
        private readonly LoginThrottle $loginThrottle,
        #[Target('password_reset')]
        private readonly RateLimiterFactoryInterface $resetLimiter,
    ) {
    }

    #[Route('/', name: 'identity_home', methods: ['GET', 'HEAD'])]
    public function home(): Response
    {
        return $this->redirectToRoute(null === $this->getUser() ? 'identity_login' : 'admin_dashboard', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/login', name: 'identity_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $target = $request->query->getString('return');
        // Only local admin pages may become a post-login continuation.
        if (('/admin' === $target || str_starts_with($target, '/admin/'))
            && !str_contains($target, '\\') && 0 === preg_match('/[[:cntrl:][:space:]]/', $target)
            && !str_contains($target, '/update-status') && !str_ends_with((string) parse_url($target, PHP_URL_PATH), '/progress')) {
            $request->getSession()->set('_security.main.target_path', $target);
        }
        if ($this->getUser() instanceof AuthenticatedUser) {
            $savedTarget = $request->getSession()->get('_security.main.target_path');

            return \is_string($savedTarget) ? $this->redirect($savedTarget, Response::HTTP_SEE_OTHER) : $this->redirectToRoute('admin_dashboard', status: Response::HTTP_SEE_OTHER);
        }
        $expiry = $request->query->getString('expiry', $request->attributes->getString('identity.session_expiry'));
        if (\in_array($expiry, ['idle', 'absolute'], true)) {
            $request->getSession()->set('identity.expiry_notice', $expiry);
        }

        $loginError = true === $request->getSession()->remove('identity.login_error');

        return $this->render('identity/login.html.twig', [
            'last_login' => $request->getSession()->get('identity.last_login', ''),
            'login_error' => $loginError,
            'expiry_notice' => $request->getSession()->get('identity.expiry_notice'),
        ]);
    }

    #[Route('/password-reset', name: 'identity_password_reset_request', methods: ['GET', 'POST'])]
    public function requestReset(Request $request): Response
    {
        if ($request->isMethodSafe()) {
            return $this->render('identity/password-reset-request.html.twig');
        }

        $token = $request->request->getString('_token');
        if (!$this->isCsrfTokenValid('password-reset-request', $token)) {
            return $this->render('identity/password-reset-requested.html.twig', response: new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $login = $request->request->getString('login_identifier');
        $source = $request->getClientIp() ?? 'unknown';
        $accepted = $this->resetLimiter->create($this->loginThrottle->sourceHash($source))->consume()->isAccepted()
            && $this->resetLimiter->create($this->loginThrottle->accountHash($login))->consume()->isAccepted();
        if ($accepted) {
            try {
                $this->passwordReset->request($login, $this->loginThrottle->sourceHash($source));
            } catch (\Throwable) {
                // The public result remains deliberately identical.
            }
        }

        return $this->render('identity/password-reset-requested.html.twig');
    }

    #[Route('/password-reset/{selector}', name: 'identity_password_reset', requirements: ['selector' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function reset(Request $request, string $selector): Response
    {
        if ($request->isMethodSafe()) {
            return $this->render('identity/password-reset.html.twig', ['selector' => $selector]);
        }
        if (!$this->isCsrfTokenValid('password-reset-'.$selector, $request->request->getString('_token'))) {
            return $this->render('identity/password-reset-failed.html.twig', response: new Response(status: Response::HTTP_BAD_REQUEST));
        }
        $source = $request->getClientIp() ?? 'unknown';
        if (!$this->resetLimiter->create($this->loginThrottle->sourceHash($source))->consume()->isAccepted()
            || !$this->resetLimiter->create($this->loginThrottle->accountHash('reset-selector:'.$selector))->consume()->isAccepted()
        ) {
            return $this->render('identity/password-reset-failed.html.twig', response: new Response(status: Response::HTTP_BAD_REQUEST));
        }
        $password = $request->request->getString('password');
        if (!hash_equals($password, $request->request->getString('password_confirmation'))) {
            return $this->render('identity/password-reset-failed.html.twig', response: new Response(status: Response::HTTP_BAD_REQUEST));
        }

        try {
            $this->passwordReset->complete(
                $selector,
                $request->request->getString('reset_token'),
                $password,
                $this->loginThrottle->sourceHash($source),
            );
        } catch (\Throwable) {
            return $this->render('identity/password-reset-failed.html.twig', response: new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $request->getSession()->invalidate();

        return $this->redirectToRoute('identity_login', status: Response::HTTP_SEE_OTHER);
    }
}
