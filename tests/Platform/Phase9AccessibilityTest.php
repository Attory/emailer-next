<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\TestCase;

final class Phase9AccessibilityTest extends TestCase
{
    public function testMvpTemplatesHaveReviewableSemanticAccessibilityBaseline(): void
    {
        $root = \dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || !str_ends_with($file->getFilename(), '.html.twig')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/<th\b([^>]*)>/i', $source, $headers);
            foreach ($headers[1] as $attributes) {
                self::assertMatchesRegularExpression('/\bscope=(?:"(?:col|row)"|\'(?:col|row)\')/i', $attributes, $file->getPathname().' has a table header without explicit scope.');
            }
            if (str_contains($source, 'type="file"')) {
                self::assertMatchesRegularExpression('/<label[^>]*>[^<]*(?:<[^>]+>[^<]*)*<input[^>]+type="file"/is', $source, $file->getPathname().' has an unlabelled file input.');
            }
            if (str_contains($source, '<html')) {
                self::assertMatchesRegularExpression('/<html\s+lang="[a-z]{2}(?:-[A-Z]{2})?"/i', $source);
                self::assertStringContainsString('name="viewport"', $source);
                if ('base.html.twig' !== $file->getFilename()) {
                    self::assertStringContainsString('<main', $source);
                }
            }
        }

        $campaign = (string) file_get_contents($root.'/templates/admin/campaign.html.twig');
        foreach (['Selected audience', 'Currently suppressed', 'Template', 'Sender', 'Time zone', 'Tracking', 'RSVP', 'Latest test'] as $requiredCue) {
            self::assertStringContainsString($requiredCue, $campaign);
        }

        $css = (string) file_get_contents($root.'/assets/styles/app.css');
        self::assertStringContainsString(':focus-visible', $css);
        self::assertStringContainsString('textarea:focus-visible', $css);
        self::assertStringContainsString('@media(max-width:600px)', $css);
        self::assertStringContainsString('overflow-x:auto', $css);
    }
}
