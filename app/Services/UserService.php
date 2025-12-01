<?php

namespace App\Services;

use App\Repositories\BaseRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class UserService extends BaseService
{
    /**
     * @var UserRepository
     */
    protected BaseRepository $repository;

    /**
     * UserService constructor.
     *
     * @param UserRepository $repository
     */
    public function __construct(UserRepository $repository)
    {
        $this->repository = $repository;
        parent::__construct($repository);
    }

    /**
     * Find user by email.
     *
     * @param string $email
     * @return Model|null
     */
    public function findByEmail(string $email): ?Model
    {
        return $this->repository->findByEmail($email);
    }

    /**
     * Create a new user with hashed password.
     *
     * @param array $data
     * @return Model
     */
    public function createWithPassword(array $data): Model
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return $this->create($data);
    }

    /**
     * Update user password.
     *
     * @param int $userId
     * @param string $password
     * @return bool
     */
    public function updatePassword(int $userId, string $password): bool
    {
        return $this->update($userId, [
            'password' => Hash::make($password)
        ]);
    }

    /**
     * Verify user password.
     *
     * @param string $email
     * @param string $password
     * @return bool
     */
    public function verifyPassword(string $email, string $password): bool
    {
        $user = $this->findByEmail($email);

        if (!$user) {
            return false;
        }

        return Hash::check($password, $user->password);
    }
}
