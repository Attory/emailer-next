<?php

declare(strict_types=1);

namespace App\Module\Housekeeping\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Housekeeping\Application\Housekeeper;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/retention')]
final class RetentionController extends AbstractController
{
    public function __construct(private readonly Housekeeper $housekeeper, private readonly AuditSink $audit)
    {
    }

    #[Route('', name: 'admin_retention', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        return $this->render('admin/retention.html.twig');
    }

    #[Route('/run', name: 'admin_retention_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('retention-run', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $counts = $this->housekeeper->run(!$request->request->getBoolean('apply'));
        $actor = $this->getUser();
        if (!$actor instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }
        $this->audit->append(new AuditRecord($actor->id(), 'retention.run', null, 'success', $counts));
        $this->addFlash('success', 'Bounded retention run completed.');

        return $this->redirectToRoute('admin_retention', status: Response::HTTP_SEE_OTHER);
    }
}
