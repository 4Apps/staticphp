<?php

namespace System\Modules\Utils\Models\Translation;

/**
 * A translation layer fault that the caller can act on.
 *
 * Kept separate from PDOException so that "this configuration is wrong" and "the database
 * went away" stay distinguishable: the first is a deployment mistake that should stop the
 * request, the second is an outage the page can render through.
 */
class TranslationError extends \Exception
{
}
