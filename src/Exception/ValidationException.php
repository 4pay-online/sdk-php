<?php

declare(strict_types=1);

namespace FourPay\Exception;

/** `422` — one detail per rejected field. */
class ValidationException extends ApiException
{
}
