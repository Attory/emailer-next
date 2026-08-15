<?php

declare(strict_types=1);

namespace App\Module\Setup\Presentation\Http;

use App\Module\Setup\Application\ActiveChallengeExists;
use App\Module\Setup\Application\ChallengeIssuer;
use App\Module\Setup\Application\Contract\Preflight;
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
    private const SESSION_PROOF_VERIFIED = 'setup.proof_verified';

    public function __construct(
        private readonly ChallengeIssuer $challengeIssuer,
        private readonly ProofVerifier $proofVerifier,
        private readonly Preflight $preflight,
        private readonly LoggerInterface $logger,
        #[Target('setup_challenge')]
        private readonly RateLimiterFactoryInterface $challengeLimiter,
        #[Target('setup_proof')]
        private readonly RateLimiterFactoryInterface $proofLimiter,
        private readonly string $proofPath,
    ) {
    }

    #[Route('/setup', name: 'setup_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $clientKey = $request->getClientIp() ?? 'unknown';

        if (!$this->challengeLimiter->create($clientKey)->consume()->isAccepted()) {
            return $this->noStore(new Response(
                'Setup challenge issuance is temporarily limited.',
                Response::HTTP_TOO_MANY_REQUESTS,
            ));
        }

        try {
            $challenge = $this->challengeIssuer->issue($clientKey);
        } catch (ActiveChallengeExists $exception) {
            $sessionIdentifier = $request->getSession()->get(self::SESSION_CHALLENGE_ID);

            if (!\is_string($sessionIdentifier) || !hash_equals($exception->identifier, $sessionIdentifier)) {
                $request->getSession()->remove(self::SESSION_CHALLENGE_ID);
            }

            return $this->noStore($this->render(
                'setup/challenge-active.html.twig',
                response: new Response(status: Response::HTTP_CONFLICT),
            ));
        }

        $request->getSession()->set(self::SESSION_CHALLENGE_ID, $challenge->identifier);
        $request->getSession()->remove(self::SESSION_PROOF_VERIFIED);

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
        $result = \is_string($identifier)
            ? $this->proofVerifier->verify($identifier, $clientKey)
            : ProofVerificationResult::InvalidAuthority;

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
        if (true !== $request->getSession()->get(self::SESSION_PROOF_VERIFIED)) {
            return new RedirectResponse($this->generateUrl('setup_index'), Response::HTTP_SEE_OTHER);
        }

        if (!$request->isSecure()) {
            return $this->noStore($this->render(
                'setup/https-required.html.twig',
                response: new Response(status: Response::HTTP_FORBIDDEN),
            ));
        }

        $documentRoot = $request->server->get('DOCUMENT_ROOT');
        $checks = $this->preflight->run(\is_string($documentRoot) ? $documentRoot : null);

        return $this->noStore($this->render('setup/preflight.html.twig', [
            'checks' => $checks,
        ]));
    }

    private function genericProofFailure(int $status): Response
    {
        return $this->noStore($this->render(
            'setup/proof-failed.html.twig',
            response: new Response(status: $status),
        ));
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
