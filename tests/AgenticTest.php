<?php

declare(strict_types=1);

use FourPay\Agentic\A2A;
use FourPay\Agentic\Acp;
use FourPay\Agentic\Ap2;
use FourPay\Agentic\MandateVerificationException;
use FourPay\Agentic\Mcp;
use FourPay\Agentic\Tools;

/** @return array{0: string, 1: string} [privatePem, publicPem] */
function issuerKeys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }

    $resource = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    openssl_pkey_export($resource, $privatePem);
    $publicPem = openssl_pkey_get_details($resource)['key'];

    return $keys = [$privatePem, $publicPem];
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** DER SEQUENCE(INTEGER r, INTEGER s) back to the raw r‖s a JWS carries. */
function derToRaw(string $der): string
{
    $rLength = ord($der[3]);
    $r = ltrim(substr($der, 4, $rLength), "\x00");
    $sLength = ord($der[4 + $rLength + 1]);
    $s = ltrim(substr($der, 4 + $rLength + 2, $sLength), "\x00");

    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

/** @param array<string, mixed> $payload */
function signMandate(array $payload, array $header = ['alg' => 'ES256', 'kid' => 'issuer-1']): string
{
    [$privatePem] = issuerKeys();
    $signingInput = base64UrlEncode(json_encode($header)) . '.' . base64UrlEncode(json_encode($payload));
    openssl_sign($signingInput, $der, $privatePem, OPENSSL_ALGO_SHA256);

    return $signingInput . '.' . base64UrlEncode(derToRaw($der));
}

/** @return array<string, mixed> */
function cartMandate(array $overrides = []): array
{
    return array_merge([
        'id' => 'cart-1',
        'merchant_id' => 'merchant-1',
        'items' => [['name' => 'Runner', 'quantity' => 2, 'price' => '6000']],
        'total' => '12000',
        'currency' => 'EUR',
        'expires_at' => gmdate('c', time() + 600),
        'user_id' => 'user-1',
    ], $overrides);
}

function issuerKeyring(): array
{
    [, $publicPem] = issuerKeys();
    return ['issuer-1' => $publicPem];
}

// ------------------------------------------------------------------------ AP2

test('a properly signed cart mandate verifies', function (): void {
    $mandate = Ap2::verifyCartMandate(signMandate(cartMandate()), issuerKeyring(), 'merchant-1');
    assertSameValue('cart-1', $mandate['id']);
});

test('a tampered payload no longer verifies', function (): void {
    [$header, , $signature] = explode('.', signMandate(cartMandate()));
    $forged = base64UrlEncode(json_encode(cartMandate(['total' => '1'])));

    assertThrows(
        MandateVerificationException::class,
        fn () => Ap2::verifyCartMandate("{$header}.{$forged}.{$signature}", issuerKeyring(), 'merchant-1'),
        'does not verify',
    );
});

test('a mandate from an unknown issuer is refused', function (): void {
    $jws = signMandate(cartMandate(), ['alg' => 'ES256', 'kid' => 'issuer-unknown']);
    assertThrows(
        MandateVerificationException::class,
        fn () => Ap2::verifyCartMandate($jws, issuerKeyring(), 'merchant-1'),
        'No public key for kid',
    );
});

test('an HMAC-signed mandate is refused — the merchant must not be able to mint one', function (): void {
    $jws = signMandate(cartMandate(), ['alg' => 'HS256', 'kid' => 'issuer-1']);
    assertThrows(
        MandateVerificationException::class,
        fn () => Ap2::verifyCartMandate($jws, issuerKeyring(), 'merchant-1'),
        'Refusing algorithm HS256',
    );
});

test('a cart addressed to another merchant is refused', function (): void {
    $jws = signMandate(cartMandate(['merchant_id' => 'someone-else']));
    assertThrows(
        MandateVerificationException::class,
        fn () => Ap2::verifyCartMandate($jws, issuerKeyring(), 'merchant-1'),
        'addressed to merchant',
    );
});

test('an expired mandate is refused', function (): void {
    $jws = signMandate(cartMandate(['expires_at' => gmdate('c', time() - 60)]));
    assertThrows(
        MandateVerificationException::class,
        fn () => Ap2::verifyCartMandate($jws, issuerKeyring(), 'merchant-1'),
        'expired',
    );
});

test('a signed total that disagrees with its own lines is refused', function (): void {
    $jws = signMandate(cartMandate(['total' => '3']));
    assertThrows(
        MandateVerificationException::class,
        fn () => Ap2::verifyCartMandate($jws, issuerKeyring(), 'merchant-1'),
        'does not match its lines',
    );
});

test('an intent mandate caps what the agent may spend', function (): void {
    $intent = [
        'id' => 'intent-1',
        'constraints' => ['max_amount' => '10000', 'currency' => 'EUR'],
        'expires_at' => gmdate('c', time() + 600),
    ];

    assertThrows(
        MandateVerificationException::class,
        fn () => Ap2::assertWithinIntent(cartMandate(), $intent),
        'exceeds',
    );
    Ap2::assertWithinIntent(cartMandate([
        'total' => '6000',
        'items' => [['name' => 'Runner', 'quantity' => 1, 'price' => '6000']],
    ]), $intent);
});

test('a verified cart becomes an idempotent payment carrying its own authority', function (): void {
    $params = Ap2::cartMandateToPayment(cartMandate(), 'test');
    assertSameValue('cart-1', $params['txid']);
    assertSameValue(true, $params['hosted']);
    assertSameValue('cart-1', $params['merchant_meta']['ap2']['payment_mandate']['cart_mandate_id']);
});

// ------------------------------------------------------------------------ ACP

test('an ACP session prices, becomes payable and produces a payment', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    $bridge = new Acp(
        client: $client, env: 'test', currency: 'EUR',
        priceItem: fn (string $id, int $qty): string => (string) (2000 * $qty),
        generateId: fn (): string => 's-1',
    );

    $session = $bridge->create([['id' => 'sku-1', 'quantity' => 2]]);
    assertSameValue('ready_for_payment', $session['status']);
    assertSameValue('4000', end($session['totals'])['amount']);

    $session = $bridge->complete('s-1');
    assertSameValue('tx-1', $session['order']['id']);
    assertSameValue('s-1', $recorder->calls[0]['body']['params']['txid']);
    assertTrueValue($session['status'] !== 'completed', 'an unpaid payment link is not a completed order');
});

