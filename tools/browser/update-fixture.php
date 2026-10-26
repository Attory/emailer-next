<?php

declare(strict_types=1);

// Disposable test control, never installed or callable through HTTP.
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
    $input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $action = $input['action'] ?? '';
    $directory = '/emailer-browser-secrets';
    $credentials = json_decode(file_get_contents($directory.'/browser-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $provider = $container->get(App\Module\Identity\Infrastructure\DoctrineUserProvider::class);
    if ('seed' === $action) {
        $actor = $provider->loadUserByIdentifier($credentials['email']);
        $editor = json_decode(file_get_contents($directory.'/browser-result.json'), true, flags: JSON_THROW_ON_ERROR);
        $version = $db->fetchOne('SELECT published_version_id FROM email_template WHERE id=?', [$editor['templateId']]);
        if (!is_string($version)) {
            throw new RuntimeException('The separately verified test template is not published.');
        }
        $csv = $directory.'/maintenance-recipients.csv';
        file_put_contents($csv, "email,first_name\nupdate-one@example.test,One\nupdate-two@example.test,Two\n");
        chmod($csv, 0o600);
        $campaigns = $container->get(App\Module\Campaigns\Application\CampaignManager::class);
        $ids = [];
        foreach (['approved', 'sending', 'paused'] as $state) {
            $draft = $campaigns->saveDraft($actor, null, '[TEST] Update visibility '.$state, $version, 5, $csv, campaignOwned: true);
            $id = $draft['id'];
            $campaigns->submitForReview($actor, $id);
            $campaigns->approve($actor, $id, (int) $campaigns->detail($actor, $id)['operation_version'], true);
            if ('approved' !== $state) {
                $campaigns->queue($actor, $id);
            }
            if ('paused' === $state) {
                $campaigns->pause($actor, $id);
            }
            $ids[$state] = $id;
        }
        unlink($csv);
        $snapshot = ['ids' => $ids, 'attempts' => (int) $db->fetchOne('SELECT COUNT(*) FROM delivery_attempt'), 'tests' => (int) $db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery'), 'queue' => (int) $db->fetchOne('SELECT COUNT(*) FROM messenger_messages'), 'mail' => json_decode(file_get_contents('http://mailpit:8025/api/v1/messages'), true, flags: JSON_THROW_ON_ERROR)['total']];
        file_put_contents($directory.'/maintenance-baseline.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
        chmod($directory.'/maintenance-baseline.json', 0o600);
        echo json_encode(['ids' => $ids], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if ('role' === $action && in_array($input['role'] ?? '', ['ROLE_OWNER', 'ROLE_ADMINISTRATOR', 'ROLE_OPERATOR', 'ROLE_AUDITOR'], true)) {
        $db->update('app_user', ['role' => $input['role']], ['login_identifier' => $credentials['email']]);
        echo "Role fixture updated.\n";
        exit(0);
    }
    $file = '/emailer-browser-runtime/update-maintenance.json';
    $operation = 'synthetic-browser-update';
    require dirname(__DIR__, 2).'/installer/update-delivery.php';
    if ('hold' === $action && 0 === posix_geteuid()) {
        EmailerUpdateDelivery::begin($db, $file, $operation);
    } elseif (in_array($action, ['blocked', 'complete'], true) && 0 === posix_geteuid()) {
        $state = EmailerUpdateDelivery::read($file, $operation);
        if (null === $state) {
            throw new RuntimeException('No owned browser update.');
        }
        $state['phase'] = $action;
        $state['reason'] = 'SMTP TLS certificate validation failed. Open Mail settings, correct the certificate, then choose Finish in setup.';
        EmailerUpdateDelivery::save($file, $state);
    // This stage checks UI transitions, not the separate host-resume acceptance.
    // Its original hold remains in the database and no worker is running.
    } elseif ('completed' === $action && 0 === posix_geteuid()) {
        $baseline = json_decode(file_get_contents($directory.'/maintenance-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $db->update('campaign', ['state' => 'completed'], ['id' => $baseline['ids']['sending']]);
    } elseif ('verify' === $action) {
        $baseline = json_decode(file_get_contents($directory.'/maintenance-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['attempts' => 'delivery_attempt', 'tests' => 'campaign_test_delivery', 'queue' => 'messenger_messages'] as $key => $table) {
            if ($baseline[$key] !== (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table)) {
                throw new RuntimeException('A blocked request mutated delivery work.');
            }
        }
        if ($baseline['mail'] !== json_decode(file_get_contents('http://mailpit:8025/api/v1/messages'), true, flags: JSON_THROW_ON_ERROR)['total']) {
            throw new RuntimeException('An unintended message was emitted.');
        }
        if ('paused' !== $db->fetchOne('SELECT state FROM campaign WHERE id=?', [$baseline['ids']['paused']])) {
            throw new RuntimeException('An existing campaign pause changed.');
        }
        echo json_encode(['status' => 'PASS', 'no_unintended_send' => true, 'blocked_requests_created_no_work' => true, 'prior_campaign_pause_preserved' => true], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    } else {
        throw new RuntimeException('Unsupported fixture action.');
    }
    echo "Maintenance fixture transition recorded.\n";
} catch (Throwable) {
    fwrite(STDERR, "Synthetic update fixture failed; no private diagnostic data is printed.\n");
    exit(1);
}
