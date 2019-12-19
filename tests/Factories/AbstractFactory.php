<?php

namespace Tests\Factories;

use Illuminate\Support\Collection;

/**
 * Class AbstractFactory
 * @package Tests\Factories
 */
abstract class AbstractFactory
{
    /**
     * @param array $attributes
     * @return mixed
     */
    abstract public function create(array $attributes = []);

    /**
     * @param int $amount
     * @return Collection
     */
    public function createMany(int $amount = 1): Collection
    {
        $collection = collect();

        do {
            $collection->push($this->create());
        } while (--$amount);

        return $collection;
    }
}
