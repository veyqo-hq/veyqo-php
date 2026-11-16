<?php

declare(strict_types=1);

namespace Veyqo;

/** A webhook whose signature or timestamp did not check out. Reject it. */
final class SignatureException extends \RuntimeException {}
