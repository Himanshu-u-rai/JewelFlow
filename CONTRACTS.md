# Typed Contracts (Laravel ↔ Mobile)

The mobile Expo client (`jewelflowMobileApp`) consumes the same JSON
shapes as the Laravel web SaaS. To prevent type drift between the two
codebases, we define every mobile-facing payload as a
[`Spatie\LaravelData\Data`](https://spatie.be/docs/laravel-data) DTO
under `app/Data/**` and auto-generate matching TypeScript type
declarations into the mobile repo with
[`spatie/laravel-typescript-transformer`](https://github.com/spatie/laravel-typescript-transformer).

## What the pipeline is for

- Single source of truth for the shape of every mobile API response
- Compile-time safety on the mobile side — if the Laravel DTO changes,
  the mobile `tsc` build breaks until the consumer is updated
- Self-documenting API: the DTO class is the contract

## Output path

Generated file (tracked in the mobile repo, committed as-is):

```
/home/himanshu/Desktop/jewelflowMobileApp/src/types/generated/laravel.d.ts
```

Types are emitted under the `App.Data.**` global namespace, mirroring
the PHP namespace. Example:

```ts
App.Data.Mobile.BootstrapData
```

## Adding a new DTO

1. Create `app/Data/<Area>/<Name>Data.php` extending
   `Spatie\LaravelData\Data`.
2. Annotate the class with
   `#[\Spatie\TypeScriptTransformer\Attributes\TypeScript]`.
3. Use typed constructor-promoted properties only (no untyped
   `public $foo`).
4. From the Laravel repo root run:

   ```
   php artisan typescript:transform
   ```

5. Verify the new type appears in
   `src/types/generated/laravel.d.ts` in the mobile repo.
6. Commit the regenerated `laravel.d.ts` alongside the PHP change in
   the mobile repo so both sides stay in lockstep.

## Workflow rules

- Never hand-edit the generated file in the mobile repo.
- Never commit a DTO change without also regenerating and committing
  the updated `.d.ts`.
- CI (future) should run `php artisan typescript:transform` and fail
  the build if the generated output differs from what was committed.
