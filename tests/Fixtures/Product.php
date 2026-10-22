<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $sku
 * @property string|null $price
 * @property int|null $stock
 * @property string|null $released_on
 * @property string|null $opens_at
 * @property bool $active
 */
class Product extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'stock' => 'integer',
    ];
}
