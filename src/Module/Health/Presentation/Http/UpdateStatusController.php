<?php

declare(strict_types=1);

namespace App\Module\Health\Presentation\Http;

use App\Module\Health\Application\UpdateStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UpdateStatusController extends AbstractController
{
    #[Route('/admin/update-status', name: 'admin_update_status', methods: ['GET', 'HEAD'])]
    public function status(UpdateStatus $state): Response
    {
        $this->denyAccessUnlessGranted('ROLE_AUDITOR');
        $response = $this->json($state->current());
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
