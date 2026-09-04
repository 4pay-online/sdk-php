<?php

declare(strict_types=1);

namespace FourPay\Exception;

/**
 * `401`, or the bare `400` returned when `x-organization-id` is missing.
 *
 * The platform resolves an API key *inside* the organization the header names,
 * so a key on its own is not a credential.
 */
class AuthException extends ApiException
{
}
