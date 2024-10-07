<?php

namespace DTApi\Repository;
use DTApi\Models\Translator;

/**
 * Class TranslatorRepository
 * @package DTApi\Repository
 */
class TranslatorRepository extends BaseRepository
{
    public function createTranslator ($userId, $jobId)
    {
        return Translator::create(['user_id' => $userId, 'job_id' => $jobId]);
    }

    public function updateTranslator ($jobId, $data)
    {
        Translator::where('job_id', $jobId)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);
    }
}