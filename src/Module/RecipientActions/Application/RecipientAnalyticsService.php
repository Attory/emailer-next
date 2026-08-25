<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Application;

use App\Module\Analytics\Application\Contract\RecipientAnalyticsLinks;
use App\Module\Configuration\Application\Contract\RecipientRuntimeConfiguration;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Module\Secrets\Application\Contract\SecretVault;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class RecipientAnalyticsService implements RecipientAnalyticsLinks
{
    private const SECRET_TYPE = 'recipient.token.key';
    private const LIFETIME = '90 days';

    public function __construct(private Connection $connection, private RecipientRuntimeConfiguration $configuration, private SecretVault $vault, private RecipientTokenCodec $codec, private RecipientUnsubscribeLinks $unsubscribe)
    {
    }

    public function issue(string $campaignId, string $campaignRecipientId, string $contactId, string $email, bool $openTracking, bool $clickTracking): array
    {
        foreach ([$campaignId, $campaignRecipientId, $contactId] as $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
                throw new \InvalidArgumentException('Recipient analytics scope is invalid.');
            }
        }
        new EmailAddress($email);
        $runtime = $this->configuration->current();
        $view = $this->token('view', $campaignId, $campaignRecipientId, $contactId, $runtime['installation_id']);
        $open = $openTracking ? $this->token('open', $campaignId, $campaignRecipientId, $contactId, $runtime['installation_id']) : null;
        $click = $clickTracking ? $this->token('click', $campaignId, $campaignRecipientId, $contactId, $runtime['installation_id']) : null;

        return ['view' => $runtime['canonical_origin'].'/r/'.rawurlencode($view).'/view', 'open' => null === $open ? null : $runtime['canonical_origin'].'/r/'.rawurlencode($open).'/open.gif', 'click' => null === $click ? null : $runtime['canonical_origin'].'/r/'.rawurlencode($click).'/click'];
    }

    /** @return array<string, mixed> */
    public function verify(string $token, string $purpose): array
    {
        if (!\in_array($purpose, ['view', 'open', 'click'], true) || null === ($parsed = $this->codec->parse($token))) {
            throw new RecipientTokenInvalid();
        }
        $row = $this->connection->fetchAssociative('SELECT t.*, k.secret_id, k.active, k.verify_until FROM recipient_token t INNER JOIN recipient_token_key k ON k.key_version = t.key_version WHERE t.handle = ? AND t.purpose = ?', [$parsed['handle'], $purpose]);
        if (false === $row || !is_numeric($row['key_version']) || $parsed['version'] !== (int) $row['key_version'] || !\is_string($row['expires_at']) || !\is_string($row['secret_id'])) {
            throw new RecipientTokenInvalid();
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $keyUsable = 1 === $this->integer($row['active']) || (\is_string($row['verify_until']) && new \DateTimeImmutable($row['verify_until'], new \DateTimeZone('UTC')) >= $now);
        if (!$keyUsable || null !== $row['revoked_at'] || new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC')) < $now) {
            throw new RecipientTokenInvalid();
        }
        $runtime = $this->configuration->current();
        try {
            $secret = $this->secret($this->vault->reveal($row['secret_id'], self::SECRET_TYPE, $runtime['installation_id']));
            $valid = $this->codec->verify($token, $this->integer($row['key_version']), $this->string($row['handle']), $purpose, $this->string($row['campaign_id']), $this->string($row['campaign_recipient_id']), $this->string($row['contact_id']), $row['expires_at'], $secret);
        } catch (\Throwable) {
            throw new RecipientTokenInvalid();
        } finally {
            if (isset($secret)) {
                sodium_memzero($secret);
            }
        }
        if (!$valid) {
            throw new RecipientTokenInvalid();
        }

        return $row;
    }

    public function record(string $recipientId, string $type, int $link = 0): void
    {
        if (!\in_array($type, ['open', 'click'], true) || 0 > $link || 65_535 < $link) {
            throw new \InvalidArgumentException('Analytics event is invalid.');
        }
        $this->connection->executeStatement('INSERT INTO analytics_aggregate (campaign_recipient_id, event_type, link_index, event_count, first_seen_at, last_seen_at) VALUES (?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE event_count = LEAST(4294967295, event_count + 1), last_seen_at = VALUES(last_seen_at)', [$recipientId, $type, $link, Identity::now(), Identity::now()]);
    }

    public function browserContent(string $token): string
    {
        $tokenRow = $this->verify($token, 'view');
        $row = $this->connection->fetchAssociative('SELECT c.id campaign_id, c.name, s.html_body, s.unsubscribe_footer, r.id recipient_id, r.contact_id, a.email_canonical, a.profile_json FROM campaign_recipient r INNER JOIN campaign c ON c.id = r.campaign_id INNER JOIN campaign_content_snapshot s ON s.campaign_id = c.id INNER JOIN campaign_audience_recipient a ON a.campaign_id = r.campaign_id AND a.contact_id = r.contact_id WHERE r.id = ?', [$this->string($tokenRow['campaign_recipient_id'])]);
        if (false === $row) {
            throw new RecipientTokenInvalid();
        }
        $profile = json_decode($this->string($row['profile_json']), true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($profile)) {
            throw new RecipientTokenInvalid();
        }
        $unsubscribe = $this->unsubscribe->issue($this->string($row['campaign_id']), $this->string($row['recipient_id']), $this->string($row['contact_id']), $this->string($row['email_canonical']));
        $runtime = $this->configuration->current();
        $values = ['contact.first_name' => \is_string($profile['first_name'] ?? null) ? $profile['first_name'] : '', 'contact.display_name' => \is_string($profile['display_name'] ?? null) ? $profile['display_name'] : '', 'campaign.name' => $this->string($row['name']), 'unsubscribe_url' => $unsubscribe, 'view_in_browser_url' => $runtime['canonical_origin'].'/r/'.rawurlencode($token).'/view'];
        $html = $this->string($row['html_body']);
        foreach ($values as $field => $value) {
            $html = preg_replace('/\{\{\s*'.preg_quote($field, '/').'\s*\}\}/', htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), $html) ?? $html;
        }
        if (1 !== preg_match('/\{\{\s*unsubscribe_url\s*\}\}/', $this->string($row['html_body']))) {
            $html .= '<p>'.htmlspecialchars($this->string($row['unsubscribe_footer']), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').' <a href="'.htmlspecialchars($unsubscribe, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8').'">Unsubscribe</a></p>';
        }

        return $html;
    }

    public function recordOpen(string $token): void
    {
        $row = $this->verify($token, 'open');
        if (1 === $this->integer($this->connection->fetchOne('SELECT s.open_tracking FROM campaign_content_snapshot s INNER JOIN campaign_recipient r ON r.campaign_id = s.campaign_id WHERE r.id = ?', [$this->string($row['campaign_recipient_id'])]))) {
            $this->record($this->string($row['campaign_recipient_id']), 'open');
        }
    }

    public function clickTarget(string $token, int $link, bool $record): string
    {
        $row = $this->verify($token, 'click');
        $snapshot = $this->connection->fetchAssociative('SELECT s.click_tracking, s.link_manifest_json FROM campaign_content_snapshot s INNER JOIN campaign_recipient r ON r.campaign_id = s.campaign_id WHERE r.id = ?', [$this->string($row['campaign_recipient_id'])]);
        $links = false === $snapshot ? null : json_decode($this->string($snapshot['link_manifest_json']), true, 32, JSON_THROW_ON_ERROR);
        $target = \is_array($links) ? ($links[$link] ?? null) : null;
        if (false === $snapshot || 1 !== $this->integer($snapshot['click_tracking']) || !\is_string($target) || 1 !== preg_match('#^https://[^\s]+$#D', $target)) {
            throw new RecipientTokenInvalid();
        }
        if ($record) {
            $this->record($this->string($row['campaign_recipient_id']), 'click', $link);
        }

        return $target;
    }

    private function token(string $purpose, string $campaignId, string $recipientId, string $contactId, string $installationId): string
    {
        return $this->connection->transactional(function () use ($purpose, $campaignId, $recipientId, $contactId, $installationId): string {
            $state = $this->connection->fetchOne("SELECT current_version FROM recipient_token_key_state WHERE singleton_key = '1' FOR UPDATE");
            if (!is_numeric($state) || 1 > (int) $state) {
                throw new \RuntimeException('Recipient token key is unavailable.');
            }
            $version = (int) $state;
            $row = $this->connection->fetchAssociative('SELECT * FROM recipient_token WHERE campaign_recipient_id = ? AND purpose = ? FOR UPDATE', [$recipientId, $purpose]);
            if (false === $row) {
                $expires = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+'.self::LIFETIME)->format('Y-m-d H:i:s.u');
                $handle = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
                $this->connection->insert('recipient_token', ['id' => Identity::uuidV7(), 'handle' => $handle, 'campaign_id' => $campaignId, 'campaign_recipient_id' => $recipientId, 'contact_id' => $contactId, 'email_canonical' => null, 'purpose' => $purpose, 'key_version' => $version, 'issued_at' => Identity::now(), 'expires_at' => $expires, 'revoked_at' => null, 'last_used_at' => null, 'use_count' => 0]);
                $row = $this->connection->fetchAssociative('SELECT * FROM recipient_token WHERE campaign_recipient_id = ? AND purpose = ? FOR UPDATE', [$recipientId, $purpose]);
            }
            if (false === $row || $row['campaign_id'] !== $campaignId || $row['contact_id'] !== $contactId || null !== $row['email_canonical']) {
                throw new \RuntimeException('Recipient analytics token scope does not match.');
            }
            $storedVersion = $this->integer($row['key_version']);
            $key = $this->connection->fetchOne('SELECT secret_id FROM recipient_token_key WHERE key_version = ?', [$storedVersion]);
            if (!\is_string($key)) {
                throw new \RuntimeException('Recipient token key is unavailable.');
            }
            $secret = $this->secret($this->vault->reveal($key, self::SECRET_TYPE, $installationId));
            try {
                return $this->codec->encode($storedVersion, $this->string($row['handle']), $purpose, $campaignId, $recipientId, $contactId, $this->string($row['expires_at']), $secret);
            } finally {
                sodium_memzero($secret);
            }
        });
    }

    private function secret(string $encoded): string
    {
        try {
            $secret = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException) {
            throw new \RuntimeException('Recipient token key is unavailable.');
        }
        if (32 !== \strlen($secret)) {
            sodium_memzero($secret);
            throw new \RuntimeException('Recipient token key is unavailable.');
        }

        return $secret;
    }

    private function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new RecipientTokenInvalid();
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new RecipientTokenInvalid();
        }

        return (int) $value;
    }
}
