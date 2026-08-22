<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Presentation\Http;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\ReauthenticationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/campaigns')]
final class CampaignsController extends AbstractController
{
    public function __construct(private readonly CampaignManager $campaigns, private readonly ReauthenticationService $reauthentication)
    {
    }

    #[Route('', name: 'admin_campaigns', methods: ['GET', 'HEAD'])]
    public function index(): Response
    {
        $user = $this->user();

        return $this->render('admin/campaigns.html.twig', ['campaigns' => $this->campaigns->list($user), 'options' => $this->isGranted('ROLE_OPERATOR') ? $this->campaigns->options($user) : ['templates' => [], 'lists' => []]]);
    }

    #[Route('/create', name: 'admin_campaign_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->validCsrf($request, 'campaign-create')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $lists = $request->request->all('lists');
            $id = $this->campaigns->create($this->user(), $request->request->getString('name'), $request->request->getString('template_version'), array_values(array_filter($lists, '\is_string')));
            $this->addFlash('success', 'Campaign draft created.');

            return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (\Throwable) {
            $this->addFlash('error', 'Campaign creation was not accepted.');

            return $this->redirectToRoute('admin_campaigns', status: Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/{id}', name: 'admin_campaign', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function detail(string $id): Response
    {
        try {
            return $this->render('admin/campaign.html.twig', ['campaign' => $this->campaigns->detail($this->user(), $id)]);
        } catch (\Throwable) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/{id}/review', name: 'admin_campaign_review', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function review(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-review', fn () => $this->campaigns->submitForReview($this->user(), $id), 'Campaign submitted for review.');
    }

    #[Route('/{id}/test', name: 'admin_campaign_test', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function test(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-test', fn () => $this->campaigns->requestTest($this->user(), $id), 'Campaign test queued for the signed-in administrator.');
    }

    #[Route('/{id}/approve', name: 'admin_campaign_approve', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function approve(Request $request, string $id): Response
    {
        if (!$this->validCsrf($request, 'campaign-approve-'.$id)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $this->reauthentication->assertRecent($request->getSession(), 'campaign_approval');

        return $this->mutate($request, $id, 'campaign-approve', fn () => $this->campaigns->approve($this->user(), $id, $request->request->getBoolean('waive_test')), 'Campaign approved with immutable snapshots.');
    }

    #[Route('/{id}/schedule', name: 'admin_campaign_schedule', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function schedule(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-schedule', fn () => $this->campaigns->schedule($this->user(), $id, new \DateTimeImmutable($request->request->getString('scheduled_at'))), 'Campaign scheduled.');
    }

    #[Route('/{id}/queue', name: 'admin_campaign_queue', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function queue(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-queue', fn () => $this->campaigns->queue($this->user(), $id), 'Campaign delivery queued.');
    }

    #[Route('/{id}/pause', name: 'admin_campaign_pause', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function pause(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-pause', fn () => $this->campaigns->pause($this->user(), $id), 'Campaign paused.');
    }

    #[Route('/{id}/resume', name: 'admin_campaign_resume', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function resume(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-resume', fn () => $this->campaigns->resume($this->user(), $id), 'Campaign resumed.');
    }

    #[Route('/{id}/cancel', name: 'admin_campaign_cancel', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function cancel(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-cancel', fn () => $this->campaigns->cancel($this->user(), $id), 'Campaign cancelled.');
    }

    private function mutate(Request $request, string $id, string $token, callable $operation, string $success): Response
    {
        if (!$this->validCsrf($request, $token.'-'.$id)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $operation();
            $this->addFlash('success', $success);
        } catch (\Throwable) {
            $this->addFlash('error', 'Campaign operation was not accepted.');
        }

        return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function validCsrf(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, $request->request->getString('_token'));
    }

    private function user(): AuthenticatedUser
    {
        $user = $this->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
