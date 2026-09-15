<?php

declare(strict_types=1);

namespace Hampel\PayPal\Api\Exception;

/**
 * No such resource - HTTP 404. Or no such PATH, which is a different problem with the same status.
 *
 * isMissingResource() tells them apart by what PayPal wrote: `RESOURCE_NOT_FOUND`, or an
 * `INVALID_RESOURCE_ID` issue, is PayPal saying the id is not one it knows. Anything else - no
 * body, an HTML page, another error name - is not, and Endpoint::apiFind() raises it rather than
 * answering null, because a typo in a custom endpoint's path is not "that capture does not exist".
 */
final class NotFoundException extends ApiException
{
    public function isMissingResource(): bool
    {
        return $this->error !== null
            && ($this->error->name === 'RESOURCE_NOT_FOUND' || $this->error->hasIssue('INVALID_RESOURCE_ID'));
    }
}
