<?php

namespace App\Exceptions;

use DomainException;

/**
 * The requested destination location cannot receive an asset.
 *
 * Separate from a plain `DomainException` so the controller can render it as
 * **422**, matching `POST /assets/{id}/location`. It is a problem with the value
 * submitted, not with the state of the asset — the same distinction that keeps
 * the eligibility and manual-move guards on 409.
 */
class InactiveLocationException extends DomainException {}
