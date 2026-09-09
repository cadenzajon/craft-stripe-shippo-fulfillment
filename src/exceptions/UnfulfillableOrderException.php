<?php

namespace cadenzajon\stripeshippo\exceptions;

use RuntimeException;

/** An expected order-validation failure whose message is safe to show to an administrator. */
class UnfulfillableOrderException extends RuntimeException
{
}
