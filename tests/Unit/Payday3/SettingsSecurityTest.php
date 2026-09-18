<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Domain\LocalSettings;
use App\Payday3\Http\Actions\SettingsAction;
use App\Payday3\Services\JsonLocalSettingsRepository;
use App\Payday3\Services\LocalSettingsCodec;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Payday3\Fakes\InMemoryAuditLog;

/**
 * Настройки payday3:
 *   - cookies/CSRF админки Poster («poster_admin») больше не отдаются
 *     клиенту, не читаются и стираются при следующем сохранении;
 *   - сохранять может только admin, каждое сохранение — в audit-лог.
 */
final class SettingsSecurityTest extends TestCase
{
    protected function setUp(): void    { $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = []; }

    private static function validPayload(): array
    {
        return [
            'telegram_chat_id' => '-1003889942420', 'telegram_message_thread_id' => '5274',
            'service_user_id'  => 4,
            'accounts' => ['andrey' => 1, 'tips' => 8, 'vietnam' => 9, 'stash' => 11],
            'balance_sinc_account_id' => 8,
            'poster_admin' => ['ssid' => 'SECRET-SSID', 'cookie' => 'pos_session=SECRET'],
        ];
    }

    public function test_client_payload_has_no_poster_admin(): void
    {
        $payload = LocalSettingsCodec::fromArray(self::validPayload())->toClientPayload();
        $this->assertArrayNotHasKey('poster_admin', $payload);
        $this->assertStringNotContainsString('SECRET', (string)json_encode($payload));
    }

    public function test_saving_erases_stored_poster_admin(): void
    {
        $canonical = LocalSettingsCodec::toCanonicalArray(self::validPayload());
        $this->assertArrayNotHasKey('poster_admin', $canonical);
    }

    public function test_cash_account_is_optional_and_defaults_to_2(): void
    {
        $this->assertNull(LocalSettingsCodec::validate(self::validPayload()));
        $s = LocalSettingsCodec::fromArray(LocalSettingsCodec::toCanonicalArray(self::validPayload()));
        $this->assertSame(2, $s->accountCashId);
        $this->assertEqualsCanonicalizing([1, 8, 9, 11, 2], $s->configuredAccountIds());
    }

    public function test_json_repository_read_raw_on_missing_file_returns_null(): void
    {
        // Used to read an undeclared $fallbackPath → TypeError on fresh installs.
        $repo = new JsonLocalSettingsRepository(sys_get_temp_dir() . '/pd3-missing-' . bin2hex(random_bytes(4)) . '.json');
        $this->assertNull($repo->readRaw());
    }

    private function repo(): LocalSettingsRepositoryInterface
    {
        return new class implements LocalSettingsRepositoryInterface {
            public ?array $saved = null;
            public function load(): LocalSettings
            {
                return $this->saved === null ? LocalSettings::defaults() : LocalSettingsCodec::fromArray($this->saved);
            }
            public function save(array $payload): array
            {
                $this->saved = LocalSettingsCodec::toCanonicalArray($payload);
                return ['ok' => true];
            }
        };
    }

    private function post(array $body)
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/payday3/api/settings')->withParsedBody($body);
    }

    public function test_non_admin_cannot_save(): void
    {
        $_SESSION['user_permissions'] = ['payday' => 1];
        $repo  = $this->repo();
        $audit = new InMemoryAuditLog();
        $res = (new SettingsAction($repo, $audit))($this->post(self::validPayload()), new Response());
        $this->assertSame(403, $res->getStatusCode());
        $this->assertNull($repo->saved);
        $this->assertSame([], $audit->rows);
    }

    public function test_admin_save_is_audited_with_diff(): void
    {
        $_SESSION['user_permissions'] = ['payday' => 1, 'admin' => 1];
        $_SESSION['user_email'] = 'boss@veranda.my';
        $repo  = $this->repo();
        $audit = new InMemoryAuditLog();
        $body = self::validPayload();
        $body['telegram_chat_id'] = '-100999';

        $res = (new SettingsAction($repo, $audit))($this->post($body), new Response());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertCount(1, $audit->rows);
        $this->assertSame('boss@veranda.my', $audit->rows[0]['email']);
        $this->assertSame('settings.save', $audit->rows[0]['action']);
        $this->assertSame(['-1003889942420', '-100999'], $audit->rows[0]['payload']['changes']['telegram_chat_id']);
    }

    public function test_get_is_open_to_payday_users(): void
    {
        $_SESSION['user_permissions'] = ['payday' => 1];
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/payday3/api/settings');
        $res = (new SettingsAction($this->repo(), new InMemoryAuditLog()))($req, new Response());
        $this->assertSame(200, $res->getStatusCode());
    }
}
