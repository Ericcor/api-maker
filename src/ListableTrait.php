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
    public function getSelectable()
    {
        if (!isset($this->listable) || empty($this->listable)) {
            return '*';
        }

        foreach ($this->listable as $key => $value) {
            if (is_numeric($key)) {
                if (count(explode('.', $value)) == 1) {
                    $return[] = "{$this->getTable()}.{$value}";
                } else {
                    $return[] = $value;
                }
            } else {
                $return[] = DB::raw("{$key} as {$value}");
            }
        }

        return $return;
    }
}
