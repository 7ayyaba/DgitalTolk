<?php

namespace DTApi\Repository;
use Illuminate\Support\Facades\DB;
use DTApi\Models\UserLanguages;
use DTApi\Models\UserMeta;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class UserRepository extends BaseRepository
{
    public function getUserByEmails(array $emails) 
    {
        return DB::table('users')->whereIn('email', $emails)->get();
    }

    public function getUserByEmail (string $email)
    {
        return DB::table('users')->where('email', $email)->first();
    }

    public function getTranslatorJobIdsByEmails(array $emails)
    {
        $users = $this->getUserByEmails($emails);
        if ($users->isNotEmpty()) {
            return DB::table('translator_job_rel')
                ->whereNull('cancel_at')
                ->whereIn('user_id', collect($users)->pluck('id')->all())
                ->lists('job_id');
        }
        return collect();
    }

    public function findUserLanguageByUserId($userId)
    {
        return UserLanguages::where('user_id', $userId);
    }

    public function findUserMetaByUserId($userId)
    {
        return UserMeta::where('user_id', $userId);
    }

}