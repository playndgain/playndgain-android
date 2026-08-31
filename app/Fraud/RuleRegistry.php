<?php

namespace App\Fraud;

use App\Fraud\Contracts\Rule;

/**
 * Key => class, mirroring NetworkDriverManager. Concrete rules register
 * themselves from FraudServiceProvider.
 */
class RuleRegistry
{
    /** @var array<string, class-string<Rule>> */
    protected array $rules = [];

    /**
     * @param  class-string<Rule>  $class
     */
    public function register(string $class): void
    {
        $this->rules[$class::key()] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->rules[$key]);
    }

    public function resolve(string $key): ?Rule
    {
        $class = $this->rules[$key] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * @return array<string, class-string<Rule>>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
