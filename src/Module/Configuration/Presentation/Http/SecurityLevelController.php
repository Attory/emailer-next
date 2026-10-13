<?php

declare(strict_types=1);

namespace App\Module\Configuration\Presentation\Http;

use App\Module\Configuration\Application\SecurityLevelManager;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\LoginThrottle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/admin/settings/security')]
final class SecurityLevelController extends AbstractController
{
    public function __construct(private readonly SecurityLevelManager $levels, private readonly LoginThrottle $throttle)
    {
    }

    #[Route('', name: 'admin_security_settings', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        return $this->render('admin/security-settings.html.twig', ['security_level' => $this->levels->current()]);
    }

    #[Route('/update', name: 'admin_security_settings_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('security-level-update', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->levels->change(
                $this->actor(),
                $request->getSession(),
                $request->request->getString('security_level'),
                'lower_to_normal' === $request->request->getString('confirm_normal'),
                $this->throttle->sourceHash($request->getClientIp() ?? 'unknown'),
            );
            $this->addFlash('success', 'Installation security level updated.');
        } catch (AccessDeniedException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            $this->addFlash('error', 'Security level change was not accepted.');
        }

        return $this->redirectToRoute('admin_security_settings', status: Response::HTTP_SEE_OTHER);
    }

    private function actor(): AdministrativeActor
    {
        $actor = $this->getUser();
        if (!$actor instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }

        return $actor;
    }
}
