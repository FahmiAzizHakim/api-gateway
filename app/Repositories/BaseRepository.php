<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The database half of a feature: how a row is found, written and removed.
 *
 * A repository holds queries and nothing else. It decides no policy -- whether
 * a delete is allowed, what a failure should say, what has to succeed
 * together -- because a Service owns that, and a rule buried behind a query is
 * a rule nobody can find. The split is what lets a Service be read as the case
 * it implements rather than as a pile of Eloquent.
 *
 * Concrete repositories name a query after what its caller wanted
 * (activeForWebsite(), not getAll(array $filters)): a query named for its
 * intent is one that can be reused without being re-read first.
 */
abstract class BaseRepository
{
    /**
     * The Eloquent model this repository reads and writes.
     *
     * @var class-string<Model>
     */
    protected $model;

    /** A fresh builder, for the queries a subclass composes itself. */
    public function query(): Builder
    {
        return ($this->model)::query();
    }

    /* =========================================================
     * Website scoping
     * ========================================================= */

    /**
     * Narrow a query to one website, or leave it whole when $websiteId is
     * null -- which is how an admin acting outside a single site reads across
     * all of them.
     *
     * website_id is a plain column on nearly every table here, so the default
     * covers most repositories. One whose ownership runs through a relation --
     * a product belongs to a website through its service -- overrides this
     * one method rather than re-implementing everything below.
     */
    protected function scopeWebsite(Builder $query, $websiteId): Builder
    {
        return $query->when($websiteId, fn ($q) => $q->where('website_id', $websiteId));
    }

    public function forWebsite($websiteId = null): Builder
    {
        return $this->scopeWebsite($this->query(), $websiteId);
    }

    /* =========================================================
     * Reads
     * ========================================================= */

    public function find($id): ?Model
    {
        return $this->query()->find($id);
    }

    public function findForWebsite($id, $websiteId = null): ?Model
    {
        return $this->forWebsite($websiteId)->where('id', $id)->first();
    }

    public function existsForWebsite($id, $websiteId = null): bool
    {
        return $this->forWebsite($websiteId)->where('id', $id)->exists();
    }

    /* =========================================================
     * Writes
     *
     * No transactions here. A single write is only sometimes the whole of what
     * must succeed together, and the caller is the only one that knows which
     * writes belong to one order -- so a Service opens the transaction and
     * these run inside it.
     * ========================================================= */

    public function create(array $attributes): ?Model
    {
        return ($this->model)::create($attributes);
    }

    public function update(Model $model, array $attributes): bool
    {
        return (bool) $model->update($attributes);
    }

    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }
}
