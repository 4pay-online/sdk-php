<?php

/**
 * The README's examples are run, not read.
 *
 * An example that cannot even be constructed is worse than no example: the
 * reader copies it, gets an exception out of a line the documentation presented
 * as working, and has no way to tell a mistake of ours from one of theirs.
 *
 * That is not hypothetical. While the SDK inferred "a domain of your own" from a
 * single default host, the README showed
 *
 *     new Client(bearerToken: $token, baseUrl: 'https://pay.partner.example')
 *
 * as the way a partner integrates. It constructed — and then sent every call
 * with no organization at all, because nothing in front of that host pins the
 * header. Once the SDK started asking for the claim out loud, the same snippet
 * stopped constructing.
 *
 * Every `new Client(...)` and `Client::forLogin(...)` in the README is built
 * here; a snippet that throws fails this test.
 */

declare(strict_types=1);

use FourPay\Client;

/** Values for the variables the snippets carry over from an earlier line. */
const README_BINDINGS = [
    '$token' => 'token-1',
    '$orgId' => 'org-1',
    "\$session['token']" => 'token-1',
    "getenv('FOURPAY_API_KEY')" => 'key-1',
    "getenv('FOURPAY_ORGANIZATION_ID')" => 'org-1',
];

/**
 * The call starting at $start, up to its balanced closing parenthesis.
 *
 * Balanced parentheses rather than a lazy pattern: the arguments span several
 * lines and carry nested calls, and a `[^)]*` would stop at the first inner
 * parenthesis and quietly test half a snippet.
 */
function readmeCallSource(string $text, int $start): string
{
    $open = strpos($text, '(', $start);
    $depth = 0;

    for ($i = $open; $i < strlen($text); $i++) {
        if ($text[$i] === '(') {
            $depth++;
        } elseif ($text[$i] === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }

    throw new RuntimeException("unbalanced call in README at offset {$start}");
}

/**
 * Every Client construction the README shows, as [source, named arguments].
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function readmeConstructions(string $markdown): array
{
    $found = [];

    foreach (['new Client', 'Client::forLogin'] as $marker) {
        $at = 0;
        while (($at = strpos($markdown, $marker . '(', $at)) !== false) {
            $source = readmeCallSource($markdown, $at);
            $args = [];

            // `name: value` pairs at the top level of the call. Values in these
            // snippets are quoted strings, `true`/`false`, or one of the
            // variables the surrounding example established
            preg_match_all(
                "/(\w+):\s*('[^']*'|true|false|\\\$\w+(?:\['[^']*'\])?|getenv\('[^']*'\))/",
                $source,
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as [, $name, $raw]) {
                $args[$name] = readmeValue($raw);
            }

            $found[] = [$source, $args];
            $at += strlen($marker);
        }
    }

    return $found;
}

/** One argument as a PHP value, with the example's variables bound. */
function readmeValue(string $raw): mixed
{
    if ($raw === 'true' || $raw === 'false') {
        return $raw === 'true';
    }
    if ($raw[0] === "'") {
        return substr($raw, 1, -1);
    }

    if (!array_key_exists($raw, README_BINDINGS)) {
        throw new RuntimeException("README example uses an unbound value: {$raw}");
    }

    return README_BINDINGS[$raw];
}

test('every Client in the README actually constructs', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    $calls = readmeConstructions($readme);

    // The README is the install-and-go page: if it stops showing the client
    // being built, this test has nothing to check and must say so rather than pass
    if (count($calls) < 3) {
        throw new RuntimeException('README shows only ' . count($calls) . ' client constructions');
    }

    foreach ($calls as [$source, $args]) {
        // The snippets do not pass a transport; nothing is sent during construction
        try {
            if (str_starts_with($source, 'Client::forLogin')) {
                Client::forLogin(...$args);
            } else {
                new Client(...$args);
            }
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                "README snippet does not construct:\n{$source}\n\n{$e->getMessage()}"
            );
        }
    }
});
