<?php

declare(strict_types=1);

namespace App\Module\Setup\Presentation\Http;

use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Identity\Application\Contract\OwnerSetup;
use App\Module\Setup\Application\ActiveChallengeExists;
use App\Module\Setup\Application\ChallengeIssuer;
use App\Module\Setup\Application\Contract\InstallationFinalizer;
use App\Module\Setup\Application\Contract\Preflight;
use App\Module\Setup\Application\Contract\SetupState;
use App\Module\Setup\Application\DatabaseInput;
use App\Module\Setup\Application\DatabaseSetup;
use App\Module\Setup\Application\ProofVerificationResult;
use App\Module\Setup\Application\ProofVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SetupController extends AbstractController
{
    private const SESSION_CHALLENGE_ID = 'setup.challenge_id';
    private const SESSION_OWNER_ID = 'setup.owner_id';
    private const SESSION_PROOF_VERIFIED = 'setup.proof_verified';

    public function __construct(
        private readonly ChallengeIssuer $challengeIssuer,
        private readonly ProofVerifier $proofVerifier,
        private readonly Preflight $preflight,
        private readonly DatabaseSetup $databaseSetup,
        private readonly SetupState $setupState,
        private readonly ConfigurationSetup $configurationSetup,
        private readonly OwnerSetup $ownerSetup,
        private readonly InstallationFinalizer $finalizer,
        private readonly LoggerInterface $logger,
        #[Target('setup_challenge')]
        private readonly RateLimiterFactoryInterface $challengeLimiter,
        #[Target('setup_proof')]
        private readonly RateLimiterFactoryInterface $proofLimiter,
        #[Target('setup_wizard')]
        private readonly RateLimiterFactoryInterface $wizardLimiter,
        private readonly string $proofPath,
    ) {
    }

    #[Route('/setup', name: 'setup_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $clientKey = $request->getClientIp() ?? 'unknown';
        if (!$this->challengeLimiter->create($clientKey)->consume()->isAccepted()) {
            return $this->noStore(new Response('Setup challenge issuance is temporarily limited.', Response::HTTP_TOO_MANY_REQUESTS));
        }

        try {
            $challenge = $this->challengeIssuer->issue($clientKey);
        } catch (ActiveChallengeExists $exception) {
            $sessionIdentifier = $request->getSession()->get(self::SESSION_CHALLENGE_ID);
            if (!\is_string($sessionIdentifier) || !hash_equals($exception->identifier, $sessionIdentifier)) {
                $request->getSession()->remove(self::SESSION_CHALLENGE_ID);
            }

            return $this->noStore($this->render('setup/challenge-active.html.twig', response: new Response(status: Response::HTTP_CONFLICT)));
        }

        $request->getSession()->set(self::SESSION_CHALLENGE_ID, $challenge->identifier);
        $request->getSession()->remove(self::SESSION_PROOF_VERIFIED);
        $request->getSession()->remove(self::SESSION_OWNER_ID);

        return $this->noStore($this->render('setup/index.html.twig', [
            'challenge_code' => $challenge->displayCode,
            'expires_at' => $challenge->expiresAt,
            'proof_path' => $this->proofPath,
        ]));
    }

    #[Route('/setup/proof/verify', name: 'setup_proof_verify', methods: ['POST'])]
    public function verify(Request $request): Response
    {
        $csrfToken = $request->request->get('_token');
        if (!\is_string($csrfToken) || !$this->isCsrfTokenValid('setup-proof', $csrfToken)) {
            return $this->genericProofFailure(Response::HTTP_BAD_REQUEST);
        }

        $clientKey = $request->getClientIp() ?? 'unknown';
        if (!$this->proofLimiter->create($clientKey)->consume()->isAccepted()) {
            return $this->genericProofFailure(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $identifier = $request->getSession()->get(self::SESSION_CHALLENGE_ID);
        $result = \is_string($identifier) ? $this->proofVerifier->verify($identifier, $clientKey) : ProofVerificationResult::InvalidAuthority;
        $this->logger->info('Setup ownership proof verification completed.', [
            'challenge_id' => \is_string($identifier) ? $identifier : 'missing',
            'result' => $result->value,
        ]);

        if (ProofVerificationResult::Success !== $result) {
            if (!$result->isRetryable()) {
                $request->getSession()->remove(self::SESSION_CHALLENGE_ID);
            }

            return $this->genericProofFailure(Response::HTTP_BAD_REQUEST);
        }

        $request->getSession()->remove(self::SESSION_CHALLENGE_ID);
        $request->getSession()->set(self::SESSION_PROOF_VERIFIED, true);
        $request->getSession()->migrate(true);

        return new RedirectResponse($this->generateUrl('setup_preflight'), Response::HTTP_SEE_OTHER);
    }

    #[Route('/setup/preflight', name: 'setup_preflight', methods: ['GET'])]
    public function preflight(Request $request): Response
    {
        if (null !== $blocked = $this->authorizeWizard($request)) {
            return $blocked;
        }

        $documentRoot = $request->server->get('DOCUMENT_ROOT');
        $checks = $this->preflight->run(\is_string($documentRoot) ? $documentRoot : null);
        $ready = true;
        foreach ($checks as $check) {
            $ready = $ready && $check->passed;
        }

        return $this->noStore($this->render('setup/preflight.html.twig', ['checks' => $checks, 'ready' => $ready]));
    }

    #[Route('/setup/database', name: 'setup_database', methods: ['GET', 'POST'])]
    public function database(Request $request): Response
    {
        if (null !== $blocked = $this->authorizeWizard($request)) {
            return $blocked;
        }
        if ($request->isMethod('GET')) {
            return $this->noStore($this->render('setup/database.html.twig'));
        }
        if (!$this->validCsrf($request, 'setup-database')) {
            return $this->wizardFailure('Database setup was not accepted.');
        }

        try {
            $this->databaseSetup->configure(new DatabaseInput(
                $request->request->getString('host'),
                $request->request->getInt('port'),
                $request->request->getString('database'),
                $request->request->getString('username'),
                $request->request->getString('password'),
            ));
            $this->setupState->initializeKey();
        } catch (\Throwable) {
            return $this->wizardFailure('Database or installation-key setup failed safely. Re-enter the values after correcting the external runtime configuration.');
        }

        return $this->redirectToRoute('setup_organisation', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/setup/organisation', name: 'setup_organisation', methods: ['GET', 'POST'])]
    public function organisation(Request $request): Response
    {
        if (null !== $blocked = $this->authorizeWizard($request)) {
            return $blocked;
        }
        $progress = $this->setupState->progress();
        if (!$progress->keyReady || null === $progress->installationId) {
            return $this->redirectToRoute('setup_database', status: Response::HTTP_SEE_OTHER);
        }
        if ($request->isMethod('GET')) {
            return $this->noStore($this->render('setup/organisation.html.twig'));
        }
        if (!$this->validCsrf($request, 'setup-organisation')) {
            return $this->wizardFailure('Organisation setup was not accepted.');
        }

        try {
            $this->configurationSetup->saveOrganisation($progress->installationId, new OrganisationInput(
                $request->request->getString('display_name'),
                $request->request->getString('base_url'),
                $request->request->getString('locale'),
                $request->request->getString('time_zone'),
            ));
            $this->setupState->mark('organisation');
        } catch (\Throwable) {
            return $this->wizardFailure('Organisation configuration was not accepted.');
        }

        return $this->redirectToRoute('setup_owner', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/setup/owner', name: 'setup_owner', methods: ['GET', 'POST'])]
    public function owner(Request $request): Response
    {
        if (null !== $blocked = $this->authorizeWizard($request)) {
            return $blocked;
        }
        $progress = $this->setupState->progress();
        if (!$progress->organisationReady || null === $progress->installationId) {
            return $this->redirectToRoute('setup_organisation', status: Response::HTTP_SEE_OTHER);
        }
        if ($request->isMethod('GET')) {
            return $this->noStore($this->render('setup/owner.html.twig'));
        }
        if (!$this->validCsrf($request, 'setup-owner')) {
            return $this->wizardFailure('Owner setup was not accepted.');
        }

        try {
            if ('confirm_mfa' === $request->request->getString('action')) {
                $ownerId = $request->getSession()->get(self::SESSION_OWNER_ID);
                if (!\is_string($ownerId)) {
                    throw new \RuntimeException('Owner setup authority is unavailable.');
                }
                $this->ownerSetup->confirmMfa($ownerId, $progress->installationId, $request->request->getString('totp_code'), $request->request->getBoolean('recovery_saved'));
                $this->setupState->mark('owner');

                return $this->redirectToRoute('setup_mail', status: Response::HTTP_SEE_OTHER);
            }

            $password = $request->request->getString('password');
            if (!hash_equals($password, $request->request->getString('password_confirmation'))) {
                throw new \InvalidArgumentException('Passwords do not match.');
            }
            $ownerId = $this->ownerSetup->create($request->request->getString('login_identifier'), $password);
            $request->getSession()->set(self::SESSION_OWNER_ID, $ownerId);

            if ($request->request->getBoolean('enable_mfa')) {
                $enrollment = $this->ownerSetup->startMfa($ownerId, $progress->installationId);

                return $this->noStore($this->render('setup/mfa-enrollment.html.twig', [
                    'seed' => $enrollment->seed,
                    'recovery_codes' => $enrollment->recoveryCodes,
                    'provisioning_uri' => $enrollment->provisioningUri,
                ]));
            }

            $this->ownerSetup->skipMfa($ownerId);
            $this->setupState->mark('owner');
        } catch (\Throwable) {
            return $this->wizardFailure('Owner setup failed safely. Secret values were not retained for redisplay.');
        }

        return $this->redirectToRoute('setup_mail', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/setup/mail', name: 'setup_mail', methods: ['GET', 'POST'])]
    public function mail(Request $request): Response
    {
        if (null !== $blocked = $this->authorizeWizard($request)) {
            return $blocked;
        }
        $progress = $this->setupState->progress();
        if (!$progress->ownerReady || null === $progress->installationId) {
            return $this->redirectToRoute('setup_owner', status: Response::HTTP_SEE_OTHER);
        }
        if ($request->isMethod('GET')) {
            return $this->noStore($this->render('setup/mail.html.twig'));
        }
        if (!$this->validCsrf($request, 'setup-mail')) {
            return $this->wizardFailure('Mail setup was not accepted.');
        }

        try {
            $this->configurationSetup->testAndSaveMail($progress->installationId, new MailInput(
                $request->request->getString('host'),
                $request->request->getInt('port'),
                $request->request->getString('encryption'),
                $request->request->getString('username'),
                $request->request->getString('password'),
                $request->request->getString('sender_address'),
                $request->request->getString('sender_name'),
                $request->request->getString('reply_to'),
            ));
            $this->setupState->mark('mail');
        } catch (\Throwable) {
            return $this->wizardFailure('Mailpit connection or mail configuration failed safely. Re-enter any secret value.');
        }

        return $this->redirectToRoute('setup_policy', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/setup/policy', name: 'setup_policy', methods: ['GET', 'POST'])]
    public function policy(Request $request): Response
    {
        if (null !== $blocked = $this->authorizeWizard($request)) {
            return $blocked;
        }
        $progress = $this->setupState->progress();
        if (!$progress->mailReady) {
            return $this->redirectToRoute('setup_mail', status: Response::HTTP_SEE_OTHER);
        }
        if ($request->isMethod('GET')) {
            return $this->noStore($this->render('setup/policy.html.twig'));
        }
        if (!$this->validCsrf($request, 'setup-policy')) {
            return $this->wizardFailure('Policy setup was not accepted.');
        }

        try {
            $this->configurationSetup->savePolicies(new PolicyInput(
                $request->request->getInt('messages_per_minute'),
                $request->request->getInt('worker_concurrency'),
                $request->request->getInt('retry_limit'),
                $request->request->getInt('maximum_campaign_size'),
                $request->request->getInt('maximum_csv_bytes'),
                $request->request->getInt('maximum_csv_rows'),
                $request->request->getInt('operational_retention_days'),
                $request->request->getInt('delivery_retention_days'),
                $request->request->getString('unsubscribe_footer'),
                $request->request->getString('worker_mode'),
            ));
            $this->setupState->mark('policy');
            $this->setupState->mark('capability');
        } catch (\Throwable) {
            return $this->wizardFailure('Delivery or privacy policy was not accepted.');
        }

        return $this->noStore($this->render('setup/complete.html.twig'));
    }

    #[Route('/setup/complete', name: 'setup_complete', methods: ['POST'])]
    public function complete(Request $request): Response
    {
        if (null !== $blocked = $this->authorizeWizard($request)) {
            return $blocked;
        }
        if (!$this->validCsrf($request, 'setup-complete')) {
            return $this->wizardFailure('Installation completion was not accepted.');
        }

        try {
            $installationId = $this->finalizer->finalize();
        } catch (\Throwable) {
            return $this->wizardFailure('Critical finalization failed safely. The installation remains unavailable and may be resumed.');
        }

        $request->getSession()->clear();
        $request->getSession()->migrate(true);
        $this->logger->notice('Emailer Next installation completed.', ['installation_id' => $installationId]);

        return $this->noStore(new Response('Installation completed. Setup is permanently locked.', Response::HTTP_OK));
    }

    private function authorizeWizard(Request $request): ?Response
    {
        if (true !== $request->getSession()->get(self::SESSION_PROOF_VERIFIED)) {
            return new RedirectResponse($this->generateUrl('setup_index'), Response::HTTP_SEE_OTHER);
        }
        if (!$request->isSecure()) {
            return $this->noStore($this->render('setup/https-required.html.twig', response: new Response(status: Response::HTTP_FORBIDDEN)));
        }
        $clientKey = $request->getClientIp() ?? 'unknown';
        if (!$this->wizardLimiter->create($clientKey)->consume()->isAccepted()) {
            return $this->wizardFailure('Setup requests are temporarily limited.', Response::HTTP_TOO_MANY_REQUESTS);
        }

        return null;
    }

    private function validCsrf(Request $request, string $identifier): bool
    {
        $csrfToken = $request->request->get('_token');

        return \is_string($csrfToken) && $this->isCsrfTokenValid($identifier, $csrfToken);
    }

    private function genericProofFailure(int $status): Response
    {
        return $this->noStore($this->render('setup/proof-failed.html.twig', response: new Response(status: $status)));
    }

    private function wizardFailure(string $message, int $status = Response::HTTP_BAD_REQUEST): Response
    {
        return $this->noStore($this->render('setup/wizard-failed.html.twig', ['message' => $message], new Response(status: $status)));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
