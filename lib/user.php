<?php
declare(strict_types=1);

namespace Mastobot\Lib;

use Mastobot\Lib\Base;

class User extends Base
{
    public function get($sUsername)
    {
        return $this->oTwitter->get('users/show', ['screen_name' => $sUsername]);
    }

    private function getById($id)
    {
        return $this->oTwitter->get('users/show', ['user_id' => $id]);
    }
}