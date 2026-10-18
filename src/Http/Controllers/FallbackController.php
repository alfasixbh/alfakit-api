<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitApi\Http\Controllers;

use AlfaSix\AlfakitApi\Http\ProblemException;

/**
 * Unknown URLs under the API prefix answer with a problem document, not an HTML page.
 */
class FallbackController
{
    public function __invoke(): never
    {
        throw ProblemException::make(404, (string) __('alfakit-api::api.problems.not_found'), 'not-found');
    }
}
