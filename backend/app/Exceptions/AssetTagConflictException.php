<?php

namespace App\Exceptions;

use App\Actions\Assets\UpdateAsset;
use DomainException;

/**
 * A generated asset tag collided with one already in use.
 *
 * A `DomainException` like every other guard failure, so it still surfaces as
 * 409. It exists only so the controller can keep rendering this one case as a
 * field-keyed `errors.asset_tag` body — the tag input is what the user has to
 * change, and a bare `message` would leave the form with nothing to highlight.
 *
 * Introduced when the asset update moved into {@see UpdateAsset},
 * which collapsed several separate try/catch blocks into one: without a distinct
 * type the controller would have had to match on the message text.
 */
class AssetTagConflictException extends DomainException {}
