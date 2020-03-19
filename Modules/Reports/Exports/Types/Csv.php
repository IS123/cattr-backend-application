<?php

namespace Modules\Reports\Exports\Types;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Sheet;

class Csv extends AbstractType implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        return $this->collection;
    }

    public function headings(): array
    {
        $firstRow = $this->collection->first();

        if ($firstRow instanceof Arrayable || is_object($firstRow)) {
            return array_keys(Sheet::mapArraybleRow($firstRow));
        }

        return $this->collection->collapse()->keys()->all();
    }
}
