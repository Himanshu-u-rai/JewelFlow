<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerProfilePhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CustomerProfilePhotoService
{
    public function __construct(private CustomerProfilePhotoEncoder $encoder) {}

    /** A null file removes the photo; validated form attributes are optional. */
    public function save(Customer $customer, ?UploadedFile $file, string $expectedVersion, array $attributes = []): ?CustomerProfilePhoto
    {
        $encoded = $file ? $this->encoder->encode($file) : null;
        $candidate = null;
        try {
            return DB::transaction(function () use ($customer, $encoded, $expectedVersion, $attributes, &$candidate) {
                $existing = $customer->exists;
                $locked = $existing
                    ? Customer::withoutTenant()->where('shop_id', $customer->shop_id)->lockForUpdate()->findOrFail($customer->id)
                    : $customer;
                if (! $locked->exists) {
                    $locked->fill($attributes)->save();
                }
                $photo = CustomerProfilePhoto::withoutTenant()->where('shop_id', $locked->shop_id)->where('customer_id', $locked->id)->first();
                abort_unless(($photo?->version ?? '') === $expectedVersion, 409, 'This photo changed in another tab. Refresh before changing it again.');
                $old = $photo?->file_path;
                if ($encoded) {
                    $candidate = 'customer-profile-photos/'.$locked->shop_id.'/'.$locked->id.'/'.Str::uuid().'.webp';
                    if (! Storage::disk('local')->put($candidate, $encoded['bytes'], ['visibility' => 'private'])) {
                        throw new RuntimeException('Private photo storage failed.');
                    }
                    $photo ??= new CustomerProfilePhoto;
                    $photo->fill([
                        'shop_id' => $locked->shop_id, 'customer_id' => $locked->id,
                        'file_path' => $candidate, 'version' => (string) Str::uuid(),
                        'width' => $encoded['width'], 'height' => $encoded['height'],
                        'size_bytes' => strlen($encoded['bytes']),
                    ])->save();
                } else {
                    $photo?->delete();
                    $photo = null;
                }
                if ($existing && $attributes !== []) {
                    $locked->fill($attributes)->save();
                }
                if ($old) {
                    DB::afterCommit(fn () => $this->cleanup($old));
                }

                return $photo;
            });
        } catch (Throwable $e) {
            if ($candidate) {
                $this->cleanup($candidate);
            }
            if ($e instanceof ValidationException || $e instanceof HttpExceptionInterface) {
                throw $e;
            }
            Log::warning('Customer profile photo was not saved.', ['customer_id' => $customer->id]);
            throw ValidationException::withMessages([
                'customer_photo' => 'The photo save could not be confirmed. Refresh to check the current photo before retrying.',
            ]);
        }
    }

    /** Only generated, unreferenced feature files may be removed; failures stay for pruning. */
    public function cleanup(string $path, bool $dryRun = false): bool
    {
        if (! preg_match('~^customer-profile-photos/([1-9][0-9]*)/([1-9][0-9]*)/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.webp$~D', $path, $parts)) {
            return false;
        }
        try {
            return DB::transaction(function () use ($path, $parts, $dryRun) {
                Customer::withoutTenant()->where('shop_id', $parts[1])->whereKey($parts[2])->lockForUpdate()->first();
                if (CustomerProfilePhoto::withoutTenant()->where('file_path', $path)->exists()) {
                    return false;
                }

                return $dryRun || Storage::disk('local')->delete($path);
            });
        } catch (Throwable $e) {
            // An uncertain commit or unavailable database must never delete the selected file.
            Log::warning('Customer profile photo cleanup deferred.');

            return false;
        }
    }
}
