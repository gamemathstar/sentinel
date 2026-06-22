<?php

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A multi-factor authenticator for a user (docs/01 §4.1). The secret is encrypted at
 * rest (field-level, docs/04 §3) and never exposed in serialization.
 */
class MfaFactor extends Model
{
    use HasUuidv7;

    protected $table = 'mfa_factors';

    protected $fillable = ['user_id', 'type', 'secret_enc', 'confirmed'];

    protected $hidden = ['secret_enc'];

    protected $casts = ['confirmed' => 'boolean'];

    /** Transparently encrypt/decrypt the TOTP/WebAuthn secret. */
    protected function secret(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->secret_enc ? Crypt::decryptString($this->secret_enc) : null,
            set: fn (?string $value) => ['secret_enc' => $value ? Crypt::encryptString($value) : null],
        );
    }
}
