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

        return $this->render('admin/campaigns.html.twig', ['selected_list' => $selectedList, 'selected_template' => $selectedTemplate, 'campaigns' => $this->campaigns->list($user), 'options' => $options, 'limits' => $this->campaigns->deliveryLimits(), 'form_values' => $request->isMethod('POST') ? $this->retainedValues($request) : []]);
    }

    #[Route('/guide', name: 'admin_campaign_guide', methods: ['GET', 'HEAD'])]
    public function guide(): Response
    {
        return $this->render('admin/campaign-guide.html.twig');
    }

    #[Route('/create', name: 'admin_campaign_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->validCsrf($request, 'campaign-create')) {
            return $this->draftError($request, 'The security check expired. Your entries and selected CSV are still here. Sign in again in another tab, then choose Refresh security check here before retrying.', Response::HTTP_BAD_REQUEST, null);
        }

        return $this->saveDraft($request, null);
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

            return $this->render('admin/campaign.html.twig', ['campaign' => $campaign, 'confirmation_expires_at' => $this->reauthentication->expiresAt($request->getSession(), 'campaign_approval')]);
        } catch (\Throwable) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/{id}/progress', name: 'admin_campaign_progress', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD'])]
    public function progress(string $id): Response
    {
        try {
            return $this->json($this->campaigns->progress($this->user(), $id));
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
    }

    #[Route('/{id}/edit', name: 'admin_campaign_edit', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(Request $request, string $id): Response
    {
        try {
            $campaign = $this->campaigns->detail($this->user(), $id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }
        if (!\in_array($campaign['state'], ['draft', 'review'], true)) {
            $this->addFlash('error', 'This newsletter is approved. Its content and recipients are locked.');

            return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
        }
        if ($request->isMethod('POST')) {
            if (!$this->validCsrf($request, 'campaign-edit-'.$id)) {
                return $this->draftError($request, 'The security check expired. Your entries and selected CSV are still here. Sign in again in another tab, then choose Refresh security check here before retrying.', Response::HTTP_BAD_REQUEST, $id);
            }

            return $this->saveDraft($request, $id);
        }
        $options = $this->campaigns->options($this->user());
        $options['templates'] = $this->templates->campaignOptions($this->user());

        return $this->render('admin/campaign-edit.html.twig', ['campaign' => $campaign, 'options' => $options, 'form_values' => null]);
    }

    private function saveDraft(Request $request, ?string $id): Response
    {
        try {
            if (null !== $id && null !== $this->pendingImport($request, $id)) {
                throw new \DomainException('Complete the existing recipient import before editing this campaign.');
            }
            $file = $request->files->get('recipient_csv');
            if ($file instanceof UploadedFile && !$file->isValid()) {
                throw new \InvalidArgumentException('Recipient CSV upload is invalid.');
            }
            $csvPath = $file instanceof UploadedFile ? $file->getPathname() : null;
            $campaignOwned = null === $id ? 'upload' === $request->request->getString('audience_mode', 'upload') : 'campaign_owned' === $this->campaigns->detail($this->user(), $id)['audience_model'];
            $listIds = $campaignOwned ? [] : array_values(array_filter($request->request->all('lists'), \is_string(...)));
            if (!$campaignOwned && null !== $csvPath) {
                throw new \InvalidArgumentException('Choose campaign CSV recipients or reusable lists, not both.');
            }
            $mapping = [];
            foreach (['email', 'display_name', 'first_name', 'last_name', 'locale', 'time_zone', 'consent_source'] as $field) {
                $column = $request->request->getString('column_'.$field);
                if ('' !== $column) {
                    if (!ctype_digit($column) || (int) $column < 1 || (int) $column > 32) {
                        throw new \InvalidArgumentException('CSV column numbers must be between 1 and 32.');
                    }
                    $mapping[$field] = (int) $column - 1;
                }
            }
            $result = $this->campaigns->saveDraft($this->user(), $id, $request->request->getString('name'), $request->request->getString('template_version'), $this->rate($request), $csvPath, $listIds, $campaignOwned, $request->request->getString('csv_mode', 'headered'), $mapping);
            $saved = $result['id'];
            $message = 'Campaign draft saved. Nothing was published, approved or sent.';
            if (null !== $result['csv_counts']) {
                $counts = $result['csv_counts'];
                $message .= \sprintf(' CSV: %d unique recipients saved; %d duplicate rows; %d invalid rows left out; %d currently suppressed. Review the recipient count before approval and replace the CSV if corrections are needed.', $counts['imported'], $counts['duplicate'], $counts['invalid'], $counts['suppressed']);
            }
            $this->addFlash('success', $message);
            $redirect = 'templates' === $request->request->getString('_destination')
                ? $this->generateUrl('admin_templates', ['return_campaign' => $saved])
                : $this->generateUrl('admin_campaign_edit', ['id' => $saved]);
            if (\in_array('application/json', $request->getAcceptableContentTypes(), true)) {
                return $this->json(['saved' => true, 'id' => $saved, 'redirect' => $redirect, 'campaign_url' => $this->generateUrl('admin_campaign_edit', ['id' => $saved])]);
            }

            return $this->redirect($redirect, Response::HTTP_SEE_OTHER);
        } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            return $this->draftError($request, CampaignDraftMessages::save($failure), $failure instanceof \InvalidArgumentException || $failure instanceof \DomainException || $failure instanceof \Symfony\Component\HttpFoundation\Exception\BadRequestException ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_SERVICE_UNAVAILABLE, $id);
        }
    }

    private function draftError(Request $request, string $message, int $status, ?string $id): Response
    {
        if (\in_array('application/json', $request->getAcceptableContentTypes(), true)) {
            return $this->json(['saved' => false, 'errors' => [$message]], $status);
        }
        $this->addFlash('error', $message.' On the server form, select the CSV file again before retrying. The live editor retains the selected file automatically.');
        if (null === $id) {
            return $this->index($request)->setStatusCode($status);
        }
        $options = $this->campaigns->options($this->user());
        $options['templates'] = $this->templates->campaignOptions($this->user());

        return $this->render('admin/campaign-edit.html.twig', ['campaign' => $this->campaigns->detail($this->user(), $id), 'options' => $options, 'form_values' => $this->retainedValues($request)], new Response(status: $status));
    }

    #[Route('/{id}/rate', name: 'admin_campaign_rate', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function configureRate(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-rate', fn () => $this->campaigns->configureRate($this->user(), $id, $this->rate($request)), 'Sending rate saved. Installation and destination-domain limits still apply.');
    }

    /** @return array<string, string|list<string>> */
    private function retainedValues(Request $request): array
    {
        // Keep newsletter text, never authentication fields or arbitrary nested input.
        $input = $request->request->all();
        $values = [];
        foreach (['name', 'template_version', 'messages_per_minute', 'audience_mode', 'csv_mode', 'column_email', 'column_display_name', 'column_first_name', 'column_last_name', 'column_locale', 'column_time_zone', 'column_consent_source'] as $field) {
            $values[$field] = isset($input[$field]) && \is_string($input[$field]) ? $input[$field] : '';
        }
        $values['lists'] = isset($input['lists']) && \is_array($input['lists']) ? array_values(array_filter($input['lists'], \is_string(...))) : [];

        return $values;
    }

    private function rate(Request $request): ?int
    {
        $value = $request->request->getString('messages_per_minute');
        if ('' === $value) {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('Sending rate must be a whole number.');
        }

        return (int) $value;
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
        if (!$this->reauthentication->isRecent($request->getSession(), 'campaign_approval')
            && ('' === $request->request->getString('owner_password') || !$this->reauthentication->confirm($request->getSession(), $this->user(), $request->request->getString('owner_password'), $request->request->getString('owner_mfa_code'), 'campaign_approval', $request->getClientIp(), $request->headers->get('User-Agent')))
        ) {
            $this->addFlash('error', 'Confirm your current password and authenticator or recovery code to approve this newsletter.');

            return $this->redirectToRoute('admin_campaign', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        return $this->mutate($request, $id, 'campaign-approve', fn () => $this->campaigns->approve($this->user(), $id, $request->request->getInt('reviewed_revision'), $request->request->getBoolean('waive_test')), 'Campaign approved with immutable snapshots.');
    }

    #[Route('/{id}/schedule', name: 'admin_campaign_schedule', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function schedule(Request $request, string $id): Response
    {
        return $this->mutate($request, $id, 'campaign-schedule', fn () => $this->campaigns->schedule($this->user(), $id, $this->campaigns->scheduleTime($request->request->getString('scheduled_at'))), 'Campaign scheduled.');
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
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', match ($token) {
                'campaign-approve' => 'Approval was not accepted. Refresh the review if this draft changed. Send an accepted test of the current draft, check its audience size, and confirm its RSVP setting matches the template.',
                'campaign-test', 'campaign-review' => 'Complete any pending recipient import first, then try again while this newsletter is a draft or in review.',
                'campaign-queue', 'campaign-resume' => 'Delivery could not start. Check Doctor for blocking findings and confirm the newsletter is approved or paused.',
                'campaign-rate' => 'Pause delivery before changing the rate. Use 1–600 messages per minute or leave blank for the installation limit.',
                'campaign-schedule' => 'Choose a future date and time in the displayed time zone. The newsletter must be approved and Doctor must be ready.',
                default => 'This action is unavailable in the current campaign state. Refresh the newsletter and try again.',
            });
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
