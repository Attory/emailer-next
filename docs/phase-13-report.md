<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Presentation\Http;

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Contacts\Application\Contract\CampaignAudiencePreparation;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\ReauthenticationService;
use App\Module\Templates\Application\Contract\CampaignTemplatePreparation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/campaigns')]
final class CampaignsController extends AbstractController
{
    public function __construct(private readonly CampaignManager $campaigns, private readonly ReauthenticationService $reauthentication, private readonly CampaignAudiencePreparation $uploads, private readonly CampaignTemplatePreparation $templates)
    {
    }

    #[Route('', name: 'admin_campaigns', methods: ['GET', 'HEAD'])]
    public function index(Request $request): Response
    {
        $user = $this->user();

        $selectedList = $request->query->getString('list');
        $selectedTemplate = $request->query->getString('template');
        $options = $this->isGranted('ROLE_OPERATOR') ? $this->campaigns->options($user) : ['templates' => [], 'lists' => []];
        if ($this->isGranted('ROLE_OPERATOR')) {
            $options['templates'] = $this->templates->campaignOptions($user);
        }
        foreach (['list' => [$selectedList, $options['lists']], 'template' => [$selectedTemplate, $options['templates']]] as [$selected, $rows]) {
            if ('' !== $selected && !\in_array($selected, array_column($rows, 'id'), true)) {
                return new Response('Selection is unavailable.', Response::HTTP_BAD_REQUEST);
            }
        }

        return $this->render('admin/campaigns.html.twig', ['selected_list' => $selectedList, 'selected_template' => $selectedTemplate, 'campaigns' => $this->campaigns->list($user), 'options' => $options]);
    }

