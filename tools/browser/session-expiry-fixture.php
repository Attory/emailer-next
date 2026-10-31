<?php

declare(strict_types=1);

// CLI-only control for the disposable browser database; excluded from packages.
require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    if ('test' !== getenv('APP_ENV') || 'https://emailer.example.test' !== getenv('DEFAULT_URI')) {
        throw new RuntimeException('Not the disposable profile.');
    }
    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $db = $container->get(Doctrine\DBAL\Connection::class);
    if ('emailer_browser_test' !== $db->getDatabase() || 'database' !== ($db->getParams()['host'] ?? null)) {
        throw new RuntimeException('Not the disposable database.');
    }
    $action = $argv[1] ?? '';
    if ('seed' === $action) {
        $credentials = json_decode(file_get_contents('/emailer-browser-secrets/browser-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
        $actor = $container->get(App\Module\Identity\Infrastructure\DoctrineUserProvider::class)->loadUserByIdentifier($credentials['email']);
        $draft = $container->get(App\Module\Campaigns\Application\CampaignManager::class)->saveDraft($actor, null, '[TEST] Session recovery', '', 5, campaignOwned: true);
        echo json_encode(['campaign' => $draft['id']], JSON_THROW_ON_ERROR);
    } elseif ('idle' === $action) {
        $db->executeStatement('UPDATE user_session SET idle_expires_at = UTC_TIMESTAMP(6) WHERE user_id IS NOT NULL AND revoked_at IS NULL');
    } elseif ('absolute' === $action) {
        $db->executeStatement('UPDATE user_session SET absolute_expires_at = UTC_TIMESTAMP(6) WHERE user_id IS NOT NULL AND revoked_at IS NULL');
    } else {
        throw new RuntimeException('Unsupported fixture action.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Disposable session fixture failed.\n");
    exit(1);
}
