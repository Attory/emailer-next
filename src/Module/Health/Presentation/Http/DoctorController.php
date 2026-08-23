<?php

declare(strict_types=1);

namespace App\Module\Health\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Health\Application\Doctor;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/doctor')]
final class DoctorController extends AbstractController
{
    public function __construct(private readonly Doctor $doctor, private readonly AuditSink $audit)
    {
    }

    #[Route('', name: 'admin_doctor', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        return $this->render('admin/doctor.html.twig', ['runs' => $this->doctor->recent()]);
    }

    #[Route('/run', name: 'admin_doctor_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('doctor-run', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $result = $this->doctor->run();
        $this->audit->append(new AuditRecord($this->actor()->id(), 'doctor.run', $result['id'], 'critical' === $result['status'] ? 'failure' : 'success', ['critical_count' => $result['critical_count'], 'warning_count' => $result['warning_count']]));

        return $this->redirectToRoute('admin_doctor', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/export', name: 'admin_doctor_export', methods: ['GET', 'HEAD'])]
    public function export(): JsonResponse
    {
        return new JsonResponse(['format' => 'emailer-next-doctor-report-v1', 'runs' => $this->doctor->recent()], headers: ['Content-Disposition' => 'attachment; filename="emailer-doctor-report.json"']);
    }

    #[Route('/findings/{code}/acknowledge', name: 'admin_doctor_acknowledge', requirements: ['code' => '[a-z0-9_]{1,64}'], methods: ['POST'])]
    public function acknowledge(Request $request, string $code): Response
    {
        if (!$this->isCsrfTokenValid('doctor-acknowledge-'.$code, $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->audit->append(new AuditRecord($this->actor()->id(), 'doctor.finding.acknowledged', $code, 'success'));
        $this->addFlash('success', 'Finding acknowledgement recorded. Sending gates are unchanged.');

        return $this->redirectToRoute('admin_doctor', status: Response::HTTP_SEE_OTHER);
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
