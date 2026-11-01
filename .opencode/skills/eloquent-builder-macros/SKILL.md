---
name: eloquent-builder-macros
description: How to silence LSP false-positive "method does not exist" errors on runtime-registered Eloquent builder macros (SoftDeletes, custom macros) by declaring them in the model's class docblock. Use whenever you add SoftDeletes to a model or register a builder macro.
---

# Eloquent Builder Macros — Docblock Annotations

## The problem

Methods like `withTrashed()`, `withoutTrashed()`, `onlyTrashed()` are registered at runtime by the `SoftDeletes` trait: `Illuminate\Database\Eloquent\SoftDeletingScope::extend()` calls `$builder->macro(...)` on the query builder. They are dispatched through `Builder::__call` and are invisible to static analysis, so the LSP reports:

```
Method "withTrashed" does not exist on class "Illuminate\Database\Eloquent\Builder<Model>"
```

This is a **false positive** — the code works fine at runtime. Do not "fix" it by changing the call, adding `/** @phpstan-ignore */`-style suppressions everywhere, or rewriting queries.

The same applies to any custom macro registered via `Builder::macro()` or to builders returned by a custom `newEloquentBuilder()`.

## The rule

Any model that uses `SoftDeletes` — or whose Eloquent builder receives custom macros — MUST declare those macros in its class-level docblock with `@method static Builder<static> ...` lines, so both static calls (`Model::withTrashed()`) and calls inside scopes on `Builder<Model>` resolve in the LSP.

Concrete example from this repo, `App\Models\KnowledgePage`:

```php
/**
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> withoutTrashed()
 * @method static Builder<static> onlyTrashed()
 */
class KnowledgePage extends BaseModel
{
    use SoftDeletes;
```

## Counter-examples — no annotation needed

- `Route::withTrashed()` in `routes/web.php` is a real method on the route definition (`Illuminate\Routing\Route`), not a builder macro.
- `BelongsToWorkspace::withoutWorkspaceScope()` is a real static helper declared on the trait itself.

Only annotate methods that exist solely at runtime via `Builder::__call`.

## Checklist

Whenever you add `SoftDeletes` to a model or register a builder macro:

1. Identify the macros (SoftDeletes: `withTrashed`, `withoutTrashed`, `onlyTrashed`; plus any custom `macro()` calls).
2. Add `@method static Builder<static> ...` lines to the model's class docblock.
3. Confirm `use Illuminate\Database\Eloquent\Builder;` is imported in that file.
4. Run `vendor/bin/pint --dirty --format agent` on the file.

## Notes

- This is a docblock-only change: zero runtime impact.
- If LSP errors persist after editing, reload/restart the language server.