test('prices come from the catalogue, never from the agent\'s request', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    $bridge = new Acp(
        client: $client, env: 'test', currency: 'EUR',
        priceItem: fn (string $id, int $qty): string => '2000',
        generateId: fn (): string => 's-1',
    );

    $bridge->create([['id' => 'sku-1', 'quantity' => 1, 'base_amount' => '1']]);
    $bridge->complete('s-1');
    assertSameValue('2000', $recorder->calls[0]['body']['params']['amount']);
});

test('completing twice does not pay twice', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => [
        'status' => 201,
        'body' => array_merge(CREATED, ['status' => 'charged', 'widget_url' => null]),
    ]);
    $bridge = new Acp(
        client: $client, env: 'test', currency: 'EUR',
        priceItem: fn (string $id, int $qty): string => '2000',
        generateId: fn (): string => 's-1',
    );

    $bridge->create([['id' => 'sku-1', 'quantity' => 1]]);
    $bridge->complete('s-1');
    $bridge->complete('s-1');
    assertSameValue(1, count($recorder->calls));
});

// ------------------------------------------------------------------------ A2A

test('the agent card advertises the payment skills', function (): void {
    [$client] = stubClient(fn (int $n) => ['body' => CREATED]);
    $agent = new A2A(client: $client, env: 'test', url: 'https://agent.example/a2a');

    $skills = array_column($agent->agentCard()['skills'], 'id');
    sort($skills);
    assertSameValue(['create_payment', 'payment_status', 'refund_payment'], $skills);
});