    #[Route('/create', name: 'admin_campaign_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->validCsrf($request, 'campaign-create')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $actor = $this->user();
            $content = null;
            $audience = null;
            $bag = $request->getSession()->get('campaign.pending_imports', []);
            $bag = \is_array($bag) ? $bag : [];
            if ('upload' === $request->request->getString('audience_mode', 'existing') && \count($bag) >= 100) {
                foreach ($bag as $campaignId => $importId) {
                    try {
                        if (!\is_string($importId) || !\in_array($this->uploads->importSummary($actor, $importId)['status'], ['uploaded', 'previewed'], true)) {
                            unset($bag[$campaignId]);
                        }
                    } catch (\InvalidArgumentException) {
                        unset($bag[$campaignId]);
                    }
                }
                $request->getSession()->set('campaign.pending_imports', $bag);
            }
            if ('upload' === $request->request->getString('audience_mode', 'existing') && \count($bag) >= 100) {
                $this->addFlash('error', 'Complete pending uploads in Imports before creating another uploaded-list draft. Existing-list drafts remain available.');

                return $this->redirectToRoute('admin_campaigns', status: Response::HTTP_SEE_OTHER);
            }
            $templateVersion = $request->request->getString('template_version');
            if ('upload' === $request->request->getString('template_mode', 'existing')) {
                $file = $request->files->get('template_file');
                if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > 200000) {
                    throw new \InvalidArgumentException('Template upload is invalid.');
                }
                $html = file_get_contents($file->getPathname());
                if (!\is_string($html) || !mb_check_encoding($html, 'UTF-8')) {
                    throw new \InvalidArgumentException('Template upload is invalid.');
                }
                $content = ['name' => $request->request->getString('template_name'), 'subject' => $request->request->getString('subject'), 'preheader' => $request->request->getString('preheader'), 'html' => $html];
            }
            $listIds = [];
            if ('upload' === $request->request->getString('audience_mode', 'existing')) {
                $file = $request->files->get('audience_file');
                if (!$file instanceof UploadedFile || !$file->isValid()) {
                    throw new \InvalidArgumentException('Audience upload is invalid.');
                }
                $mapping = [];
                foreach (['email', 'display_name', 'first_name', 'last_name', 'locale', 'time_zone', 'consent_source'] as $field) {
                    $column = $request->request->getString('column_'.$field);
                    if (ctype_digit($column)) {
                        $mapping[$field] = (int) $column;
                    }
                }
                $audience = ['name' => $request->request->getString('list_name'), 'source' => $file->getPathname(), 'mode' => $request->request->getString('csv_mode', 'headered'), 'mapping' => $mapping];
            } else {
                $listIds = array_values(array_filter($request->request->all('lists'), '\is_string'));
            }
            $prepared = $this->campaigns->prepareDraft($actor, $request->request->getString('name'), $templateVersion, $listIds, $content, $audience);
            $id = $prepared['id'];
            if (null !== $prepared['import_id']) {
                $bag[$id] = $prepared['import_id'];
                $request->getSession()->set('campaign.pending_imports', $bag);
                $this->addFlash('success', 'Campaign draft created. Review uploaded recipient counts before committing the import.');
            } else {
                $this->addFlash('success', 'Campaign draft created. No email has been sent.');
            }

            return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
        } catch (\Throwable) {
            $this->addFlash('error', 'Draft creation was not accepted. Check the selected lists and template, unique upload names, and CSV column mapping. No campaign or uploaded list/template was saved.');

            return $this->redirectToRoute('admin_campaigns', status: Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/{id}/import/commit', name: 'admin_campaign_import_commit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function commitImport(Request $request, string $id): Response
    {
        if (!$this->validCsrf($request, 'campaign-import-'.$id)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $bag = $request->getSession()->get('campaign.pending_imports', []);
        $importId = \is_array($bag) ? ($bag[$id] ?? null) : null;
        if (!\is_string($importId)) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->campaigns->commitAudienceUpload($this->user(), $id, $importId);
            unset($bag[$id]);
            $request->getSession()->set('campaign.pending_imports', $bag);
            $this->addFlash('success', 'Reviewed recipients imported. Global unsubscribe protections were checked again.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Recipient import was not accepted. Review the private import status before proceeding.');
        }

        return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'admin_campaign', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function detail(Request $request, string $id): Response
    {
        try {
            $campaign = $this->campaigns->detail($this->user(), $id);
            $campaign['pending_import'] = $this->pendingImport($request, $id);

            return $this->render('admin/campaign.html.twig', ['campaign' => $campaign]);
        } catch (\Throwable) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/{id}/review', name: 'admin_campaign_review', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function review(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-review', fn () => $this->campaigns->submitForReview($this->user(), $id), 'Campaign submitted for review.');
    }

    #[Route('/{id}/rsvp', name: 'admin_campaign_rsvp', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function rsvp(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-rsvp', fn () => $this->campaigns->configureRsvp($this->user(), $id, $request->request->getBoolean('enabled')), 'Campaign RSVP setting updated.');
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
            if (\in_array($token, ['campaign-review', 'campaign-test'], true)) {
                if (null !== $this->pendingImport($request, $id)) {
                    throw new \DomainException('Review and commit the uploaded recipients first.');
                }
            }
            $operation();
            $this->addFlash('success', $success);
        } catch (\Throwable) {
            $this->addFlash('error', 'Campaign operation was not accepted.');
        }

        return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** @return array<string, mixed>|null */
    private function pendingImport(Request $request, string $id): ?array
    {
        $bag = $request->getSession()->get('campaign.pending_imports', []);
        $importId = \is_array($bag) ? ($bag[$id] ?? null) : null;
        if (!$this->isGranted('ROLE_OPERATOR') || !\is_string($importId)) {
            return null;
        }
        try {
            $summary = $this->uploads->importSummary($this->user(), $importId);
            if (\in_array($summary['status'], ['uploaded', 'previewed'], true)) {
                return $summary;
            }
        } catch (\InvalidArgumentException) {
            $this->addFlash('error', 'The uploaded-recipient preview expired or is unavailable. Review this draft’s list and use Imports to upload recipients again.');
        }
        unset($bag[$id]);
        $request->getSession()->set('campaign.pending_imports', $bag);

        return null;
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
