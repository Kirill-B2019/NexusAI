<?php

namespace App\Services;

interface ApplicationSecretStore
{
    /**
     * @param  array<string, string>  $secret
     * @param  array<string, string>  $tags
     */
    public function store(string $name, array $secret, string $description, array $tags): string;

    /**
     * @param  array<string, string>  $expectedTags
     */
    public function delete(string $name, array $expectedTags): void;
}
