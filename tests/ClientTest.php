<?php

declare(strict_types=1);

namespace PayGate\Tests;

use PayGate\Client;
use PayGate\Exceptions\PayGateException;
use PayGate\Exceptions\TransportException;
use PayGate\HmacSigner;
use PHPUnit\Framework\TestCase;

/**
 * Runs Client against PHP's built-in web server (tests/support/router.php),
 * so no network or external service is needed.
 */
final class ClientTest extends TestCase
{
    private const KEY = 'pgk_test';
    private const SECRET = 'test_secret';

    /** @var resource */
    private static $process;
    private static string $dir;
    private static string $baseUrl;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paygate-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);

        self::$baseUrl = 'http://127.0.0.1:' . $port;
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/support/router.php'];
        self::$process = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['file', self::$dir . '/server.log', 'a'], 2 => ['file', self::$dir . '/server.log', 'a']],
            $pipes,
            null,
            array_merge($_ENV, ['PAYGATE_TEST_DIR' => self::$dir, 'SystemRoot' => getenv('SystemRoot') ?: ''])
        );

        for ($i = 0; $i < 100; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($conn) {
                fclose($conn);

                return;
            }
            usleep(50000);
        }
        self::fail('Built-in PHP server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$dir);
    }

    protected function setUp(): void
    {
        $this->reply(200, ['success' => true, 'data' => ['ok' => true]]);
    }

    /** @param array<string, mixed>|string $payload */
    private function reply(int $status, $payload): void
    {
        file_put_contents(self::$dir . '/reply.json', json_encode([
            'status' => $status,
            'raw' => is_string($payload) ? $payload : json_encode($payload),
        ]));
    }

    private function client(): Client
    {
        return Client::withHmac(self::$baseUrl, self::KEY, self::SECRET);
    }

    /** @return array<string, mixed> */
    private function captured(): array
    {
        return json_decode((string) file_get_contents(self::$dir . '/capture.json'), true);
    }

    /** @param array<string, mixed>|null $body */
    private function assertSigned(string $method, string $pathWithoutQuery, ?array $body): void
    {
        $captured = $this->captured();
        $rawBody = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertSame($method, $captured['method']);
        $this->assertSame(self::KEY, $captured['headers']['x-app-key']);
        $timestamp = (int) $captured['headers']['x-timestamp'];
        $this->assertGreaterThan(0, $timestamp);
        $this->assertSame(
            (new HmacSigner(self::SECRET))->sign($method, $pathWithoutQuery, $rawBody, $timestamp),
            $captured['headers']['x-signature']
        );
        $this->assertSame($rawBody, $captured['body']);
    }

    /** @return array<string, array{0: callable, 1: string, 2: string, 3: ?array}> */
    public function endpointProvider(): array
    {
        $price = ['amount' => 199000, 'currency' => 'VND', 'interval_unit' => 'month', 'interval_count' => 1];
        $priceWithTrial = $price + ['trial' => ['amount' => 0, 'interval_unit' => 'day', 'interval_count' => 7]];

        return [
            'createCustomer' => [
                static function (Client $c) {
                    return $c->createCustomer(['name' => 'A', 'email' => 'a@example.com', 'locale' => 'vi']);
                },
                'POST', '/api/v1/customers', ['name' => 'A', 'email' => 'a@example.com', 'locale' => 'vi'],
            ],
            'getCustomer' => [
                static function (Client $c) {
                    return $c->getCustomer('cus_1');
                },
                'GET', '/api/v1/customers/cus_1', null,
            ],
            'updateCustomer' => [
                static function (Client $c) {
                    return $c->updateCustomer('cus_1', ['locale' => 'en']);
                },
                'PATCH', '/api/v1/customers/cus_1', ['locale' => 'en'],
            ],
            'getPlan' => [
                static function (Client $c) {
                    return $c->getPlan('plan_1');
                },
                'GET', '/api/v1/plans/plan_1', null,
            ],
            'createPlan' => [
                static function (Client $c) use ($price) {
                    return $c->createPlan(['code' => 'premium', 'name' => 'Premium', 'prices' => [$price]]);
                },
                'POST', '/api/v1/plans', ['code' => 'premium', 'name' => 'Premium', 'prices' => [$price]],
            ],
            'updatePlan' => [
                static function (Client $c) {
                    return $c->updatePlan('plan_1', ['status' => 'archived']);
                },
                'PATCH', '/api/v1/plans/plan_1', ['status' => 'archived'],
            ],
            'addPlanPrice' => [
                static function (Client $c) use ($priceWithTrial) {
                    return $c->addPlanPrice('plan_1', $priceWithTrial);
                },
                'POST', '/api/v1/plans/plan_1/prices', $priceWithTrial,
            ],
            'updatePlanPrice' => [
                static function (Client $c) {
                    return $c->updatePlanPrice('plan_1', 'price_1', ['name' => 'Monthly', 'status' => 'archived']);
                },
                'PATCH', '/api/v1/plans/plan_1/prices/price_1', ['name' => 'Monthly', 'status' => 'archived'],
            ],
            'getSubscription' => [
                static function (Client $c) {
                    return $c->getSubscription('user 42');
                },
                'GET', '/api/v1/subscriptions/user%2042', null,
            ],
            'setSubscriptionCustomer' => [
                static function (Client $c) {
                    return $c->setSubscriptionCustomer('user-42', 'cus_1');
                },
                'PUT', '/api/v1/subscriptions/user-42/customer', ['customer_id' => 'cus_1'],
            ],
            'setSubscriptionPrice' => [
                static function (Client $c) {
                    return $c->setSubscriptionPrice('user-42', 'price_2');
                },
                'PUT', '/api/v1/subscriptions/user-42/plan', ['price_id' => 'price_2'],
            ],
            'createCheckoutSession subscription' => [
                static function (Client $c) {
                    return $c->createCheckoutSession([
                        'mode' => 'subscription', 'price_id' => 'price_1', 'customer_id' => 'cus_1', 'skip_trial' => true,
                    ]);
                },
                'POST', '/api/v1/checkout-sessions',
                ['mode' => 'subscription', 'price_id' => 'price_1', 'customer_id' => 'cus_1', 'skip_trial' => true],
            ],
        ];
    }

    /**
     * @dataProvider endpointProvider
     * @param array<string, mixed>|null $body
     */
    public function testRequestIsSentAndSigned(callable $call, string $method, string $path, ?array $body): void
    {
        $call($this->client());

        $this->assertSame($path, $this->captured()['uri']);
        $this->assertSigned($method, $path, $body);
    }

    /**
     * @dataProvider endpointProvider
     * @param array<string, mixed>|null $body
     */
    public function testPayGateErrorsAreMapped(callable $call, string $method, string $path, ?array $body): void
    {
        $this->reply(404, ['success' => false, 'error' => ['code' => 'not_found', 'message' => 'Nope.']]);

        try {
            $call($this->client());
            $this->fail('Expected PayGateException.');
        } catch (PayGateException $e) {
            $this->assertSame('not_found', $e->getErrorCode());
            $this->assertSame('Nope.', $e->getMessage());
            $this->assertSame(404, $e->getHttpStatus());
        }
    }

    public function testListPlansWithoutFilterHasNoQueryString(): void
    {
        $this->reply(200, ['success' => true, 'data' => ['plans' => [['id' => 'plan_1']]]]);

        $plans = $this->client()->listPlans();

        $this->assertSame([['id' => 'plan_1']], $plans);
        $this->assertSame('/api/v1/plans', $this->captured()['uri']);
        $this->assertSigned('GET', '/api/v1/plans', null);
    }

    public function testListPlansSendsStatusQueryButSignsPathOnly(): void
    {
        $this->reply(200, ['success' => true, 'data' => ['plans' => []]]);

        $this->client()->listPlans(['status' => 'archived']);

        $this->assertSame('/api/v1/plans?status=archived', $this->captured()['uri']);
        $this->assertSigned('GET', '/api/v1/plans', null);
    }

    public function testNonJsonResponseThrowsTransportException(): void
    {
        $this->reply(200, 'not json');

        $this->expectException(TransportException::class);
        $this->client()->getCustomer('cus_1');
    }

    public function testFirebaseAuthUsesBearerToken(): void
    {
        Client::withFirebaseIdToken(self::$baseUrl, 'tok')->getCustomer('cus_1');

        $headers = $this->captured()['headers'];
        $this->assertSame('Bearer tok', $headers['authorization']);
        $this->assertArrayNotHasKey('x-signature', $headers);
    }
}
