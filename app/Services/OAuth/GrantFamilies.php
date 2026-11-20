<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\Models\Client;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Bridge\AccessToken;
use Laravel\Passport\DeviceCode;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use League\OAuth2\Server\Exception\OAuthServerException;
use LogicException;
use SensitiveParameter;
use stdClass;

final class GrantFamilies
{
    public function connection(): Connection
    {
        if (Passport::tokenModel() !== Token::class || Passport::refreshTokenModel() !== RefreshToken::class
            || ! Passport::$revokeRefreshTokenAfterUse) {
            throw new LogicException('Grant families require the built-in Passport token models and refresh token rotation.');
        }

        if (Passport::clientModel() !== Client::class || Passport::authCodeModel() !== AuthCode::class
            || Passport::deviceCodeModel() !== DeviceCode::class || Passport::$accessTokenEntity !== AccessToken::class) {
            throw new LogicException('Grant families require the supported Passport client, code models and access token entity.');
        }

        $connection = DB::connection(config('passport.connection'));
        if (Passport::token()->getConnection() !== $connection
            || Passport::refreshToken()->getConnection() !== $connection
            || Passport::client()->getConnection() !== $connection) {
            throw new LogicException('Passport token, refresh token, and client models must use the Passport connection.');
        }

        return $connection;
    }

    /** Call inside a transaction. Mapped families are always locked before their access tokens. */
    public function lockForToken(string $accessTokenId): stdClass
    {
        $db = $this->connection();
        $member = $db->table('oauth_grant_family_tokens')->where('access_token_id', $accessTokenId)->first();
        if ($member !== null) {
            $family = $db->table('oauth_grant_families')->where('id', $member->family_id)->lockForUpdate()->first();
            if ($family === null || $family->revoked_at !== null) {
                throw OAuthServerException::invalidRefreshToken('The grant family is revoked.');
            }

            return $family;
        }

        $token = $db->table('oauth_access_tokens')->where('id', $accessTokenId)->lockForUpdate()->first();
        if ($token === null || $token->user_id === null || $token->revoked) {
            throw OAuthServerException::invalidRefreshToken('The grant is no longer active.');
        }
        // A concurrent legacy refresh may have initialized the family while we waited.
        // Reject this request rather than taking a family lock after an access-token lock.
        if ($db->table('oauth_grant_family_tokens')->where('access_token_id', $accessTokenId)->exists()) {
            throw OAuthServerException::invalidRefreshToken('The grant has already been rotated.');
        }

        $id = (string) Str::uuid();
        $db->table('oauth_grant_families')->insert([
            'id' => $id,
            'client_id' => $token->client_id,
            'user_id' => $token->user_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $db->table('oauth_grant_family_tokens')->insert(['access_token_id' => $accessTokenId, 'family_id' => $id]);

        return $db->table('oauth_grant_families')->where('id', $id)->first();
    }

    public function attach(string $familyId, string $accessTokenId): void
    {
        $this->connection()->table('oauth_grant_family_tokens')->insert([
            'access_token_id' => $accessTokenId,
            'family_id' => $familyId,
        ]);
    }

    public function issueCredential(string $accessTokenId): ?string
    {
        return $this->connection()->transaction(function () use ($accessTokenId): ?string {
            $family = $this->lockForToken($accessTokenId);
            if ($family->revocation_hash !== null) {
                return null;
            }

            $credential = 'kg1.'.$family->id.'.'.bin2hex(random_bytes(32));
            $this->connection()->table('oauth_grant_families')->where('id', $family->id)->update([
                'revocation_hash' => hash('sha256', $credential),
                'updated_at' => now(),
            ]);

            return $credential;
        });
    }

    public function revokeCredential(#[SensitiveParameter] string $credential): bool
    {
        if (! preg_match('/\Akg1\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.[0-9a-f]{64}\z/', $credential, $matches)) {
            return false;
        }

        return $this->connection()->transaction(function () use ($credential, $matches): bool {
            $family = $this->connection()->table('oauth_grant_families')->where('id', $matches[1])->lockForUpdate()->first();
            if ($family === null || ! is_string($family->revocation_hash)
                || ! hash_equals($family->revocation_hash, hash('sha256', $credential))) {
                return false;
            }
            $this->revokeLockedFamily($family->id);

            return true;
        });
    }

    /** Call with the family row already locked. */
    public function revokeLockedFamily(string $familyId): void
    {
        $db = $this->connection();
        $ids = $db->table('oauth_grant_family_tokens')->where('family_id', $familyId)->pluck('access_token_id');
        $db->table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true]);
        $db->table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
        $db->table('oauth_grant_families')->where('id', $familyId)->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
