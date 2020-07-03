<?php

namespace Pterodactyl\Services\Users;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Pterodactyl\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Contracts\Encryption\Encrypter;
use Pterodactyl\Contracts\Repository\UserRepositoryInterface;
use Pterodactyl\Repositories\Eloquent\RecoveryTokenRepository;
use Pterodactyl\Exceptions\Service\User\TwoFactorAuthenticationTokenInvalid;

class ToggleTwoFactorService
{
    /**
     * @var \Illuminate\Contracts\Encryption\Encrypter
     */
    private $encrypter;

    /**
     * @var \PragmaRX\Google2FA\Google2FA
     */
    private $google2FA;

    /**
     * @var \Pterodactyl\Contracts\Repository\UserRepositoryInterface
     */
    private $repository;

    /**
     * @var \Pterodactyl\Repositories\Eloquent\RecoveryTokenRepository
     */
    private $recoveryTokenRepository;

    /**
     * ToggleTwoFactorService constructor.
     *
     * @param \Illuminate\Contracts\Encryption\Encrypter $encrypter
     * @param \PragmaRX\Google2FA\Google2FA $google2FA
     * @param \Pterodactyl\Repositories\Eloquent\RecoveryTokenRepository $recoveryTokenRepository
     * @param \Pterodactyl\Contracts\Repository\UserRepositoryInterface $repository
     */
    public function __construct(
        Encrypter $encrypter,
        Google2FA $google2FA,
        RecoveryTokenRepository $recoveryTokenRepository,
        UserRepositoryInterface $repository
    ) {
        $this->encrypter = $encrypter;
        $this->google2FA = $google2FA;
        $this->repository = $repository;
        $this->recoveryTokenRepository = $recoveryTokenRepository;
    }

    /**
     * Toggle 2FA on an account only if the token provided is valid.
     *
     * @param \Pterodactyl\Models\User $user
     * @param string $token
     * @param bool|null $toggleState
     * @return string[]
     *
     * @throws \PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException
     * @throws \PragmaRX\Google2FA\Exceptions\InvalidCharactersException
     * @throws \PragmaRX\Google2FA\Exceptions\SecretKeyTooShortException
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     * @throws \Pterodactyl\Exceptions\Service\User\TwoFactorAuthenticationTokenInvalid
     */
    public function handle(User $user, string $token, bool $toggleState = null): array
    {
        $secret = $this->encrypter->decrypt($user->totp_secret);

        $isValidToken = $this->google2FA->verifyKey($secret, $token, config()->get('pterodactyl.auth.2fa.window'));

        if (! $isValidToken) {
            throw new TwoFactorAuthenticationTokenInvalid('The token provided is not valid.');
        }

        // Now that we're enabling 2FA on the account, generate 10 recovery tokens for the account
        // and store them hashed in the database. We'll return them to the caller so that the user
        // can see and save them.
        //
        // If a user is unable to login with a 2FA token they can provide one of these backup codes
        // which will then be marked as deleted from the database and will also bypass 2FA protections
        // on their account.
        $tokens = [];
        if ((! $toggleState && ! $user->use_totp) || $toggleState) {
            $inserts = [];
            for ($i = 0; $i < 10; $i++) {
                $token = Str::random(10);

                $inserts[] = [
                    'user_id' => $user->id,
                    'token' => password_hash($token, PASSWORD_DEFAULT),
                ];

                $tokens[] = $token;
            }

            // Bulk insert the hashed tokens.
            $this->recoveryTokenRepository->insert($inserts);
        } elseif ($toggleState === false || $user->use_totp) {
            // If we are disabling 2FA on this account we will delete all of the recovery codes
            // that exist in the database for this account.
            $this->recoveryTokenRepository->deleteWhere(['user_id' => $user->id]);
        }

        $this->repository->withoutFreshModel()->update($user->id, [
            'totp_authenticated_at' => Carbon::now(),
            'use_totp' => (is_null($toggleState) ? ! $user->use_totp : $toggleState),
        ]);

        return $tokens;
    }
}
