<?php

declare(strict_types=1);

namespace FourPay\Exception;

/** Network failure, DNS, TLS, or the request outlived its timeout. */
class ConnectionException extends FourPayException
{
}
