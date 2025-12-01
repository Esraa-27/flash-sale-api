<?php

namespace App\Repositories;

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

class ProductRepository extends BaseRepository
{
    /**
     * ProductRepository constructor.
     *
     * @param Product $model
     */
    public function __construct(Product $model)
    {
        parent::__construct($model);
    }

    /**
     * Find products by name.
     *
     * @param string $name
     * @return Collection
     */
    public function findByName(string $name): Collection
    {
        return $this->model->where('name', 'like', "%{$name}%")->get();
    }

    /**
     * Find products with stock greater than zero.
     *
     * @return Collection
     */
    public function findInStock(): Collection
    {
        return $this->model->where('stock', '>', 0)->get();
    }

    /**
     * Find product by ID with available stock calculation.
     *
     * @param int $id
     * @return array|null
     */
    public function findWithAvailableStock(int $id): ?array
    {
        $product = $this->model->find($id);

        if (!$product) {
            return null;
        }

        // Calculate held quantity using Hold model with aggregate
        $heldQuantity = (int) Hold::where('product_id', $id)
            ->where('expires_at', '>', now())
            ->where('is_used', false)
            ->sum('quantity');

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => (float) $product->price,
            'total_stock' => $product->stock,
            'available_stock' => max(0, $product->stock - $heldQuantity),
        ];
    }

    /**
     * Find product by ID with pessimistic lock (SELECT ... FOR UPDATE).
     * This locks the product row to prevent concurrent modifications.
     *
     * @param int $id
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function findWithLockForUpdate(int $id): ?\Illuminate\Database\Eloquent\Model
    {
        return $this->model->where('id', $id)->lockForUpdate()->first();
    }
}
