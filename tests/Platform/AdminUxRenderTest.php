<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\RoutePolicyRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class AdminUxRenderTest extends TestCase
{
    public function testListsDialogAndMenuRenderRealTemplatesWithoutRedundantNavigation(): void
    {
        $root = \dirname(__DIR__, 2);
        $twig = new Environment(new FilesystemLoader($root.'/templates'), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/assets/'.$path));
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => '/'.$route.'/'.implode('/', array_map(static fn (mixed $v): string => \is_string($v) ? $v : '', $parameters))));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $purpose): string => 'synthetic-unused'));
        $twig->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => true));
        $twig->addFunction(new TwigFunction('security_requires_recent', static fn (): bool => false));
        $app = new class {
            public Request $request;

            public function __construct()
            {
                $this->request = Request::create('/admin/lists');
            }

            /** @return list<string> */
            public function flashes(string $type): array
            {
                return [];
            }
        };
        $context = ['app' => $app, 'ripe_source_url' => 'https://source.example.test', 'lists' => [
            ['id' => '01900000-0000-7000-8000-000000000001', 'name' => 'Synthetic A', 'member_count' => 2],
            ['id' => '01900000-0000-7000-8000-000000000002', 'name' => 'Synthetic B', 'member_count' => 2],
        ]];
        $html = $twig->render('admin/lists.html.twig', $context);
        $test = $twig->render('admin/_campaign-test.html.twig', ['update_delivery' => ['blocked' => false], 'campaign' => ['id' => '01900000-0000-7000-8000-000000000003', 'test_actor_id' => '01900000-0000-7000-8000-000000000005', 'test_request_id' => '01900000-0000-7000-8000-000000000004', 'own_test' => null, 'test_destination' => 'requester@example.test', 'can_test' => true]]);
        self::assertStringContainsString('Test destination:', $test);
        self::assertStringContainsString('requester@example.test', $test);
        self::assertStringContainsString('name="request_id"', $test);
        self::assertStringContainsString('method="post"', $test);
        self::assertStringContainsString('synthetic-unused', $test);
        $html = str_replace('</main>', $test.'</main>', $html);
        self::assertStringContainsString('data-list-create-open', $html);
        self::assertStringContainsString('data-tools-menu', $html);
        self::assertStringContainsString('name="source_lists[]" multiple', $html);
        self::assertStringContainsString('Ctrl-click', $html);
        self::assertStringContainsString('Cmd-click', $html);
        self::assertStringContainsString('data-source-choice', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringNotContainsString('>View</a>', $html);
        self::assertStringNotContainsString('>Edit</a>', $html);
        self::assertStringNotContainsString('>Create list</button>', $html);
        self::assertStringContainsString('>Send to</a>', $html);
        self::assertStringContainsString('Download CSV', $html);
        $path = getenv('EMAILER_ADMIN_UX_HTML');
        if (\is_string($path) && '' !== $path) {
            file_put_contents($path, $html);
        }
        $policy = (new RoutePolicyRegistry())->policy('admin_campaign_test_result');
        self::assertNotNull($policy);
        self::assertSame(AdministrativeRole::Operator, $policy['role']);
        self::assertSame(['GET', 'HEAD'], $policy['methods']);
        $postPolicy = (new RoutePolicyRegistry())->policy('admin_campaign_test');
        self::assertNotNull($postPolicy);
        self::assertSame(['POST'], $postPolicy['methods']);
    }
}
