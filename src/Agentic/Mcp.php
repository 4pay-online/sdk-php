<?php

declare(strict_types=1);

namespace FourPay\Agentic;

/**
 * MCP — Model Context Protocol.
 *
 * The platform ships an MCP server, in two builds because the two audiences are
 * not the same person:
 *
 * | Server                 | Who runs it            | What it exposes                              |
 * |------------------------|------------------------|----------------------------------------------|
 * | `4pay-payments-mcp` | the partner (merchant) | transactions, payment links, products, cards |
 * | `4pay-mcp`          | the platform's client  | the above plus terminals, routing, limits, FX |
 *
 * A partner integrating payments wants the first. Handing them the operator
 * build gives an agent tools that can re-route live traffic, and no amount of
 * prompting reliably keeps a model out of a tool it can see.
 */
final class Mcp
{
    /**
     * Build the `mcpServers` entry for an MCP client.
     *
     * With `$useEnvPlaceholders` the file carries `${FOURPAY_API_KEY}` rather
     * than the key itself — worth doing, since these files end up in repositories.
     *
     * @return array<string, mixed>
     */
    public static function buildConfig(
        string $apiKey,
        string $organizationId,
        string $baseUrl = 'https://4pay.online',
        string $scope = 'payments',
        bool $useEnvPlaceholders = false,
    ): array {
        $package = $scope === 'payments' ? '@4pay/payments-mcp' : '@4pay/mcp-server';
        $env = $useEnvPlaceholders
            ? [
                'FOURPAY_API_URL' => '${FOURPAY_API_URL}',
                'FOURPAY_API_KEY' => '${FOURPAY_API_KEY}',
                'FOURPAY_ORGANIZATION_ID' => '${FOURPAY_ORGANIZATION_ID}',
            ]
            : [
                'FOURPAY_API_URL' => $baseUrl,
                'FOURPAY_API_KEY' => $apiKey,
                'FOURPAY_ORGANIZATION_ID' => $organizationId,
            ];

        return ['mcpServers' => ['fourpay' => ['command' => 'npx', 'args' => ['-y', $package], 'env' => $env]]];
    }
}
