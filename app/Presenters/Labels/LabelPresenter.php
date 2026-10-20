<?php

namespace App\Presenters\Labels;

use App\Models\Label;

class LabelPresenter
{
    /**
     * @return array{id: string, name: string, color: string}
     */
    public static function present(Label $label): array
    {
        return [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
        ];
    }
}