test('message/send produces a task carrying a payment link', function (): void {
    [$client] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    $agent = new A2A(client: $client, env: 'test', url: 'https://agent.example/a2a');

    $response = $agent->handle([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'message/send',
        'params' => ['message' => ['role' => 'user', 'messageId' => 'm-1', 'parts' => [
            ['kind' => 'data', 'data' => [
                'skill' => 'create_payment', 'amount' => '4000', 'currency' => 'EUR', 'order_id' => 's-1',
            ]],
        ]]],
    ]);

    assertSameValue('completed', $response['result']['status']['state']);
    assertSameValue(
        CREATED['widget_url'],
        $response['result']['artifacts'][0]['parts'][1]['data']['payment_url'],
    );
});

test('a payment above the agent\'s ceiling fails the task instead of charging', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    $agent = new A2A(client: $client, env: 'test', url: 'https://a.example/a2a', maxAmount: '1000');

    $response = $agent->handle([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'message/send',
        'params' => ['message' => ['role' => 'user', 'messageId' => 'm-1', 'parts' => [
            ['kind' => 'data', 'data' => ['skill' => 'create_payment', 'amount' => '500000', 'currency' => 'EUR']],
        ]]],
    ]);

    assertSameValue('failed', $response['result']['status']['state']);
    assertSameValue(0, count($recorder->calls));
});

// ------------------------------------------------------------------ LLM tools

test('refunds are absent from the tool list unless explicitly enabled', function (): void {
    assertSameValue(false, in_array('refund_payment', array_column(Tools::definitions(), 'name'), true));
    assertSameValue(
        true,
        in_array('refund_payment', array_column(Tools::definitions(null, true), 'name'), true),
    );
});

test('a decimal amount from a model is rejected before it reaches the platform', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    $result = Tools::execute($client, 'create_payment', ['amount' => '40.00', 'order_id' => 's-1'], 'test', 'EUR');

    assertSameValue(false, $result['ok']);
    assertTrueValue(str_contains($result['content'], 'minor units'));
    assertSameValue(0, count($recorder->calls));
});

test('the ceiling is enforced where a prompt cannot argue with it', function (): void {
    [$client, $recorder] = stubClient(fn (int $n) => ['status' => 201, 'body' => CREATED]);
    $result = Tools::execute(
        $client, 'create_payment', ['amount' => '999999', 'order_id' => 's-1'], 'test', 'EUR', '10000',
    );

    assertSameValue(false, $result['ok']);
    assertTrueValue(str_contains($result['content'], 'ceiling'));
    assertSameValue(0, count($recorder->calls));
});

test('a refused payment comes back as a sentence the model can act on', function (): void {
    [$client] = stubClient(fn (int $n) => [
        'status' => 201,
        'body' => array_merge(CREATED, ['status' => 'rejected', 'error_description' => 'terminal not found']),
    ]);
    $result = Tools::execute($client, 'create_payment', ['amount' => '4000', 'order_id' => 's-1'], 'test', 'EUR');

    assertSameValue(false, $result['ok']);
    assertTrueValue(str_contains($result['content'], 'No terminal is configured'));
});

// ------------------------------------------------------------------------ MCP

test('the MCP config points a partner at the payments build, not the operator one', function (): void {
    $config = Mcp::buildConfig('key', 'org', 'https://sandbox.4pay.online');
    assertSameValue('@4pay/payments-mcp', $config['mcpServers']['fourpay']['args'][1]);

    $operator = Mcp::buildConfig('key', 'org', scope: 'operator');
    assertSameValue('@4pay/mcp-server', $operator['mcpServers']['fourpay']['args'][1]);
});

test('placeholders keep the key out of a file that ends up in a repository', function (): void {
    $config = Mcp::buildConfig('secret-key', 'org', useEnvPlaceholders: true);
    $env = $config['mcpServers']['fourpay']['env'];

    assertSameValue('${FOURPAY_API_KEY}', $env['FOURPAY_API_KEY']);
    assertTrueValue(!str_contains(json_encode($config), 'secret-key'));
});
