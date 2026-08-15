<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\MfaManagement;
use App\Module\Identity\Application\ReauthenticationService;
use App\Module\Identity\Application\SessionRegistry;
use App\Module\Identity\Application\UserAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/admin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserAdministration $users,
        private readonly MfaManagement $mfa,
        private readonly ReauthenticationService $reauthentication,
        private readonly SessionRegistry $sessions,
        private readonly AuditSink $audit,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET', 'HEAD'])]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', ['user' => $this->user()]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-logout')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $user = $this->user();
        $this->audit->append(new AuditRecord($user->id(), 'authentication.logout', $user->id(), 'success'));
        $this->sessions->revoke($user->id(), $this->sessions->currentHash($request->getSession()->getId()));
        $this->tokens->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('identity_login', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/reauthenticate', name: 'admin_reauthenticate', methods: ['GET', 'POST'])]
    public function reauthenticate(Request $request): Response
    {
        $purpose = $request->isMethod('POST') ? $request->request->getString('purpose') : $request->query->getString('purpose', 'sensitive_operation');
        if ($request->isMethod('GET')) {
            return $this->render('admin/reauthenticate.html.twig', ['purpose' => $purpose]);
        }
        if (!$this->validCsrf($request, 'admin-reauthenticate')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $user = $this->user();
        if (!$this->reauthentication->verify($user, $request->request->getString('password'), $request->request->getString('mfa_code'), $purpose)) {
            $this->addFlash('error', 'Re-authentication failed.');

            return $this->redirectToRoute('admin_reauthenticate', ['purpose' => $purpose], Response::HTTP_SEE_OTHER);
        }
        $this->reauthentication->mark($request->getSession(), $purpose);
        $this->rotateAndBind($request, $user);
        $this->addFlash('success', 'Recent re-authentication recorded.');

        return $this->redirectToRoute('admin_dashboard', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/profile', name: 'admin_profile', methods: ['GET'])]
    public function profile(): Response
    {
        return $this->render('admin/profile.html.twig', ['user' => $this->user()]);
    }

    #[Route('/profile/password', name: 'admin_password_change', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-password-change')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'password_change');
        $password = $request->request->getString('new_password');
        if (!hash_equals($password, $request->request->getString('password_confirmation'))) {
            $this->addFlash('error', 'Password change was not accepted.');

            return $this->redirectToRoute('admin_profile', status: Response::HTTP_SEE_OTHER);
        }
        try {
            $updatedUser = $this->users->changePassword($this->user(), $request->request->getString('current_password'), $password, $request->getSession()->getId());
            $this->replaceCurrentUser($updatedUser);
            $this->rotateAndBind($request, $updatedUser);
            $this->addFlash('success', 'Password changed.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Password change was not accepted.');
        }

        return $this->redirectToRoute('admin_profile', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/sessions', name: 'admin_sessions', methods: ['GET'])]
    public function sessions(Request $request): Response
    {
        $user = $this->user();

        return $this->render('admin/sessions.html.twig', [
            'sessions' => $this->sessions->listForUser($user->id(), $request->getSession()->getId()),
        ]);
    }

    #[Route('/sessions/revoke', name: 'admin_session_revoke', methods: ['POST'])]
    public function revokeSession(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-session-revoke')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'session_management');
        $user = $this->user();
        $idHash = $request->request->getString('session');
        if (64 !== \strlen($idHash) || 1 !== preg_match('/^[a-f0-9]{64}$/', $idHash) || !$this->sessions->revoke($user->id(), $idHash)) {
            $this->addFlash('error', 'Session revocation was not accepted.');

            return $this->redirectToRoute('admin_sessions', status: Response::HTTP_SEE_OTHER);
        }
        $this->audit->append(new AuditRecord($user->id(), 'identity.session.revoked', $user->id(), 'success'));
        if (hash_equals($idHash, $this->sessions->currentHash($request->getSession()->getId()))) {
            $this->tokens->setToken(null);
            $request->getSession()->invalidate();

            return $this->redirectToRoute('identity_login', status: Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_sessions', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/mfa', name: 'admin_mfa', methods: ['GET'])]
    public function mfa(): Response
    {
        return $this->render('admin/mfa.html.twig', ['status' => $this->mfa->status($this->user())]);
    }

    #[Route('/mfa/start', name: 'admin_mfa_start', methods: ['POST'])]
    public function startMfa(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-mfa-start')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'mfa_management');
        try {
            return $this->render('admin/mfa-enrollment.html.twig', ['enrollment' => $this->mfa->start($this->user())]);
        } catch (\Throwable) {
            $this->addFlash('error', 'MFA enrollment was not accepted.');

            return $this->redirectToRoute('admin_mfa', status: Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/mfa/confirm', name: 'admin_mfa_confirm', methods: ['POST'])]
    public function confirmMfa(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-mfa-confirm')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'mfa_management');
        try {
            $this->mfa->confirm($this->user(), $request->request->getString('totp_code'), $request->request->getBoolean('recovery_saved'), $request->getSession()->getId());
            $this->rotateAndBind($request, $this->user());
            $this->addFlash('success', 'MFA enabled.');
        } catch (\Throwable) {
            $this->addFlash('error', 'MFA confirmation was not accepted.');
        }

        return $this->redirectToRoute('admin_mfa', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/mfa/disable', name: 'admin_mfa_disable', methods: ['POST'])]
    public function disableMfa(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-mfa-disable')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'mfa_management');
        $this->mfa->disable($this->user(), $request->getSession()->getId());
        $this->rotateAndBind($request, $this->user());

        return $this->redirectToRoute('admin_mfa', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/mfa/recovery', name: 'admin_mfa_recovery', methods: ['POST'])]
    public function regenerateRecovery(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-mfa-recovery')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'mfa_management');
        try {
            $codes = $this->mfa->regenerateRecoveryCodes($this->user(), $request->getSession()->getId());
            $this->rotateAndBind($request, $this->user());

            return $this->render('admin/recovery-codes.html.twig', ['recovery_codes' => $codes]);
        } catch (\Throwable) {
            $this->addFlash('error', 'Recovery-code regeneration was not accepted.');

            return $this->redirectToRoute('admin_mfa', status: Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(): Response
    {
        return $this->render('admin/users.html.twig', [
            'users' => $this->users->list($this->user()),
            'roles' => AdministrativeRole::cases(),
        ]);
    }

    #[Route('/users/create', name: 'admin_user_create', methods: ['POST'])]
    public function createUser(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-user-create')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'user_management');
        try {
            $this->users->create(
                $this->user(),
                $request->request->getString('login_identifier'),
                $request->request->getString('password'),
                AdministrativeRole::from($request->request->getString('role')),
            );
            $this->addFlash('success', 'User created.');
        } catch (\Throwable) {
            $this->addFlash('error', 'User creation was not accepted.');
        }

        return $this->redirectToRoute('admin_users', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/users/{id}/update', name: 'admin_user_update', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function updateUser(Request $request, string $id): Response
    {
        if (!$this->validCsrf($request, 'admin-user-update-'.$id)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'user_management');
        try {
            $updatedUser = $this->users->update(
                $this->user(),
                $id,
                AdministrativeRole::from($request->request->getString('role')),
                $request->request->getBoolean('active'),
            );
            if (hash_equals($this->user()->id(), $id)) {
                if (!$updatedUser->active()) {
                    $this->tokens->setToken(null);
                    $request->getSession()->invalidate();

                    return $this->redirectToRoute('identity_login', status: Response::HTTP_SEE_OTHER);
                }
                $this->replaceCurrentUser($updatedUser);
                $this->rotateAndBind($request, $updatedUser);
            }
            $this->addFlash('success', 'User updated.');
        } catch (\Throwable) {
            $this->addFlash('error', 'User update was not accepted.');
        }

        return $this->redirectToRoute('admin_users', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/users/{id}/delete', name: 'admin_user_delete', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function deleteUser(Request $request, string $id): Response
    {
        if (!$this->validCsrf($request, 'admin-user-delete-'.$id)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'user_management');
        try {
            $this->users->delete($this->user(), $id);
            $this->addFlash('success', 'User deleted.');
        } catch (\Throwable) {
            $this->addFlash('error', 'User deletion was not accepted.');
        }

        return $this->redirectToRoute('admin_users', status: Response::HTTP_SEE_OTHER);
    }

    private function user(): AuthenticatedUser
    {
        $user = $this->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function validCsrf(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, $request->request->getString('_token'));
    }

    private function rotateAndBind(Request $request, AuthenticatedUser $user): void
    {
        $request->getSession()->migrate(true);
        $this->sessions->bind(
            $request->getSession()->getId(),
            $user->id(),
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );
    }

    private function replaceCurrentUser(AuthenticatedUser $user): void
    {
        $token = $this->tokens->getToken();
        if (null === $token) {
            throw $this->createAccessDeniedException();
        }
        $token->setUser($user);
    }
}
