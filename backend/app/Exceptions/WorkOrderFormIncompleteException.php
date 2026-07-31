<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a Work Order with an attached form cannot be completed because
 * one or more required form fields are unfilled. Extends DomainException so it
 * stays inside the domain-conflict family, but is caught BEFORE the generic
 * DomainException handler in the controller so the response carries the list of
 * missing fields with a 422 status.
 */
class WorkOrderFormIncompleteException extends DomainException
{
    /**
     * @param array<int, array{uuid: ?string, label: ?string, missing: array<int, string>}> $missing
     */
    public function __construct(public array $missing, string $message = 'Required WO Form fields are unfilled.')
    {
        parent::__construct($message);
    }

    /**
     * @return array<int, array{uuid: ?string, label: ?string, missing: array<int, string>}>
     */
    public function missing(): array
    {
        return $this->missing;
    }
}
