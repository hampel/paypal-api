<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Enum;

/**
 * A transaction search row's `transaction_status`, and its filter's.
 */
enum TransactionStatus: string
{
    /** PayPal or merchant rules denied the transaction. */
    case Denied = 'D';

    /** Created, and waiting on another process - such as a bank transfer - to complete. */
    case Pending = 'P';

    /** Completed, without a denial and after any pending status. */
    case Success = 'S';

    /** A successful transaction was reversed and the funds returned to the sender. */
    case Reversed = 'V';
}
