<?php

namespace DTApi\Repository;
use DTApi\Models\Job;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class JobRepository extends BaseRepository
{
    public function getTranslatorJobs($userId) 
    {
        return Job::getTranslatorJobs($userId, 'new')->pluck('jobs')->all();
    }

    public function checkParticularJob($userId, $jobItem)
    {
        return Job::checkParticularJob($userId, $jobItem);
    }

    public function getTranslatorJobsHistoric($userId, $pageNum)
    {
        return Job::getTranslatorJobsHistoric($userId, 'historic', $pageNum);

    }

    public function getJobsAssignedTranslatorDetail($job)
    {
        return Job::getJobsAssignedTranslatorDetail($job);

    }

    public function insertTranslatorJobRel($userId, $jobId)
    {
        return Job::insertTranslatorJobRel($userId, $jobId);

    }

    public function isTranslatorAlreadyBooked($userId, $jobId, $jobDue)
    {
        return Job::isTranslatorAlreadyBooked($jobId, $userId, $jobDue);
    }

    public function deleteTranslatorJobRel($translatorId, $jobId)
    {
        return Job::deleteTranslatorJobRel($translatorId, $jobId);
    }

    public function getJobs($userId, $jobType, $status, $userLanguage, $gender, $translatorLevel)
    {
        return Job::getJobs($userId, $jobType, $status, $userLanguage, $gender, $translatorLevel);
    }

    public function assignedToPaticularTranslator($userId, $jobId)
    {
        return Job::assignedToPaticularTranslator($userId, $jobId);
    }

    public function checkTowns($jobUserId, $userId)
    {
        return Job::checkTowns($jobUserId, $userId);
    }

    public function findJobWithRelations($relations = [], $jobId)
    {
        return Job::with($relations)->find($jobId);
    }


}
