<?php

namespace ApiMaker;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

trait ListableTrait
{
    /**
     * Returns the listable attributes.
     */
    public function getListable(): array
    {
        return $this->listable ?? [];
    }

    /**
     * Sets the listable attributes.
     */
    public function setListable(array $listable): self
    {
        $this->listable = $listable;
        return $this;
    }

    /**
     * Adds a new item to the listable attributes.
     */
    public function addListable(string $value, ?string $alias = null): self
    {
        if (!isset($this->listable)) {
            $this->listable = [];
        }

        if ($alias === null) {
            $this->listable[] = $value;
        } else {
            $this->listable[$value] = $alias;
        }

        return $this;
    }

    /**
     * Returns the selectable attributes.
     */
    public function getSelectable(): array|string
    {
        if (empty($this->listable)) {
            return '*';
        }

        return array_map(function ($key, $value) {
            if (is_numeric($key)) {
                return strpos($value, '.') === false ? "{$this->getTable()}.{$value}" : $value;
            }
            return DB::raw("{$key} as {$value}");
        }, array_keys($this->listable), $this->listable);
    }
}
