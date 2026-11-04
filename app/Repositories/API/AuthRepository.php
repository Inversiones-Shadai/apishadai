<?php

namespace App\Repositories\API;

use App\Models\AuthModel;
use App\Repositories\BaseRepository;

class AuthRepository extends BaseRepository
{
    public function __construct(AuthModel $model)
    {
        $this->model = $model;
    }

    #[\Override]
    public function getAllowedMethods(): array
    {
        return [
            'login' => ['post'],
            'logout' => ['post'],
            'register' => ['post'],
            'update' => ['post'],
        ];
    }

    public function model(): string
    {
        return AuthModel::class;
    }
}
