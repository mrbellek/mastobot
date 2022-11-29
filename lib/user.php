<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Mastobot\Lib\Base;

class User extends Base
{
    public function get(string $username)
    {
        throw new RuntimeException('User::get not supported by Mastodon');
        //return $this->oTwitter->get('users/show', ['screen_name' => $username]);
    }

    private function getById($id)
    {
        throw new RuntimeException('User::getById not supported by Mastodon');
        //return $this->oTwitter->get('users/show', ['user_id' => $id]);
    }
}