<?php

declare(strict_types=1);

namespace App\Module\Backup\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Backup\Application\BackupEngine;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\ReauthenticationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/backups')]
final class BackupController extends AbstractController
{
    public function __construct(private readonly BackupEngine $backups, private readonly ReauthenticationService $reauthentication, private readonly AuditSink $audit)
    {
    }

    #[Route('', name: 'admin_backups', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        return $this->render('admin/backups.html.twig', ['backups' => $this->backups->recentRecords()]);
    }

    #[Route('/create', name: 'admin_backup_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup-create', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'backup_management');
        $backup = $this->backups->create();
        $actor = $this->getUser();
        if (!$actor instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }
        $this->audit->append(new AuditRecord($actor->id(), 'backup.created', $backup['id'], 'success', ['byte_size' => $backup['bytes']]));
        $this->addFlash('success', 'Encrypted backup created and verified.');

        return $this->redirectToRoute('admin_backups', status: Response::HTTP_SEE_OTHER);
    }
}
