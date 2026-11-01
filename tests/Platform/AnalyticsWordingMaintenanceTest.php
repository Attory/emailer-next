<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class AnalyticsWordingMaintenanceTest extends TestCase
{
    public function testRenderedAnalyticsUsesCampaignHeadingWithoutIntroductoryPrivacyParagraph(): void
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.html.twig' => '{% block admin_body %}{% endblock %}']),
            new FilesystemLoader(\dirname(__DIR__, 2).'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => false));
        $twig->addFunction(new TwigFunction('path', static fn (string $name): string => '/not-dispatched'));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $name): string => 'synthetic-unused'));
        $html = $twig->render('admin/analytics.html.twig', ['campaigns' => []]);
        self::assertStringContainsString('<h1>Campaign analytics</h1>', $html);
        self::assertStringNotContainsString('Privacy-minimized analytics', $html);
        self::assertStringNotContainsString('Open and click figures are approximate.', $html);
        self::assertStringNotContainsString('Email addresses, IP addresses, user agents, and raw destination URLs are not stored in analytics records.', $html);
        self::assertStringContainsString('Approximate opens', $html);
        self::assertStringContainsString('Approximate clicks', $html);
    }
}
