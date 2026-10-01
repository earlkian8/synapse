<?php

namespace App\Models\Concerns;

use App\Support\Hashid;
use Illuminate\Database\Eloquent\Model;

/**
 * Binds a model to routes by an obfuscated **hashid** instead of its raw integer
 * key, so URLs never expose sequential primary keys. Route generation
 * (`route(...)`, `$model->getRouteKey()`) emits the code; route resolution decodes it.
 *
 * The decoded lookup goes through the normal query builder, so global scopes
 * (e.g. tenant isolation) still apply — an out-of-scope or malformed code 404s.
 *
 * @mixin Model
 */
trait HasHashid
{
    /**
     * The value placed in a URL for this model.
     */
    public function getRouteKey(): string
    {
        return Hashid::encode($this->getKey());
    }

    /**
     * Resolve a model from its hashid route value.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        $id = Hashid::decode((string) $value);

        return $id === null
            ? null
            : $this->where($this->getKeyName(), $id)->first();
    }

    /**
     * Expose the code for serialization (`$model->hashid`).
     */
    public function getHashidAttribute(): string
    {
        return Hashid::encode($this->getKey());
    }
}
