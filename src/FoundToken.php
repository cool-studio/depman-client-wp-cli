<?php

namespace Depman\WpCli;

/**
 * A token and where it came from.
 *
 * The source is the useful half. Four mechanisms can supply a token, and when
 * the wrong one wins the symptom is a 401 with no clue which of them produced
 * the credential — so `wp depman doctor` prints this rather than a bare "found".
 */
final class FoundToken
{
    /** @var string */
    public $value;

    /** @var string */
    public $source;

    public function __construct(string $value, string $source)
    {
        $this->value = $value;
        $this->source = $source;
    }
}
