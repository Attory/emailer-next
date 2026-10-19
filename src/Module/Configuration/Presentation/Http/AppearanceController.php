<?php

declare(strict_types=1);

namespace App\Module\Configuration\Presentation\Http;

use App\Module\Configuration\Application\AppearanceSettings;
use App\Module\Configuration\Application\WebsiteBranding;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/admin/appearance')]
final class AppearanceController extends AbstractController
{
    public function __construct(private readonly AppearanceSettings $settings)
    {
    }

    #[Route('', name: 'admin_appearance', methods: ['GET', 'HEAD', 'POST'])]
    public function preference(Request $request): Response
    {
        if ($request->isMethodSafe()) {
            return $this->json($this->settings->view($this->actor()));
        }

        return $this->change($request, function (array $data): array {
            return $this->settings->save($this->actor(), $this->string($data, 'skin'), $this->string($data, 'motion'));
        });
    }

    #[Route('/branding', name: 'admin_appearance_branding', methods: ['POST'])]
    public function branding(Request $request): Response
    {
        return $this->change($request, function (array $data): array {
            $tokens = $data['tokens'] ?? [];
            if (!\is_array($tokens)) {
                throw new \InvalidArgumentException('Choose the website colours using the displayed fields.');
            }

            return $this->settings->changeBranding($this->actor(), $this->string($data, 'action'), $this->string($data, 'website_url'), $tokens);
        });
    }

    #[Route('/logo.png', name: 'admin_appearance_logo', methods: ['GET', 'HEAD'])]
    public function logo(): Response
    {
        $this->settings->view($this->actor());
        $bytes = $this->settings->logo();

        return null === $bytes ? new Response('', 404) : new Response($bytes, headers: ['Content-Type' => 'image/png']);
    }

    #[Route('/tokens.css', name: 'admin_appearance_tokens', methods: ['GET', 'HEAD'])]
    public function tokens(): Response
    {
        $tokens = $this->settings->view($this->actor())['branding']['tokens'];
        $css = 'body[data-skin="website"]{';
        foreach (WebsiteBranding::defaults() as $name => $default) {
            $value = $tokens[$name];
            if ('font' === $name) {
                $value = 'serif' === $value ? '"Iowan Old Style","Palatino Linotype",Palatino,Georgia,"Times New Roman",serif' : 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
            }
            $css .= '--website-'.$name.':'.$value.';';
        }

        foreach (WebsiteBranding::statusColours($tokens) as $name => $colour) {
            $css .= '--website-'.$name.':'.$colour.';';
        }
        $css .= '--website-on-primary:'.(WebsiteBranding::contrast($tokens['primary'], '#ffffff') >= WebsiteBranding::contrast($tokens['primary'], '#111111') ? '#ffffff' : '#111111').';';
        $css .= '--website-link:'.(min(WebsiteBranding::contrast($tokens['primary'], $tokens['surface']), WebsiteBranding::contrast($tokens['primary'], $tokens['background'])) >= 4.5 ? $tokens['primary'] : $tokens['text']).';';
        $css .= '--website-scheme:'.(5.0 > WebsiteBranding::contrast($tokens['background'], '#000000') ? 'dark' : 'light').';';

        return new Response($css.'}', headers: ['Content-Type' => 'text/css; charset=UTF-8']);
    }

    /** @param \Closure(array<mixed>): array<mixed> $operation */
    private function change(Request $request, \Closure $operation): JsonResponse
    {
        if (32768 < \strlen($request->getContent())) {
            return $this->json(['error' => 'Appearance settings are too large.'], 400);
        }
        try {
            $data = $request->toArray();
            if (!$this->isCsrfTokenValid('admin-appearance', $this->string($data, '_token'))) {
                return $this->json(['error' => 'Your session could not confirm this change. Reload after saving your work and try again.'], 400);
            }

            return $this->json($operation($data));
        } catch (AccessDeniedException) {
            return $this->json(['error' => 'Only an Administrator or Owner can change the shared website branding.'], 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 400);
        } catch (\Throwable) {
            return $this->json(['error' => 'Appearance could not be updated. Your saved skin is available; check the website address or private storage and try again.'], 503);
        }
    }

    /** @param array<mixed> $data */
    private function string(array $data, string $name): string
    {
        $value = $data[$name] ?? '';
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Use the displayed appearance fields.');
        }

        return $value;
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
